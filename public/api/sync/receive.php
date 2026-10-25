<?php
// public/api/sync/receive.php — the website end of the shop-PC sync
// (app/helpers/DesktopSync.php). The shop PC sends one table's new/changed
// rows and deleted keys at a time; they're written here as-is, so the online
// POS shows what was done on the shop PC.
//
// No login: the PC proves itself with the sync key that was put in its zip
// (download/my-shop.php). Only the key's own shop can be written: rows of
// another shop are skipped, and billing/account fields are never taken.
require_once __DIR__ . '/../../../app/app.php';
require_once APP_ROOT . '/models/SettingModel.php'; // global class, not autoloaded

header('Content-Type: application/json');
header('Cache-Control: no-store');
$fail = static function (int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail(405, 'POST only');
}
$pdo = Database::pdo();
$key = json_decode((string) (new SettingModel($pdo))->get('desktop_sync_key'), true);
$token = (string) ($_SERVER['HTTP_X_SYNC_TOKEN'] ?? '');
if (!is_array($key) || $token === '' || !hash_equals((string) ($key['hash'] ?? ''), hash('sha256', $token))) {
    $fail(403, 'This shop computer\'s sync key isn\'t valid any more. Download the Windows version again from the website.');
}
$tid = (int) $key['tenant_id'];

$in = json_decode((string) file_get_contents('php://input'), true);
$table = (string) ($in['table'] ?? '');
$pk = (array) ($in['pk'] ?? []);
if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !$pk) {
    $fail(400, 'bad request');
}
// Never written from a shop PC: bookkeeping, site-wide settings, billing.
$blocked = ['request_keys', 'login_attempts', 'login_otps', 'schema_migrations', 'sync_events', 'staff_otp_verification',
    'site_settings', 'subscriptions', 'subscription_plans', 'subscription_stk', 'roles'];
$protectedCols = ['tenants' => ['status', 'slug', 'enabled_modules']];
if (in_array($table, $blocked, true)) {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

$isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
try {
    $cols = $isSqlite
        ? array_column($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
} catch (Throwable $e) {
    $cols = [];
}
if (!$cols) {
    // A table the website doesn't have (yet): nothing to write it into.
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}
if (array_diff($pk, $cols)) {
    $fail(400, 'unknown key columns for ' . $table);
}

$q = static fn(string $c): string => $isSqlite ? '"' . $c . '"' : '`' . $c . '`';
$pkWhere = implode(' AND ', array_map(fn($c) => $q($c) . ' = ?', $pk));
// Which shop a row belongs to (null = the table isn't per-shop).
$shopCol = $table === 'tenants' ? 'id' : (in_array('tenant_id', $cols, true) ? 'tenant_id' : null);
$ownerOf = $shopCol ? $pdo->prepare('SELECT ' . $q($shopCol) . ' FROM ' . $q($table) . ' WHERE ' . $pkWhere) : null;
$mine = static function (array $keyVals) use ($ownerOf, $tid): bool {
    if (!$ownerOf) {
        return true;
    }
    $ownerOf->execute($keyVals);
    $owner = $ownerOf->fetchColumn();
    return $owner === false || (int) $owner === $tid;
};

$written = 0;
$skipped = 0;
try {
    if (!$isSqlite) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    }
    $pdo->beginTransaction();
    $stmts = [];
    foreach ((array) ($in['rows'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row = array_intersect_key($row, array_flip($cols));
        $keyVals = array_map(fn($c) => $row[$c] ?? null, $pk);
        if (in_array(null, $keyVals, true) || ($shopCol && (int) ($row[$shopCol] ?? 0) !== $tid) || !$mine($keyVals)) {
            $skipped++;
            continue;
        }
        $names = array_keys($row);
        $sig = implode(',', $names);
        if (!isset($stmts[$sig])) {
            $place = implode(',', array_fill(0, count($names), '?'));
            $list = implode(',', array_map($q, $names));
            $set = array_diff($names, $pk, $protectedCols[$table] ?? []);
            if ($isSqlite) {
                $sql = 'INSERT INTO ' . $q($table) . ' (' . $list . ') VALUES (' . $place . ') ON CONFLICT (' . implode(',', array_map($q, $pk)) . ') '
                    . ($set ? 'DO UPDATE SET ' . implode(',', array_map(fn($c) => $q($c) . ' = excluded.' . $q($c), $set)) : 'DO NOTHING');
            } else {
                $sql = 'INSERT INTO ' . $q($table) . ' (' . $list . ') VALUES (' . $place . ') ON DUPLICATE KEY UPDATE '
                    . ($set ? implode(',', array_map(fn($c) => $q($c) . ' = VALUES(' . $q($c) . ')', $set)) : $q($pk[0]) . ' = ' . $q($pk[0]));
            }
            $stmts[$sig] = $pdo->prepare($sql);
        }
        $stmts[$sig]->execute(array_values($row));
        $written++;
    }
    $delete = $pdo->prepare('DELETE FROM ' . $q($table) . ' WHERE ' . $pkWhere);
    foreach ((array) ($in['deleted'] ?? []) as $keyVals) {
        if (!is_array($keyVals) || count($keyVals) !== count($pk) || $table === 'tenants' || !$mine(array_values($keyVals))) {
            $skipped++;
            continue;
        }
        $delete->execute(array_values($keyVals));
        $written++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Shop-PC sync (' . $table . '): ' . $e->getMessage());
    $fail(500, $e->getMessage());
} finally {
    if (!$isSqlite) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
echo json_encode(['ok' => true, 'written' => $written, 'skipped' => $skipped]);

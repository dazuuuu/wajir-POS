<?php
// app/helpers/Idempotency.php
// "Record it once, however many times it arrives."
//
// The offline queue (assets/js/offline-orders.js) puts a one-off key in
// every form it sends (_idem). A sale can arrive more than once: the till
// gave up waiting after a few seconds but the server did finish it, the
// answer got lost on a bad connection, or the cashier double-tapped. The
// first arrival is processed normally; when it ends in a redirect (every
// success in this app does), the redirect is remembered against the key.
// Any later arrival with the same key gets that same redirect straight
// away and is NOT processed again.
//
// While the first one is still being processed, a repeat gets 503 ("try
// again shortly"), which the queue treats as "retry later".

class Idempotency
{
    private static ?string $key = null;

    public static function guard(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return;
        }
        $key = (string) ($_POST['_idem'] ?? '');
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $key)) {
            return;
        }
        try {
            $db = Database::pdo();
            self::ensureTable($db);
            $st = $db->prepare('SELECT location FROM request_keys WHERE idem = ?');
            $st->execute([$key]);
            $row = $st->fetch();
            if ($row) {
                if ($row['location'] !== null && $row['location'] !== '') {
                    header('Location: ' . $row['location'], true, 303);
                    exit;
                }
                http_response_code(503);
                header('Retry-After: 5');
                exit('This request is still being processed.');
            }
            // Claim the key; if another copy claimed it a moment ago, wait.
            try {
                $db->prepare('INSERT INTO request_keys (idem, location, created_at) VALUES (?, NULL, ?)')
                    ->execute([$key, date('Y-m-d H:i:s')]);
            } catch (PDOException $e) {
                http_response_code(503);
                header('Retry-After: 5');
                exit('This request is still being processed.');
            }
            self::$key = $key;
            register_shutdown_function([self::class, 'finish']);
            if (mt_rand(1, 200) === 1) {
                $db->prepare('DELETE FROM request_keys WHERE created_at < ?')->execute([date('Y-m-d H:i:s', strtotime('-60 days'))]);
            }
        } catch (Throwable $e) {
            // Never block a sale because this bookkeeping failed.
            error_log('Idempotency guard: ' . $e->getMessage());
            self::$key = null;
        }
    }

    /** After the page ran: remember its redirect, or free the key if it didn't succeed. */
    public static function finish(): void
    {
        if (self::$key === null) {
            return;
        }
        try {
            $location = self::redirectLocation();
            $db = Database::pdo();
            if ($location !== null) {
                $db->prepare('UPDATE request_keys SET location = ? WHERE idem = ?')->execute([$location, self::$key]);
            } else {
                // A validation error or a crash: nothing was saved, so let
                // a later try with the same key run normally.
                $db->prepare('DELETE FROM request_keys WHERE idem = ?')->execute([self::$key]);
            }
        } catch (Throwable $e) {
            error_log('Idempotency finish: ' . $e->getMessage());
        }
    }

    /** The Location this response is redirecting to, if it is a redirect. */
    private static function redirectLocation(): ?string
    {
        $code = http_response_code();
        foreach (array_reverse(headers_list()) as $h) {
            if (stripos($h, 'Location:') === 0) {
                if ($code !== false && ($code < 300 || $code >= 400)) {
                    return null;
                }
                return trim(substr($h, 9));
            }
        }
        // Tests can't see headers from the command line; they set this.
        return $GLOBALS['__idem_test_location'] ?? null;
    }

    private static function ensureTable(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db->query('SELECT 1 FROM request_keys LIMIT 1');
        } catch (PDOException $e) {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $db->exec('CREATE TABLE IF NOT EXISTS request_keys (idem TEXT PRIMARY KEY, location TEXT NULL, created_at DATETIME NOT NULL)');
            } else {
                $db->exec('CREATE TABLE IF NOT EXISTS request_keys (
                    idem VARCHAR(64) NOT NULL PRIMARY KEY, location TEXT NULL, created_at DATETIME NOT NULL,
                    KEY idx_request_keys_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
            }
        }
        $done = true;
    }
}

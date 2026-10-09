<?php
// app/helpers/OfflineWarm.php
// The POS pages the service worker downloads right after login, so the POS
// works offline even for screens that were never opened (see sw.js,
// WARM_CACHE). One list, used by pwa_head.php on every page and by the
// /support/ console's "Download offline resources" button.

class OfflineWarm
{
    /** How many recent receipts / open invoices to keep available offline. */
    private const RECENT = 15;

    /** Pages that must stay online-only (changing them needs the server there and then). */
    private const NEVER = ['super/updates/', 'super/revenues/', 'super/data/', 'super/profile/'];

    /**
     * Every screen of the app for this role, downloaded once a day so the
     * whole POS opens offline — not just the till. Built from the folders
     * that exist, so new screens are included automatically.
     */
    private static function dailyPaths(bool $isStaff): array
    {
        $prefix = $isStaff ? 'staff/' : 'super/';
        $paths = [];
        foreach (glob(PUBLIC_ROOT . '/' . $prefix . '*/index.php') ?: [] as $file) {
            $paths[] = $prefix . basename(dirname($file)) . '/';
        }
        $extra = $isStaff
            ? ['staff/orders/new.php', 'staff/orders/held.php', 'staff/stock/new.php']
            : ['super/stock/new.php', 'super/stationery/new.php', 'super/purchases/new.php', 'super/purchases/track.php',
               'super/services/new.php', 'super/services/sell.php', 'super/services/appointments.php',
               'super/staff/attendance.php', 'super/inventory/low-stock.php', 'super/reports/inventory.php',
               'staff/orders/held.php'];
        foreach ($extra as $p) {
            if (is_file(PUBLIC_ROOT . '/' . $p)) {
                $paths[] = $p;
            }
        }
        return array_values(array_diff(array_unique($paths), self::NEVER));
    }

    /** @return array{home: ?string, urls: string[], daily: string[], fp: ?string} empty when logged out */
    public static function forSession(): array
    {
        if (empty($_SESSION['logged_in']) || empty($_SESSION['otp_verified'])) {
            return ['home' => null, 'urls' => [], 'daily' => [], 'fp' => null];
        }
        $isStaff = ($_SESSION['role'] ?? '') === 'staff';
        if ($isStaff) {
            $home = 'staff/dashboard/';
            $paths = ['staff/dashboard/', 'staff/orders/new.php', 'staff/orders/', 'staff/sales/', 'staff/payments/', 'staff/returns/'];
        } else {
            // Owner: the till lives at super/shop/ (it renders the staff till).
            $home = 'super/shop/';
            $paths = ['super/shop/', 'super/dashboard/', 'super/orders/new.php', 'super/orders/', 'super/sales/', 'super/payments/', 'super/inventory/', 'super/customers/', 'super/reports/',
                // Money and stock screens, so they open offline too.
                'super/tracker/', 'super/reports/inventory.php', 'super/expenses/', 'super/finances/',
                'super/purchases/', 'super/store/', 'super/returns/', 'super/invoices/', 'super/suppliers/'];
        }
        // The offline sales history screen: reads the device's synced copy of
        // every sale, so it's the way to see past days' receipts offline.
        $paths[] = 'offline/history.php';
        $urls = array_map(static fn(string $p): string => public_url($p), $paths);
        $prefix = $isStaff ? 'staff/' : 'super/';

        $fp = null;
        try {
            $pdo = Database::pdo();
            $tid = (int) TenantContext::tenantId();
            // Recent receipts, and edit pages for invoices still open, so they
            // can be viewed/edited offline too.
            $st = $pdo->prepare('SELECT id, status FROM orders WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . self::RECENT);
            $st->execute([$tid]);
            foreach ($st->fetchAll() as $row) {
                $urls[] = public_url($prefix . 'orders/receipt.php?id=' . (int) $row['id']);
                if (($row['status'] ?? '') === 'open') {
                    $urls[] = public_url($prefix . 'invoices/edit.php?id=' . (int) $row['id']);
                }
            }
            // Which database the saved pages came from. If this changes (e.g.
            // the site moves from a test database to the real one), every
            // saved page is thrown away and re-downloaded — so offline never
            // shows data from a database you're no longer using.
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dbName = $driver === 'mysql' ? (string) $pdo->query('SELECT DATABASE()')->fetchColumn() : 'local';
            $tenant = $pdo->prepare('SELECT name FROM tenants WHERE id = ? LIMIT 1');
            $tenant->execute([$tid]);
            $fp = substr(sha1($driver . '|' . $dbName . '|' . $tid . '|' . (string) $tenant->fetchColumn()), 0, 16);
        } catch (Throwable $ignored) {
        }

        $daily = array_values(array_diff(
            array_map(static fn(string $p): string => public_url($p), self::dailyPaths($isStaff)),
            $urls
        ));
        return ['home' => public_url($home), 'urls' => $urls, 'daily' => $daily, 'fp' => $fp];
    }
}

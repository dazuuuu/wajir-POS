<?php
// app/helpers/ShopExport.php
// The whole shop as JSON, for moving it onto the shop-PC (offline) install.
// Used by api/backup/export.php (sent to the browser) and download/my-shop.php
// (written into the Windows zip, so the shop PC loads it on first start).
//
//   ShopExport::write($fh)   every table's rows + the uploaded files list
//   ShopExport::zip($path)   the same plus the files themselves, in one .zip —
//                            "Export my shop", loaded back by /setup/ (ShopImport)
//   ShopExport::files()      uploaded files under public/ (pictures, logo, documents)

class ShopExport
{
    // Bookkeeping that means nothing on another install.
    private const SKIP = ['request_keys', 'login_attempts', 'login_otps', 'schema_migrations', 'sync_events', 'staff_otp_verification'];

    /**
     * Write the export to $fh, table by table so a big sales history doesn't
     * need it all in memory. $progress(done, total) is called as each table starts.
     */
    public static function write($fh, ?callable $progress = null): void
    {
        $pdo = Database::pdo();
        $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $tables = $isSqlite
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        fwrite($fh, '{"ok":true,"version":1,"exported_at":' . json_encode(date('c')) . ',"tables":{');
        $first = true;
        $total = count($tables);
        foreach ($tables as $i => $table) {
            if ($progress) {
                $progress($i, $total);
            }
            if (in_array($table, self::SKIP, true)) {
                continue;
            }
            fwrite($fh, ($first ? '' : ',') . json_encode($table) . ':[');
            $first = false;
            $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');
            $row1 = true;
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                fwrite($fh, ($row1 ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                $row1 = false;
            }
            fwrite($fh, ']');
            fflush($fh);
        }
        fwrite($fh, '},"files":' . json_encode(self::files()) . '}');
    }

    /**
     * Write the whole shop to a .zip at $path, made to be read back a row at a
     * time (a big sales history is far larger than PHP's memory):
     *   manifest.json        {"ok":true,"format":"shop-zip","version":2,"tables":[...],"files":[...]}
     *   tables/<name>.jsonl  one row per line
     *   files/<path>         every picture and document
     * Returns false if the zip couldn't be made.
     */
    public static function zip(string $path): bool
    {
        $pdo = Database::pdo();
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        $temps = [];
        $tables = [];
        foreach (self::tables($pdo) as $table) {
            $tmp = $temps[] = tempnam(sys_get_temp_dir(), 'shoptbl');
            $fh = fopen($tmp, 'wb');
            $st = $pdo->query('SELECT * FROM `' . str_replace('`', '', $table) . '`');
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            }
            fclose($fh);
            $zip->addFile($tmp, 'tables/' . $table . '.jsonl');
            $tables[] = $table;
        }
        $files = self::files();
        foreach ($files as $rel) {
            $zip->addFile(PUBLIC_ROOT . '/' . $rel, 'files/' . $rel);
            // Pictures are already compressed: store them, don't squeeze again.
            if (!preg_match('/\.(svg|pdf)$/i', $rel)) {
                $zip->setCompressionName('files/' . $rel, ZipArchive::CM_STORE);
            }
        }
        $zip->addFromString('manifest.json', json_encode([
            'ok' => true, 'format' => 'shop-zip', 'version' => 2, 'exported_at' => date('c'),
            'tables' => $tables, 'files' => $files,
        ]));
        $ok = $zip->close(); // reads the temp files, so delete them only now
        foreach ($temps as $t) {
            @unlink($t);
        }
        return $ok;
    }

    /** Every table worth moving (not the per-install bookkeeping). */
    private static function tables(PDO $pdo): array
    {
        $all = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff($all, self::SKIP));
    }

    /** @return string[] paths relative to public/ */
    public static function files(): array
    {
        $files = [];
        foreach (['uploads', 'assets/uploads'] as $dir) {
            $base = PUBLIC_ROOT . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getSize() < 15 * 1024 * 1024 && preg_match('/\.(png|jpe?g|gif|webp|svg|pdf)$/i', $f->getFilename())) {
                    $files[] = $dir . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
                }
            }
        }
        return $files;
    }
}

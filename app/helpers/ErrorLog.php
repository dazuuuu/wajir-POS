<?php
// app/helpers/ErrorLog.php
// Every PHP error lands in app/storage/logs/php-errors.log (one file per
// month), instead of a blank page with nothing to go on. Loaded first by
// app/app.php so even a crash while starting up is recorded.
//
// A crash shows the visitor a short "something went wrong" page with a
// reference code; search the log for that code to find the exact error.
//
// Reading it on Hostinger: hPanel → Files → File Manager → app/storage/logs/.
// The folder is outside the website (and blocked by .htaccess), so visitors
// can never open it.

if (!defined('ERROR_LOG_DIR')) {
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($dir . '/index.html', '');
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = rtrim(sys_get_temp_dir(), '/\\'); // last resort, still better than nothing
    }
    define('ERROR_LOG_DIR', $dir);
    define('ERROR_LOG_FILE', $dir . '/php-errors-' . date('Y-m') . '.log');

    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    ini_set('error_log', ERROR_LOG_FILE);
    ini_set('display_errors', '0'); // never show code or passwords to visitors

    register_shutdown_function(static function (): void {
        $e = error_get_last();
        if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $where = ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? ''));
        error_log("[ref {$ref}] FATAL at {$where}: {$e['message']} in {$e['file']}:{$e['line']}");
        if (PHP_SAPI === 'cli') {
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            @http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Something went wrong</title>'
            . '<div style="font-family:system-ui,sans-serif;max-width:480px;margin:15vh auto;padding:0 16px;text-align:center">'
            . '<h1 style="font-size:1.3rem">Something went wrong</h1>'
            . '<p style="color:#555">This page hit an error. Please try again, or tell support this code:</p>'
            . '<p style="font-size:1.6rem;font-weight:700;letter-spacing:.1em">' . $ref . '</p></div>';
    });
}

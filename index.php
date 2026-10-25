<?php
// index.php (project root) — router into public/ with clean addresses.
//
// Normally the root .htaccess does this and this file only answers "/".
// On a server without mod_rewrite (or with all traffic sent here) it serves
// the whole site: /auth/login.php runs public/auth/login.php, /assets/x.css
// sends public/assets/x.css. Nothing outside public/ is ever served.

$publicDir = __DIR__ . '/public';
$publicReal = realpath($publicDir);

$path = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));
// Address of this project folder when it sits in a sub-folder (e.g. /shop).
$prefix = '';
$docReal = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
$hereReal = realpath(__DIR__);
if ($docReal && $hereReal && strpos($hereReal, $docReal . DIRECTORY_SEPARATOR) === 0) {
    $prefix = str_replace('\\', '/', substr($hereReal, strlen($docReal)));
}
if ($prefix !== '' && strpos($path, $prefix . '/') === 0) {
    $path = substr($path, strlen($prefix));
}
// Old links: /public/x → /x
if ($path === '/public' || strpos($path, '/public/') === 0) {
    $query = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ' . $prefix . (substr($path, 7) ?: '/') . $query, true, 301);
    exit;
}

$target = $publicDir . $path;
if (is_dir($target)) {
    if (substr($path, -1) !== '/') {
        header('Location: ' . $prefix . $path . '/', true, 301);
        exit;
    }
    $target = rtrim($target, '/') . '/index.php';
} elseif (!is_file($target) && is_file($target . '.php')) {
    $target .= '.php'; // /auth/login → auth/login.php
}

$real = realpath($target);
$blocked = !$real || !$publicReal || strpos($real, $publicReal . DIRECTORY_SEPARATOR) !== 0
    || preg_match('#(^|/)\.(?!well-known/)#', $path)
    || preg_match('#^/(uploads|assets/uploads)/.*\.(php\d?|phtml|phar)$#i', $path);
if ($blocked || !is_file($real)) {
    http_response_code($real && !$blocked ? 404 : ($real ? 403 : 404));
    echo 'Not found';
    exit;
}

if (preg_match('/\.php$/i', $real)) {
    // Make the page see itself at its clean address.
    $rel = str_replace('\\', '/', substr($real, strlen($publicReal)));
    $_SERVER['SCRIPT_FILENAME'] = $real;
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $prefix . $rel;
    chdir(dirname($real));
    require $real;
    exit;
}

$types = [
    'css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json',
    'webmanifest' => 'application/manifest+json', 'svg' => 'image/svg+xml', 'png' => 'image/png',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'ico' => 'image/x-icon', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    'pdf' => 'application/pdf', 'zip' => 'application/zip', 'html' => 'text/html; charset=utf-8',
    'txt' => 'text/plain; charset=utf-8', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
];
$ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($real));
if (in_array(basename($real), ['sw.js', 'manifest.webmanifest'], true)) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Service-Worker-Allowed: /');
}
readfile($real);

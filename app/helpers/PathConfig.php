<?php
// app/helpers/PathConfig.php
// Single source of truth for project root and public web base URL.
// This removes the need to hard-code folder names like /Kitale or /2in1.

if (!function_exists('path_config_normalize')) {
    function path_config_normalize(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/'));
    }
}

if (!defined('ROOT_PATH')) {
    $projectRoot = path_config_normalize(dirname(__DIR__, 2));
    define('ROOT_PATH', $projectRoot);
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', ROOT_PATH . '/app');
}

if (!defined('PUBLIC_ROOT')) {
    // The web folder on disk. Normally public/, but hosts like Hostinger serve
    // public_html/ next to app/ — so the files of public/ are uploaded there.
    // A folder only counts if the site's pages are really in it (a stray empty
    // public/ made by an upload must not win).
    $publicRoot = ROOT_PATH . '/public';
    foreach ([ROOT_PATH . '/public', ROOT_PATH . '/public_html', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')] as $dir) {
        if ($dir !== '' && is_file($dir . '/auth/login.php') && is_dir($dir . '/templates')) {
            $publicRoot = path_config_normalize($dir);
            break;
        }
    }
    define('PUBLIC_ROOT', $publicRoot);
}

if (!function_exists('path_config_detect_base')) {
    /**
     * The URL prefix that reaches public/ on this server: '' when the domain
     * points at public/, '/public' when it points at the project folder,
     * '/Kitale/public' on a local XAMPP, and so on.
     *
     * Worked out from the script PHP is running: its real file inside public/
     * (e.g. /auth/login.php) is cut off the end of the URL it was opened by
     * (SCRIPT_NAME), and what is left is the prefix. Real paths are compared,
     * so symlinked or differently-named home folders don't matter.
     *
     * Override: set APP_BASE_URL in the environment (e.g. SetEnv in .htaccess)
     * or define BASE_URL before this file loads.
     */
    function path_config_detect_base(): string
    {
        $env = getenv('APP_BASE_URL');
        if ($env !== false && trim($env) !== '') {
            return rtrim(trim($env), '/');
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $publicReal = realpath(PUBLIC_ROOT);
        $fileReal = $scriptFile !== '' ? realpath($scriptFile) : false;

        if ($scriptName !== '' && $publicReal && $fileReal) {
            $publicReal = path_config_normalize($publicReal);
            $fileReal = path_config_normalize($fileReal);
            if (strpos($fileReal, $publicReal . '/') === 0) {
                $rel = substr($fileReal, strlen($publicReal)); // "/auth/login.php"
                if (substr($scriptName, -strlen($rel)) === $rel) {
                    $base = rtrim(substr($scriptName, 0, -strlen($rel)), '/');
                    // The root .htaccess serves public/ without "/public" in the
                    // address (/auth/login.php runs public/auth/login.php). The
                    // visitor's URL then lacks the prefix, so links must too.
                    $reqPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
                    if ($base !== '' && substr($base, -7) === '/public' && $reqPath !== ''
                        && strpos($reqPath . '/', $base . '/') !== 0) {
                        $base = substr($base, 0, -7);
                    }
                    return $base;
                }
            }
        }

        // Document root is public/ itself (compared as real paths).
        $docReal = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        if ($docReal && $publicReal && path_config_normalize($docReal) === path_config_normalize($publicReal)) {
            return '';
        }
        // Document root above public/: the prefix is the folders in between.
        if ($docReal && $publicReal && strpos(path_config_normalize($publicReal), path_config_normalize($docReal) . '/') === 0) {
            return substr(path_config_normalize($publicReal), strlen(path_config_normalize($docReal)));
        }
        // Last resort: a "/public" in the URL.
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
        $pos = strpos($path, '/public/');
        if ($pos !== false || substr($path, -7) === '/public') {
            return substr($path, 0, ($pos !== false ? $pos : strlen($path) - 7)) . '/public';
        }
        return PHP_SAPI === 'cli' ? '/public' : '';
    }
}

if (!defined('BASE_URL')) {
    define('BASE_URL', path_config_detect_base());
}

if (!function_exists('public_url')) {
    function public_url(string $path = ''): string
    {
        $base = rtrim((string) BASE_URL, '/');
        $path = ltrim($path, '/');
        return $base === '' ? '/' . ($path !== '' ? $path : '') : $base . '/' . $path;
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . public_url($path);
    }
}

if (!function_exists('path_config_rewrite_output')) {
    function path_config_rewrite_output(string $buffer): string
    {
        $base = defined('BASE_URL') ? (string) BASE_URL : '/public';
        $base = rtrim($base, '/');
        $oldPrefix = '/Kitale/public';
        if ($base !== '' && $base !== $oldPrefix) {
            $buffer = str_replace($oldPrefix, $base, $buffer);
        }
        return $buffer;
    }
}

if (!defined('PATH_CONFIG_OUTPUT_BUFFERING')) {
    define('PATH_CONFIG_OUTPUT_BUFFERING', true);
    if (!headers_sent()) {
        ob_start(function (string $buffer): string {
            return path_config_rewrite_output($buffer);
        });
    }
}

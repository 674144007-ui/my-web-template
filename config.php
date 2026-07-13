<?php
/**
 * config.php — Multi-Tenant Edition
 */

if (defined('CONFIG_LOADED')) return;
define('CONFIG_LOADED', true);

require_once __DIR__ . '/env_loader.php';

date_default_timezone_set('Asia/Bangkok');

define('APP_ENV',   env('APP_ENV',   'production'));
define('APP_DEBUG', env('APP_DEBUG', false));

if (APP_ENV === 'development' && APP_DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__ . '/logs/php_error.log');
}

define('BASE_URL', rtrim(env('APP_URL', 'http://localhost/my-web-template-main/'), '/') . '/');

define('UPLOAD_DIR',      __DIR__ . '/uploads/');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);
define('ALLOWED_MIME_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'application/pdf',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
]);

define('SESSION_LIFETIME',      (int) env('SESSION_LIFETIME',      28800)); // 8 ชั่วโมง
define('LOGIN_MAX_ATTEMPTS',    (int) env('LOGIN_MAX_ATTEMPTS',    5));
define('LOGIN_LOCKOUT_MINUTES', (int) env('LOGIN_LOCKOUT_MINUTES', 15));
define('LINE_NOTIFY_TOKEN',     env('LINE_NOTIFY_TOKEN', ''));

_ensureDir(UPLOAD_DIR, true);
_ensureDir(__DIR__ . '/logs/', false);

function _ensureDir(string $dir, bool $blockPhp = false): void {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    if ($blockPhp) {
        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess,
                '<FilesMatch "\.(php|php[3-7]|phtml|sh|exe|pl|cgi)$">' . "\n" .
                "Order allow,deny\nDeny from all\n</FilesMatch>\nOptions -Indexes"
            );
        }
    }
}

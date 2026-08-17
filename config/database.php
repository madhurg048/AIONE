<?php
require_once __DIR__ . '/env.php';

if (!defined('DB_HOST')) define('DB_HOST', env('DB_HOST', '127.0.0.1'));
if (!defined('DB_PORT')) define('DB_PORT', env_int('DB_PORT', 3306));
if (!defined('DB_NAME')) define('DB_NAME', env('DB_NAME', 'super_payg_crm'));
if (!defined('DB_USER')) define('DB_USER', env('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', env('DB_PASS', ''));

/**
 * TLS. Azure Database for MySQL — Flexible Server requires an encrypted
 * connection, so DB_SSL must be true there. A plain MySQL VM usually does not,
 * so it defaults off. DB_SSL_CA points at a PEM CA bundle; leave it empty and
 * set DB_SSL_VERIFY=false to encrypt without verifying the server certificate.
 */
if (!defined('DB_SSL'))        define('DB_SSL', env_bool('DB_SSL', false));
if (!defined('DB_SSL_CA'))     define('DB_SSL_CA', env('DB_SSL_CA', ''));
if (!defined('DB_SSL_VERIFY')) define('DB_SSL_VERIFY', env_bool('DB_SSL_VERIFY', true));

if (!function_exists('get_app_timezone')) {
  function get_app_timezone() {
    return env('APP_TIMEZONE', 'Asia/Kolkata');
  }
}
if (!function_exists('get_app_timezone_offset')) {
  function get_app_timezone_offset() {
    $tz = env('APP_TIMEZONE_OFFSET');
    if ($tz) return $tz;
    try {
      $dt = new DateTime('now', new DateTimeZone(get_app_timezone()));
      return $dt->format('P');
    } catch (Exception $e) { return '+05:30'; }
  }
}

if (!function_exists('getDB')) {
  function getDB(){
    static $db = null;
    if ($db !== null) return $db;

    try {
      date_default_timezone_set(get_app_timezone());

      $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
      $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => env_int('DB_TIMEOUT', 10),
      ];

      if (DB_SSL) {
        // Without a CA file PDO still negotiates TLS, but cannot verify the peer.
        if (DB_SSL_CA !== '' && is_readable(DB_SSL_CA)) {
          $opts[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
        }
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
          $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) DB_SSL_VERIFY;
        }
      }

      $db = new PDO($dsn, DB_USER, DB_PASS, $opts);

      try { $db->exec('SET time_zone=' . $db->quote(get_app_timezone_offset())); }
      catch (PDOException $e) { error_log('DB time_zone: ' . $e->getMessage()); }

      return $db;
    } catch (PDOException $e) {
      error_log('DB connect failed (' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME . '): ' . $e->getMessage());
      return null;
    }
  }
}

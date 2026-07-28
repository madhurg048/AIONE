<?php
// Credentials come from the environment (Azure App Service → Application Settings, or a .env
// file in the project root — see .env.example). Nothing secret is hardcoded here.
require_once __DIR__ . '/app.php';

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'super_payg');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: '');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
if (!function_exists('getDB')) {
  function getDB(){ static $db=null; if($db===null){ try{
    if (DB_USER === '') { error_log('DB credentials missing: set DB_USER/DB_PASS (env or .env)'); return null; }
    if (!function_exists('get_app_timezone')) {
      function get_app_timezone() {
        return getenv('APP_TIMEZONE') ?: 'Asia/Kolkata';
      }
    }
    if (!function_exists('get_app_timezone_offset')) {
      function get_app_timezone_offset() {
        $tz = getenv('APP_TIMEZONE_OFFSET');
        if ($tz) return $tz;
        try {
          $dt = new DateTime('now', new DateTimeZone(get_app_timezone()));
          return $dt->format('P');
        } catch (Exception $e) { return '+05:30'; }
      }
    }
    date_default_timezone_set(get_app_timezone());
    $db=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    try { $db->exec('SET time_zone=' . $db->quote(get_app_timezone_offset())); } catch (PDOException $e) { error_log($e->getMessage()); }
    }catch(PDOException $e){ error_log($e->getMessage()); return null; } } return $db; }
}

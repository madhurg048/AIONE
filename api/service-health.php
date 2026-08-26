<?php
// Minimal stand-in for the original reachability helper (test copy).
if(!defined('SERVICE_BASE_URL')){ $c=__DIR__.'/../config/config.php'; if(is_file($c)) require_once $c; }
if(!function_exists('serviceProbe')){
  function serviceProbe():array{
    $ch=curl_init(rtrim(defined('SERVICE_BASE_URL')?SERVICE_BASE_URL:'','/').'/');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_NOBODY=>true,
      CURLOPT_TIMEOUT=>4,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_SSL_VERIFYPEER=>false]);
    curl_exec($ch); $errno=curl_errno($ch); curl_close($ch);
    return ['up'=>$errno===0,'errno'=>$errno];
  }
}
if(!function_exists('serviceIsUp')){ function serviceIsUp():bool{ $p=serviceProbe(); return !empty($p['up']); } }

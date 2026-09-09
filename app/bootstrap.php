<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/error.log');
require_once __DIR__ . '/core.php';
if (!is_file(__DIR__.'/../config.local.php')) {
    http_response_code(503);
    exit('Assembly is not configured. Copy config.example.php to config.local.php, enter your database and mail settings, then visit setup.php. See README.md for setup instructions.');
}
if (strlen(config()['app_key']) < 32 || str_contains(config()['app_key'], 'REPLACE')) { http_response_code(503); exit('Set a random app_key in config.local.php before continuing.'); }
require_once __DIR__ . '/../vendor/autoload.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
header('Cache-Control: no-store, private');
if(config()['environment']==='production') header('Strict-Transport-Security: max-age=31536000');
ini_set('session.use_strict_mode','1');
session_name('assembly_session');
session_save_path(__DIR__.'/../storage');
session_set_cookie_params(['lifetime'=>0,'path'=>parse_url(url(),PHP_URL_PATH) ?: '/', 'secure'=>config()['environment']==='production','httponly'=>true,'samesite'=>'Lax']);
session_start();
if(isset($_SESSION['last_seen']) && time()-$_SESSION['last_seen']>1800) $_SESSION=[];
$_SESSION['last_seen']=time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
set_exception_handler(function(Throwable $err): void {
    error_log((string)$err); http_response_code(500);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Assembly</title><h1>Unable to complete this request</h1><p>Please try again or contact your election administrator.</p></html>';
});

require_once __DIR__ . '/migrations.php';
migrateMemberProfiles();

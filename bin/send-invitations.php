<?php
// Optional Hostinger cron: run once per minute to deliver queued invitations without an open admin page.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
ini_set('log_errors','1');
ini_set('error_log',dirname(__DIR__).'/storage/error.log');
require dirname(__DIR__).'/app/core.php';
if (($problem=appKeyError(config()['app_key']??null))!==null) { fwrite(STDERR,$problem."\n"); exit(1); }
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/migrations.php';
date_default_timezone_set('UTC');
migrateMemberProfiles();
$start=microtime(true); $sent=0; $failed=0;
while (microtime(true)-$start<45) {
    $result=processNomineeInvitation();
    if ($result['outcome']==='idle') break;
    if ($result['outcome']==='sent') $sent++; else $failed++;
}
echo "Nominee invitations: $sent sent, $failed failed.\n";

<?php
// Run once from the project directory: php bin/configure-local.php
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
if(is_file($root.'/config.local.php')) exit("Local configuration already exists; no changes made.\n");
$c=require $root.'/config.example.php';
$c['base_url']='http://localhost/codite'; $c['environment']='local';
$c['app_key']=bin2hex(random_bytes(32)); $c['setup_key']=bin2hex(random_bytes(24));
$c['db']['name']='codite_assembly'; $c['mail']['transport']='log';
$c['mail']['from_email']='elections@localhost.test';
$pdo=new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE IF NOT EXISTS codite_assembly CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
file_put_contents($root.'/config.local.php',"<?php\nreturn ".var_export($c,true).";\n");
$pdo->exec('USE codite_assembly'); $pdo->exec(file_get_contents($root.'/app/schema.sql'));
file_put_contents($root.'/storage/setup-key.txt',"Assembly local setup key\n\n".$c['setup_key']."\n\nOpen http://localhost/codite/setup.php and use this key to create your own admin account.\nLocal OTP messages are written to storage/mail.log. No email is sent in local mode.\n");
echo "Local database and configuration created. Your private setup key is in storage/setup-key.txt.\n";

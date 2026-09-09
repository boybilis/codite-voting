<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__); $config=require $root.'/config.local.php';
if($config['environment']!=='local') exit("HTTP tests require local mode.\n");
$tag=bin2hex(random_bytes(6)); $dbName='assembly_http_'.$tag;
$folder=$root.'/storage/http-test-'.$tag; $port=18000+random_int(0,15000); $base='http://127.0.0.1:'.$port;
$d=$config['db']; $pdo=new PDO("mysql:host={$d['host']};port={$d['port']};charset=utf8mb4",$d['user'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$process=null; $checks=0;
function check(bool $ok,string $label): void { global $checks; if(!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; echo "PASS $label\n"; }
function request(string $session,string $path,array $data=[]): array {
    global $base,$folder;
    $c=curl_init($base.'/'.$path); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_COOKIEJAR=>$folder.'/'.$session.'.cookies',CURLOPT_COOKIEFILE=>$folder.'/'.$session.'.cookies',CURLOPT_TIMEOUT=>20]);
    if($data) { curl_setopt($c,CURLOPT_POST,true); curl_setopt($c,CURLOPT_POSTFIELDS,count(array_filter($data,fn($value)=>$value instanceof CURLFile)) ? $data : http_build_query($data)); }
    $body=curl_exec($c); $status=curl_getinfo($c,CURLINFO_RESPONSE_CODE); $error=curl_error($c); curl_close($c);
    if($body===false) throw new RuntimeException($error); return [$status,$body];
}
function token(string $html): string { if(!preg_match('/name="csrf" value="([^"]+)"/',$html,$m)) throw new RuntimeException('No CSRF token in response'); return $m[1]; }
function post(string $session,string $page,array $data): array { [, $html]=request($session,$page); return request($session,$page,['csrf'=>token($html)]+$data); }
function lastCode(): string { global $folder; preg_match_all('/verification code is: (\d{6})/',file_get_contents($folder.'/storage/mail.log'),$m); return end($m[1]); }
function cleanDirectory(string $path,string $allowed): void {
    $real=realpath($path); $base=realpath($allowed);
    if(!$real || !$base || !str_starts_with($real,$base.DIRECTORY_SEPARATOR)) throw new RuntimeException('Unsafe cleanup target');
    foreach(new FilesystemIterator($path) as $entry) { if($entry->isDir()) cleanDirectory($entry->getPathname(),$allowed); else unlink($entry->getPathname()); } rmdir($path);
}
try {
    $pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    mkdir($folder); mkdir($folder.'/app'); mkdir($folder.'/storage'); mkdir($folder.'/vendor'); mkdir($folder.'/assets');
    foreach(['app.css','app.js','bootstrap.min.css','bootstrap.bundle.min.js'] as $asset) copy($root.'/assets/'.$asset,$folder.'/assets/'.$asset);
    foreach(['index.php','setup.php'] as $f) copy($root.'/'.$f,$folder.'/'.$f);
    foreach(['core.php','bootstrap.php','views.php','migrations.php','site.php','schema.sql'] as $f) copy($root.'/app/'.$f,$folder.'/app/'.$f);
    file_put_contents($folder.'/vendor/autoload.php',"<?php require ".var_export($root.'/vendor/autoload.php',true).";");
    $config['base_url']=$base; $config['db']['name']=$dbName; $config['setup_key']=bin2hex(random_bytes(24)); $config['mail']['transport']='log';
    file_put_contents($folder.'/config.local.php',"<?php return ".var_export($config,true).";");
    file_put_contents($folder.'/router.php', '<?php $path=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if(!in_array($path,["/index.php","/setup.php","/"],true)){http_response_code(404);exit;} return false;');
    $process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',$folder,$folder.'/router.php'],[0=>['pipe','r'],1=>['file',$folder.'/server.log','a'],2=>['file',$folder.'/server.log','a']],$pipes,$folder,null,['bypass_shell'=>true,'create_no_window'=>true]);
    if(!is_resource($process)) throw new RuntimeException('Cannot start HTTP test server'); fclose($pipes[0]);
    for($i=0;$i<30;$i++) { try { request('admin','setup.php'); break; } catch(RuntimeException $e) { usleep(100000); } }
    [$status,$html]=request('fresh','index.php');
    check($status===200 && str_contains($html,'Set up your election'),'Empty database homepage redirects to setup');
    $password=bin2hex(random_bytes(12));
    [$status,$html]=post('admin','setup.php',['setup_key'=>$config['setup_key'],'name'=>'Test Administrator','email'=>'admin@example.test','password'=>$password,'password_confirm'=>$password]);
    check($status===200 && str_contains($html,'Election overview'),'Installer creates admin and opens dashboard');
    $pdo->exec("USE `$dbName`");
    [, $html]=request('outsider','index.php?page=members'); check(str_contains($html,'Welcome back'),'Unauthenticated admin pages redirect to login');
    [, $html]=request('outsider','index.php?page=export'); check(str_contains($html,'Welcome back'),'Exports require admin authentication');
    [, $html]=request('outsider','index.php?page=export_members'); check(str_contains($html,'Welcome back'),'Member backup requires admin authentication');
    [, $html]=request('outsider','index.php?page=qr'); check(str_contains($html,'Welcome back'),'Admin QR endpoint requires authentication');
    [, $html]=request('admin','index.php?page=settings',['csrf'=>'invalid','action'=>'settings','title'=>'Tampered']); check(str_contains($html,'Your session expired'),'HTTP CSRF failure rejects change');
    post('admin','index.php?page=settings',['action'=>'settings','title'=>'Test Officer Election','nomination_limit'=>'2','vote_limit'=>'1','officer_count'=>'1']);
    check($pdo->query('SELECT vote_limit FROM elections')->fetchColumn()==1,'HTTP election settings persist');
    foreach(['Ana','Ben','Cara'] as $name) post('admin','index.php?page=members',['action'=>'add_member','first_name'=>$name,'last_name'=>'Member','middle_initial'=>'A','email'=>strtolower($name).'@example.test']);
    $ids=$pdo->query('SELECT id FROM members ORDER BY id')->fetchAll(PDO::FETCH_COLUMN); [$a,$b,$c]=$ids;
    check(count($ids)===3,'HTTP individual member entry works');
    post('admin','index.php?page=members',['action'=>'update_profile','member_id'=>$b,'school'=>'Central School','position'=>'Principal']);
    [, $profileHtml]=request('admin','index.php?page=members&q=Central');
    check(str_contains($profileHtml,'Central School') && str_contains($profileHtml,'Principal'),'School and Position are editable and searchable');
    post('admin','index.php?page=dashboard',['action'=>'phase','next'=>'nomination']);
    $nom='index.php?page=participate&stage=nomination';
    [, $html]=request('member',$nom); check(str_contains($html,'Verify your membership') && !str_contains($html,'Ana A. Member'),'Candidate names are hidden before verification');
    [, $html]=post('member',$nom,['action'=>'submit_ballot','candidates'=>[$b]]); check(str_contains($html,'verification expired'),'Unverified direct ballot submission is rejected');
    [, $html]=post('unknown',$nom,['action'=>'request_otp','email'=>'unknown@example.test']); check(str_contains($html,'This email is not registered. Please contact your administrator.'),'Unregistered email shows membership error');
    check(str_contains($html,'name="email"') && !str_contains($html,'name="code"'),'Unregistered member stays on email entry without OTP input');
    check((int)$pdo->query('SELECT COUNT(*) FROM otp_challenges')->fetchColumn()===0,'Unknown members receive no OTP');
    post('stale',$nom,['action'=>'request_otp','email'=>'ben@example.test']);
    [, $html]=post('stale',$nom,['action'=>'request_otp','email'=>'unknown@example.test']);
    check(str_contains($html,'This email is not registered') && !str_contains($html,'name="code"'),'Unknown email clears an earlier OTP screen');
    [, $html]=post('stale',$nom,['action'=>'verify_otp','code'=>lastCode()]);
    check(str_contains($html,'Request a new verification code.'),'Cleared OTP challenge cannot be used');
    post('member',$nom,['action'=>'request_otp','email'=>'ana@example.test']); $code=lastCode();
    [, $html]=post('member',$nom,['action'=>'verify_otp','code'=>$code]); check(str_contains($html,'Who would you like to nominate?'),'Email OTP unlocks nomination ballot');
    check(str_contains($html,'Central School') && str_contains($html,'Principal'),'Member ballot displays School and Position');
    [, $html]=post('member',$nom,['action'=>'submit_ballot','candidates'=>[$b,$c]]); check(str_contains($html,'Nominations submitted'),'Nomination submission reaches success page');
    post('member',$nom,['action'=>'submit_ballot','candidates'=>[$b]]);
    check((int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE stage='nomination'")->fetchColumn()===1,'Duplicate HTTP submission is blocked');
    foreach(['dashboard','members','nominations','voting','results','settings','audit'] as $page) { [$status,$html]=request('admin','index.php?page='.$page); check($status===200 && str_contains($html,'app-shell') && !str_contains($html,'Unable to complete'),'Populated admin route renders: '.$page); }
    [$status,$svg]=request('admin','index.php?page=qr&stage=nomination'); check($status===200 && str_contains($svg,'<svg'),'QR endpoint returns SVG');
    [, $csv]=request('admin','index.php?page=export&stage=nomination'); check(str_contains($csv,'Ben A. Member') && str_contains($csv,'pending'),'Nomination export includes tally');
    post('admin','index.php?page=nominations',['action'=>'phase','next'=>'review']);
    post('admin','index.php?page=nominations',['action'=>'decision','member_id'=>$b,'decision'=>'accepted']);
    post('admin','index.php?page=nominations',['action'=>'decision','member_id'=>$c,'decision'=>'denied']);
    post('admin','index.php?page=voting',['action'=>'phase','next'=>'voting']);
    $vote='index.php?page=participate&stage=voting';
    [, $html]=post('unknown',$vote,['action'=>'request_otp','email'=>'unknown@example.test']);
    check(str_contains($html,'This email is not registered') && !str_contains($html,'name="code"'),'Voting also rejects unregistered email before OTP');
    [, $html]=request('member',$vote); check(str_contains($html,'Verify your membership'),'Voting requires a fresh email verification');
    $pdo->exec('DELETE FROM rate_limits');
    post('member',$vote,['action'=>'request_otp','email'=>'ana@example.test']);
    [, $html]=post('member',$vote,['action'=>'verify_otp','code'=>lastCode()]); check(str_contains($html,'Ben A. Member') && !str_contains($html,'Cara A. Member'),'Voting ballot exposes accepted candidates only');
    [, $html]=post('member',$vote,['action'=>'submit_ballot','candidates'=>[$b]]); check(str_contains($html,'Your vote is recorded'),'Voting submission reaches success page');
    post('admin','index.php?page=voting',['action'=>'phase','next'=>'closed']);
    [, $html]=request('admin','index.php?page=results'); check(str_contains($html,'Final results') && str_contains($html,'Elected'),'Final result shows elected officer');
    [, $html]=post('admin','index.php?page=settings',['action'=>'request_reset','scope'=>'votes','password'=>'wrong']); check(str_contains($html,'Admin password is incorrect'),'Reset rejects wrong admin password');
    [, $html]=post('admin','index.php?page=settings',['action'=>'request_reset','scope'=>'votes','password'=>$password]); check(str_contains($html,'Reset code sent'),'Reset request sends admin OTP');
    $code=lastCode();
    [, $html]=post('admin','index.php?page=settings',['action'=>'confirm_reset','code'=>$code,'confirmation'=>'wrong']); check(str_contains($html,'Type RESET exactly'),'Reset requires typed confirmation');
    [, $html]=post('admin','index.php?page=settings',['action'=>'confirm_reset','code'=>'000000','confirmation'=>'RESET']); check(str_contains($html,'Invalid or expired code'),'Reset rejects wrong email code');
    [, $html]=post('admin','index.php?page=settings',['action'=>'confirm_reset','code'=>$code,'confirmation'=>'RESET']); check(str_contains($html,'Reset completed'),'Verified reset completes');
    check($pdo->query('SELECT phase FROM elections')->fetchColumn()==='review' && (int)$pdo->query("SELECT COUNT(*) FROM submissions WHERE stage='voting'")->fetchColumn()===0,'HTTP reset deletes votes and returns to review');
    post('admin','index.php?page=settings',['action'=>'logout']);
    [, $html]=post('admin','index.php?page=login',['action'=>'login','email'=>'admin@example.test','password'=>$password]); check(str_contains($html,'Election overview'),'Admin can sign out and sign back in');
    $special=$pdo->prepare('UPDATE members SET first_name=?,school=?,position=? WHERE id=?');
    $special->execute(['Niño "Alex"','=SUM(1,2)',"'Principal",$a]);
    $fields='first_name,last_name,middle_initial,email,school,position';
    $original=$pdo->query('SELECT '.$fields.' FROM members ORDER BY email')->fetchAll(PDO::FETCH_ASSOC);
    [, $backup]=request('admin','index.php?page=export_members&q=nonexistent&p=999');
    check(str_contains($backup,'assembly_backup_version') && str_contains($backup,'ana@example.test') && str_contains($backup,'cara@example.test'),'Member backup exports all profiles despite filters and pagination');
    check(str_contains($backup,"'=SUM") && str_contains($backup,"''Principal"),'Backup escapes formulas and apostrophes reversibly');
    file_put_contents($folder.'/members-backup.csv',$backup);
    $pdo->exec('DELETE FROM rate_limits');
    post('admin','index.php?page=settings',['action'=>'request_reset','scope'=>'all','password'=>$password]);
    post('admin','index.php?page=settings',['action'=>'confirm_reset','code'=>lastCode(),'confirmation'=>'RESET']);
    check((int)$pdo->query('SELECT COUNT(*) FROM members')->fetchColumn()===0,'Full reset clears members before restore');
    [, $emptyBackup]=request('admin','index.php?page=export_members');
    check(count(preg_split('/\r?\n/',trim($emptyBackup)))===1,'Empty register downloads a CSV header');
    [, $html]=post('admin','index.php?page=members',['action'=>'import_csv','csv'=>new CURLFile($folder.'/members-backup.csv','text/csv','members-backup.csv')]);
    check(str_contains($html,'Import complete: 3 added, 0 duplicate emails skipped.'),'Downloaded backup is accepted by the CSV upload');
    $restored=$pdo->query('SELECT '.$fields.' FROM members ORDER BY email')->fetchAll(PDO::FETCH_ASSOC);
    check($restored===$original,'Full reset and CSV restore preserve all profile fields including Unicode, quotes, and formula-like text');
    check(str_contains($html,'Download members CSV'),'Member directory exposes backup download');
    [, $html]=request('outsider','setup.php'); check(str_contains($html,'Welcome back'),'Installer locks after first administrator');
    $log=is_file($folder.'/storage/error.log')?file_get_contents($folder.'/storage/error.log'):'';
    check($log==='', 'No application warnings or errors during HTTP workflow');
    echo "\n$checks HTTP checks passed.\n";
} finally {
    if(is_resource($process)) { proc_terminate($process); proc_close($process); }
    if(preg_match('/^assembly_http_[a-f0-9]{12}$/',$dbName)) $pdo->exec("DROP DATABASE IF EXISTS `$dbName`");
    if(is_dir($folder)) cleanDirectory($folder,$root.'/storage');
}

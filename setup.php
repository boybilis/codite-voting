<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/views.php';
$installed=false;
try { $installed=(bool)one('SELECT id FROM admins LIMIT 1'); } catch(PDOException $error) { if($error->getCode()!=='42S02') throw $error; }
if($installed) redirect('index.php?page=login');
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        checkCsrf();
        if(strlen(config()['setup_key'])<24 || str_contains(config()['setup_key'],'REPLACE') || !hash_equals(config()['setup_key'],(string)($_POST['setup_key']??''))) throw new DomainException('The setup key is incorrect. Use the key from your private configuration.');
        $name=trim((string)($_POST['name']??'')); $email=strtolower(trim((string)($_POST['email']??''))); $password=(string)($_POST['password']??'');
        if(!$name || mb_strlen($name)>120 || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Enter your name and a valid admin email.');
        if(strlen($password)<12 || strlen($password)>72) throw new DomainException('Choose a password between 12 and 72 characters.');
        if($password!==($_POST['password_confirm']??'')) throw new DomainException('The passwords do not match.');
        db()->exec(file_get_contents(__DIR__.'/app/schema.sql'));
        transaction(function() use($name,$email,$password) {
            election(true);
            if(one('SELECT id FROM admins LIMIT 1')) throw new DomainException('Setup is already complete.');
            query('INSERT INTO admins (name,email,password_hash) VALUES (?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $_SESSION['admin_id']=(int)db()->lastInsertId(); audit('installation_complete');
        });
        session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); flash('Your election workspace is ready. Start by adding your members.'); redirect('index.php');
    } catch(DomainException $error) { flash($error->getMessage(),'danger'); }
    catch(Throwable $error) { error_log((string)$error); flash('Setup could not finish. Check the database configuration and application log.','danger'); }
    redirect('setup.php');
}
authStart('Set up your election','Create the administrator account for this workspace.');
?>
<div class="setup-help">Use an email inbox you can access. It will receive verification codes when you reset election data.</div>
<form method="post"><?=csrf()?><label class="form-label" for="setup-key">Private setup key</label><input class="form-control mb-3" id="setup-key" name="setup_key" type="password" autocomplete="off" required><label class="form-label" for="name">Your name</label><input class="form-control mb-3" id="name" name="name" maxlength="120" autocomplete="name" required><label class="form-label" for="email">Admin email</label><input class="form-control mb-3" id="email" name="email" type="email" maxlength="254" autocomplete="email" required><div class="row g-3"><div class="col-sm-6"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div><div class="col-sm-6"><label class="form-label" for="password-confirm">Confirm password</label><input class="form-control" id="password-confirm" name="password_confirm" type="password" minlength="12" maxlength="72" autocomplete="new-password" required></div></div><p class="small-note mt-2">At least 12 characters.</p><button class="btn btn-primary w-100 mt-2">Create admin account <?=icon('arrow')?></button></form>
<?php authEnd();

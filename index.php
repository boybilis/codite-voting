<?php
require __DIR__.'/app/bootstrap.php';
require __DIR__.'/app/views.php';
$page=(string)($_GET['page']??'dashboard');
$public=['login','participate','nominee_response','candidate_photo'];
try {
    $installed = (bool)one('SELECT id FROM admins LIMIT 1');
} catch (PDOException $error) {
    if ($error->getCode() !== '42S02') throw $error;
    $installed = false; // An empty database must reach setup before tables exist.
}
if (!$installed) redirect('setup.php');
if(!in_array($page,$public,true)) requireAdmin();
if ($page==='candidate_photo') {
    $el=election(); $v=$_SESSION['verified']??null;
    $allowed=admin() || ($v && !(memberOtpEnabled() && !empty($v['email_only'])) && $v['expires']>=time() && $v['stage']==='voting' && $v['generation']===(int)$el['generation'] && $el['phase']==='voting' && one('SELECT id FROM members WHERE id=? AND active=1',[$v['member_id']]));
    $photo=$allowed ? one("SELECT p.* FROM member_photos p JOIN nominee_decisions d ON d.member_id=p.member_id JOIN members m ON m.id=p.member_id WHERE p.member_id=? AND d.decision='accepted' AND m.active=1",[(int)($_GET['id']??0)]) : null;
    if (!$photo) { http_response_code(404); exit; }
    header('Content-Type: '.$photo['mime_type']); header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.strlen($photo['image_data'])); echo $photo['image_data']; exit;
}
if ($page==='nominee_response' && isset($_GET['token'])) {
    $_SESSION['nominee_token']=is_string($_GET['token']) ? $_GET['token'] : '';
    header('Referrer-Policy: no-referrer');
    redirect('index.php?page=nominee_response');
}
if ($page==='send_invitations') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['error'=>'Use POST.']); exit; }
    try { checkCsrf(); echo json_encode(processNomineeInvitation(),JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { error_log((string)$error); http_response_code(400); echo json_encode(['error'=>'Unable to send invitations. Refresh or check the application log.']); }
    exit;
}
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        checkCsrf();
        $action=(string)($_POST['action']??'');
        if($action==='logout') { $_SESSION=[]; session_regenerate_id(true); redirect('index.php?page=login'); }
        if($page==='login' && $action==='login') {
            $email=strtolower(trim((string)($_POST['email']??'')));
            rateLimit('login-ip:'.($_SERVER['REMOTE_ADDR']??''),20,900);
            rateLimit('login-email:'.$email,8,900);
            $a=one('SELECT * FROM admins WHERE email=?',[$email]);
            if(!$a || !password_verify((string)($_POST['password']??''),$a['password_hash'])) throw new DomainException('Email or password is incorrect.');
            session_regenerate_id(true); $_SESSION['admin_id']=$a['id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
            audit('admin_login'); redirect('index.php');
         } elseif ($page==='nominee_response') {
            if ($action!=='nominee_decision') throw new DomainException('Unknown action.');
            respondToNomination((string)($_SESSION['nominee_token']??''),(string)($_POST['decision']??''),$_FILES['profile_photo']??null);
            flash('Thank you. Your nomination response has been recorded.');
        } elseif($page==='participate') {
            $stage=(string)($_GET['stage']??'nomination');
            if(!in_array($stage,['nomination','voting'],true)) throw new DomainException('Invalid ballot.');
            $el=election();
            if($action==='start_over') { unset($_SESSION['challenge'],$_SESSION['verified']); redirect('index.php?page=participate&stage='.$stage); }
            if($el['phase']!==$stage) throw new DomainException('This stage is not currently open.');
            if($action==='request_otp') {
                if (memberOtpEnabled()) rateLimit('otp-ip:'.($_SERVER['REMOTE_ADDR']??''),(int)(config()['rate_limits']['otp_ip_per_hour']??1000),3600,'This internet connection has reached its hourly code request limit.');
                $email=strtolower(trim((string)($_POST['email']??'')));
                if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new DomainException('Enter a valid email address.');
                $m=one('SELECT * FROM members WHERE email=? AND active=1',[$email]);
                if (!$m) {
                    unset($_SESSION['challenge'], $_SESSION['verified']);
                    throw new DomainException('This email is not registered. Please contact your administrator.');
                }
                if (!memberOtpEnabled()) {
                    session_regenerate_id(true);
                    $_SESSION['verified']=['member_id'=>(int)$m['id'],'stage'=>$stage,'generation'=>(int)$el['generation'],'expires'=>time()+1800,'email_only'=>true];
                    unset($_SESSION['challenge']);
                    flash('Member found. You can now make your selections.');
                    redirect('index.php?page=participate&stage='.$stage);
                }
                $context=session_id().':'.$el['generation'].':'.$stage;
                $id=issueOtp($email,$stage,$context);
                $_SESSION['challenge']=['id'=>$id,'email'=>$email,'stage'=>$stage,'generation'=>(int)$el['generation'],'context'=>$context];
                unset($_SESSION['verified']);
                flash('A verification code has been sent to your registered email. Check your inbox and spam folder.');
            } elseif($action==='verify_otp') {
                rateLimit('verify-ip:'.($_SERVER['REMOTE_ADDR']??''),(int)(config()['rate_limits']['verification_ip_per_15min']??1000),900);
                $c=$_SESSION['challenge']??null;
                if(!$c || $c['stage']!==$stage || $c['generation']!==(int)$el['generation']) throw new DomainException('Request a new verification code.');
                consumeOtp($c['id'],$c['email'],$stage,$c['context'],trim((string)($_POST['code']??'')));
                $m=one('SELECT id FROM members WHERE email=? AND active=1',[$c['email']]);
                if(!$m) throw new DomainException('Your membership could not be verified.');
                session_regenerate_id(true);
                $_SESSION['verified']=['member_id'=>(int)$m['id'],'stage'=>$stage,'generation'=>(int)$el['generation'],'expires'=>time()+1800];
                unset($_SESSION['challenge']); flash('Membership verified. You can now make your selections.');
            } elseif($action==='submit_ballot') {
                $v=$_SESSION['verified']??null;
                if(!$v || (memberOtpEnabled() && !empty($v['email_only'])) || $v['expires']<time() || $v['stage']!==$stage || $v['generation']!==(int)$el['generation']) throw new DomainException('Your verification expired. Verify your email again.');
                $ids=$_POST['candidates']??[];
                if(!is_array($ids)) throw new DomainException('Invalid ballot.');
                submitBallot($v['member_id'],$stage,$v['generation'],$ids); flash('Your selections have been recorded. Thank you for participating.');
            } else throw new DomainException('Unknown action.');
        } else {
            $a=requireAdmin();
            switch($action) {
                case 'add_member':
                    [$added,$skipped]=addMembers([$_POST]); flash($added?'Member added.':'That email is already registered.',$added?'success':'warning'); break;
                case 'update_profile':
                    updateMemberProfile((int)($_POST['member_id']??0),$_POST); flash('Member profile saved.'); break;
                case 'import_csv':
                    $file=$_FILES['csv']??null;
                    if(!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>2*1024*1024 || !is_uploaded_file($file['tmp_name']) || strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='csv') throw new DomainException('Upload a CSV file up to 2 MB.');
                    [$added,$updated]=addMembers(parseCsv($file['tmp_name']),true); flash("Import complete: $added added, $updated updated."); break;
                case 'settings':
                    transaction(function(){
                        if(election(true)['phase']!=='draft') throw new DomainException('Election settings are locked once nominations open.');
                        $title=trim((string)($_POST['title']??''));
                        if(!$title || mb_strlen($title)>160) throw new DomainException('Enter an election title up to 160 characters.');
                        $values=[];
                        foreach(['nomination_limit','vote_limit','officer_count'] as $key) { $n=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT); if($n===false || $n<1 || $n>100) throw new DomainException('Limits must be whole numbers from 1 to 100.'); $values[]=$n; }
                        query('UPDATE elections SET title=?,nomination_limit=?,vote_limit=?,officer_count=? WHERE id=1',array_merge([$title],$values)); audit('settings_updated');
                    }); flash('Election settings saved.'); break;
                case 'phase':
                    $next=(string)($_POST['next']??''); changePhase($next);
                    if ($next==='review') {
                        processNomineeInvitation();
                        flash('Nominations are closed. Invitation emails are being sent to each nominee.');
                    } else flash('Election phase updated.');
                    break;
                case 'retry_invitations':
                    rateLimit('invitation-retry:'.$a['id'],1,60); retryNomineeInvitations();
                    flash('Pending invitation emails are queued. Keep this page open while they send.'); break;
                case 'resend_invitation':
                    resendNomineeInvitation((int)($_POST['member_id']??0)); flash('Nominee invitation queued for sending.'); break;
                case 'decision': decideNominee((int)($_POST['member_id']??0),(string)($_POST['decision']??'')); flash('Nominee response saved.'); break;
                case 'request_reset':
                    rateLimit('reset-admin:'.$a['id'],5,900);
                    if(!password_verify((string)($_POST['password']??''),$a['password_hash'])) throw new DomainException('Admin password is incorrect.');
                    $scope=(string)($_POST['scope']??'');
                    if(!in_array($scope,['votes','election','all'],true)) throw new DomainException('Choose a reset option.');
                    $el=election(); $context=session_id().':'.$scope.':'.$el['generation'];
                    $id=issueOtp($a['email'],'reset',$context);
                    $_SESSION['reset']=['id'=>$id,'email'=>$a['email'],'scope'=>$scope,'generation'=>(int)$el['generation'],'context'=>$context];
                    flash('Reset code sent to your admin email. No records have been removed yet.'); break;
                case 'confirm_reset':
                    $r=$_SESSION['reset']??null;
                    if(!$r || $r['email']!==$a['email']) throw new DomainException('Request a reset code first.');
                    if(($_POST['confirmation']??'')!=='RESET') throw new DomainException('Type RESET exactly to confirm.');
                    consumeOtp($r['id'],$r['email'],'reset',$r['context'],trim((string)($_POST['code']??'')));
                    resetElection($r['scope'],$r['generation']); unset($_SESSION['reset'],$_SESSION['verified'],$_SESSION['challenge']); flash('Reset completed. All previous member verifications have expired.'); break;
                case 'cancel_reset': unset($_SESSION['reset']); flash('Reset cancelled.'); break;
                default: throw new DomainException('Unknown action.');
            }
        }
    } catch(DomainException $error) { flash($error->getMessage(),'danger'); }
    catch(Throwable $error) { error_log((string)$error); flash('The request could not be completed. Please try again or check the application log.','danger'); }
    $dest='index.php?page='.rawurlencode($page);
    if($page==='participate') $dest.='&stage='.rawurlencode((string)($_GET['stage']??'nomination'));
    redirect($dest);
}
if($page==='export') {
    $stage=($_GET['stage']??'nomination')==='voting'?'voting':'nomination';
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$stage.'-results.csv"');
    $out=fopen('php://output','w'); fputcsv($out,['Name','School','Position','Votes',$stage==='voting'?'Result':'Decision']);
    $rows=$stage==='voting'?rankResults(tally($stage),(int)election()['officer_count']):tally($stage);
    foreach($rows as $r) { $n=name($r); if(preg_match('/^[=+@\-\t\r]/',$n)) $n="'".$n; fputcsv($out,[$n,csvText($r['school']),csvText($r['position']),$r['votes'],$stage==='voting'?(election()['phase']==='closed'?$r['result']:'Provisional'):$r['decision']]); } fclose($out); exit;
}
if ($page === 'export_members') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registered-members-'.gmdate('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $fields = ['first_name','last_name','middle_initial','email','school','position','member_status'];
    fputcsv($out, array_merge($fields, ['assembly_backup_version']), ',', '"', '');
    $members = query('SELECT first_name,last_name,middle_initial,email,school,position,member_status FROM members ORDER BY last_name,first_name,id');
    while ($member = $members->fetch()) {
        $values = array_map(fn($field)=>memberBackupText($member[$field]), $fields);
        fputcsv($out, array_merge($values, ['1']), ',', '"', '');
    }
    fclose($out); exit;
}
if($page==='template') { header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="members-template.csv"'); echo "first_name,last_name,middle_initial,email,school,position,member_status\nJuan,Dela Cruz,A,juan@example.com,Sample School,Teacher,Member\n"; exit; }
if($page==='qr') {
    $stage=($_GET['stage']??'nomination')==='voting'?'voting':'nomination';
    $qr=Endroid\QrCode\QrCode::create(url('index.php?page=participate&stage='.$stage))->setSize(320)->setMargin(16);
    $result=(new Endroid\QrCode\Writer\SvgWriter())->write($qr);
    header('Content-Type: image/svg+xml'); if(isset($_GET['download'])) header('Content-Disposition: attachment; filename="'.$stage.'-qr.svg"'); echo $result->getString(); exit;
}
if($page==='nominee_response') renderNomineeResponse();
elseif($page==='login') renderLogin();
elseif($page==='participate') renderMember((string)($_GET['stage']??'nomination'));
elseif(in_array($page,['dashboard','members','nominations','voting','results','settings','audit'],true)) renderAdmin($page);
else { http_response_code(404); renderAdmin('not-found'); }

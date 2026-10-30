<?php
function currentAcademicYear(?DateTimeImmutable $at=null): string {
    $at=($at??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('Asia/Manila'));
    $year=(int)$at->format('Y')-((int)$at->format('n')<6?1:0);
    return 'AY'.$year.'-'.($year+1);
}
function academicYearDates(string $year): array {
    if (!preg_match('/^AY([0-9]{4})-([0-9]{4})$/D',$year,$m) || (int)$m[2]!==((int)$m[1]+1)) throw new DomainException('Invalid academic year.');
    return ['starts'=>$m[1].'-06-01','expires'=>$m[2].'-05-31'];
}
function membershipHistory(int $memberId): array {
    return query("SELECT member_id AS id,'New' AS type,academic_year,payment_date,payment_amount,status,created_at,reviewed_at FROM membership_registrations WHERE member_id=? UNION ALL SELECT id,'Renewal' AS type,academic_year,payment_date,payment_amount,status,created_at,reviewed_at FROM membership_renewals WHERE member_id=? ORDER BY payment_date DESC,created_at DESC",[$memberId,$memberId])->fetchAll();
}
function membershipSummary(array $history, ?DateTimeImmutable $at=null): array {
    $current=currentAcademicYear($at); $approved=array_values(array_filter($history,fn($r)=>$r['status']==='approved'));
    $currentPaid=array_filter($approved,fn($r)=>$r['academic_year']===$current);
    $first=$approved?min(array_column($approved,'payment_date')):null;
    $latest=$approved?max(array_column($approved,'academic_year')):null;
    return ['current'=>$current,'active'=>(bool)$currentPaid,'first_recorded'=>$first,'latest'=>$latest,'expires'=>academicYearDates($current)['expires']];
}
function profileMember(): ?array {
    $session=$_SESSION['membership_profile']??null;
    if (!$session || $session['expires']<time()) { unset($_SESSION['membership_profile']); return null; }
    return one('SELECT * FROM members WHERE id=? AND email=?',[$session['member_id'],$session['email']]);
}
function membershipPortalAction(string $action): void {
    if ($action==='profile_signout') { unset($_SESSION['membership_profile'],$_SESSION['profile_challenge']); session_regenerate_id(true); return; }
    if ($action==='profile_request_otp') {
        unset($_SESSION['membership_profile'],$_SESSION['profile_challenge']);
        rateLimit('profile-otp-ip:'.($_SERVER['REMOTE_ADDR']??''),100,3600);
        $email=strtolower(trim((string)($_POST['email']??'')));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new DomainException('Enter a valid email address.');
        if (!one('SELECT id FROM members WHERE email=?',[$email])) throw new DomainException('This email has no membership record. Please register or contact the administrator.');
        $context=session_id().':membership-profile';
        $id=issueOtp($email,'membership-profile',$context);
        $_SESSION['profile_challenge']=['id'=>$id,'email'=>$email,'context'=>$context];
        flash('A verification code was sent to your email.'); return;
    }
    if ($action==='profile_verify_otp') {
        rateLimit('profile-verify-ip:'.($_SERVER['REMOTE_ADDR']??''),100,900);
        $c=$_SESSION['profile_challenge']??null;
        if (!$c) throw new DomainException('Request a new verification code.');
        consumeOtp($c['id'],$c['email'],'membership-profile',$c['context'],trim((string)($_POST['code']??'')));
        $member=one('SELECT id,email FROM members WHERE email=?',[$c['email']]);
        if (!$member) throw new DomainException('Membership record is no longer available.');
        session_regenerate_id(true);
        $_SESSION['membership_profile']=['member_id'=>(int)$member['id'],'email'=>$member['email'],'expires'=>time()+1800];
        unset($_SESSION['profile_challenge']); flash('Email verified. Welcome to your membership profile.'); return;
    }
    if ($action==='renew_membership') {
        $member=profileMember(); if (!$member) throw new DomainException('Verify your email before submitting a renewal.');
        submitMembershipRenewal((int)$member['id'],$_POST,$_FILES['payment_proof']??null);
        flash('Renewal received. Your payment is pending administrator verification.'); return;
    }
    throw new DomainException('Unknown action.');
}
function submitMembershipRenewal(int $memberId,array $input,?array $proof): void {
    $year=(string)($input['academic_year']??'');
    if (!in_array($year,academicYearOptions(),true)) throw new DomainException('Choose an academic year from the list.');
    $paid=registrationDate((string)($input['payment_date']??''),'payment date');
    $amount=trim((string)($input['payment_amount']??''));
    if (!preg_match('/^[0-9]{1,7}(\.[0-9]{1,2})?$/D',$amount) || (float)$amount<=0) throw new DomainException('Enter a valid payment amount.');
    if (($input['consent']??'')!=='1') throw new DomainException('Confirm the renewal details and consent before submitting.');
    if (!$proof || ($proof['error']??null)!==UPLOAD_ERR_OK || !is_string($proof['tmp_name']??null) || !is_uploaded_file($proof['tmp_name'])) throw new DomainException('Upload payment proof as JPG, PNG, WebP, or PDF, up to 2 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($proof['tmp_name']); $size=filesize($proof['tmp_name']);
    if (!$size || $size>2097152 || !in_array($mime,['image/jpeg','image/png','image/webp','application/pdf'],true) || ($mime!=='application/pdf' && !@getimagesize($proof['tmp_name']))) throw new DomainException('Upload valid payment proof up to 2 MB.');
    $bytes=file_get_contents($proof['tmp_name']);
    transaction(function () use($memberId,$year,$paid,$amount,$mime,$bytes) {
        election(true);
        $m=one('SELECT id,active FROM members WHERE id=?',[$memberId]);
        $initial=one('SELECT status FROM membership_registrations WHERE member_id=?',[$memberId]);
        if (!$m || !$m['active'] || ($initial && $initial['status']!=='approved')) throw new DomainException('Your initial membership must be approved before you can renew.');
        foreach (membershipHistory($memberId) as $record) if ($record['academic_year']===$year && in_array($record['status'],['pending','approved'],true)) throw new DomainException('You already have a pending or approved membership payment for this academic year.');
        query('INSERT INTO membership_renewals (member_id,academic_year,payment_date,payment_amount,proof_mime,proof_data) VALUES (?,?,?,?,?,?)',[$memberId,$year,$paid,$amount,$mime,$bytes]);
        query('INSERT INTO audit_log (admin_id,action,details) VALUES (NULL,?,?)',['membership_renewal_requested',json_encode(['member_id'=>$memberId,'academic_year'=>$year])]);
    });
}
function reviewRenewal(int $id,string $decision,bool $verified): void {
    $admin=requireAdmin();
    if (!in_array($decision,['approved','rejected'],true)) throw new DomainException('Choose Approve or Reject.');
    if ($decision==='approved' && !$verified) throw new DomainException('Confirm that you have verified the payment proof.');
    transaction(function () use($id,$decision,$admin) {
        election(true); $r=one('SELECT status FROM membership_renewals WHERE id=? FOR UPDATE',[$id]);
        if (!$r || $r['status']!=='pending') throw new DomainException('This renewal is no longer pending.');
        query('UPDATE membership_renewals SET status=?,reviewed_at=UTC_TIMESTAMP(),reviewed_by=? WHERE id=?',[$decision,$admin['id'],$id]);
        audit('membership_renewal_'.$decision,['renewal_id'=>$id]);
    });
}
function renderMembershipProfile(): void {
    $m=profileMember();
    if (!$m) {
        $challenge=$_SESSION['profile_challenge']??null;
        authStart('Your membership profile','Verify your email to view your profile and private payment history.');
        if ($challenge): ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="profile_verify_otp"><label class="form-label" for="profile-code">Email verification code</label><input class="form-control mb-3" id="profile-code" name="code" pattern="[0-9]{6}" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required><button class="btn btn-primary w-100">Verify and view profile</button></form><form method="post" class="mt-3"><?=csrf()?><input type="hidden" name="action" value="profile_request_otp"><input type="hidden" name="email" value="<?=e($challenge['email'])?>"><button class="btn btn-link">Resend code</button></form><?php postButton('profile_signout','Use another email',[],'btn-link'); else: ?><form method="post"><?=csrf()?><input type="hidden" name="action" value="profile_request_otp"><label class="form-label" for="profile-email">Registered email</label><input class="form-control mb-3" type="email" name="email" id="profile-email" maxlength="254" autocomplete="email" required><button class="btn btn-primary w-100">Send verification code</button></form><?php endif; ?><p class="small-note mt-3">Profile access always requires email verification, even when voting OTP is off.</p><a href="index.php?page=login">Back to Assembly</a><?php authEnd(); return;
    }
    $history=membershipHistory((int)$m['id']); $summary=membershipSummary($history);
    $initial=one('SELECT contact_number,birthday,department,institution_address,joined_year,status FROM membership_registrations WHERE member_id=?',[$m['id']]);
    head('My membership'); ?>
<main class="container py-4" style="max-width:1000px"><div class="d-flex flex-wrap justify-content-between gap-3"><a href="index.php?page=login">Assembly</a><?php postButton('profile_signout','Sign out',[],'btn-outline-primary');?></div><h1 class="mt-4">My membership</h1><?php notices(); ?><section class="panel p-4 mb-4"><h2><?=e(name($m))?></h2><dl class="row"><?php foreach(['email'=>'Email','school'=>'Institution','position'=>'Position','member_status'=>'Member type'] as $key=>$label):?><dt class="col-sm-4"><?=e($label)?></dt><dd class="col-sm-8 text-break"><?=e($m[$key]?:'Not recorded')?></dd><?php endforeach?><?php if($initial): foreach(['contact_number'=>'Contact number','birthday'=>'Birthday','department'=>'Department / college','institution_address'=>'Institution address','joined_year'=>'Reported first joining year'] as $key=>$label):?><dt class="col-sm-4"><?=e($label)?></dt><dd class="col-sm-8 text-break"><?=e($initial[$key])?></dd><?php endforeach; endif?><dt class="col-sm-4">First recorded membership date</dt><dd class="col-sm-8"><?=e($summary['first_recorded']??'Not recorded')?></dd></dl><p class="small-note">The first recorded date is based on your earliest approved membership payment. Earlier membership may not be recorded in this system.</p></section>
<section class="panel p-4 mb-4"><h2><?=e($summary['current'])?></h2><p><strong><?=$summary['active'] && $m['active']?'Active membership':'No active membership for this academic year'?></strong></p><p>June 1 through May 31 · Expires <?=e($summary['expires'])?>, end of day (Philippine time).</p><?php if(!$summary['active']):?><p>Submit or renew your membership for this academic year. A pending or future-year payment does not activate the current year.</p><?php endif?></section>
<section class="panel mb-4"><div class="p-4"><h2>Payment history</h2><p>Each entry retains its academic year and verification status.</p></div><div class="table-responsive"><table class="table app-table"><thead><tr><th>Academic year</th><th>Type</th><th>Payment date</th><th>Amount</th><th>Status</th><th>Coverage</th></tr></thead><tbody><?php foreach($history as $r): $dates=academicYearDates($r['academic_year']);?><tr><td><?=e($r['academic_year'])?></td><td><?=e($r['type'])?></td><td><?=e($r['payment_date'])?></td><td>₱<?=e(number_format((float)$r['payment_amount'],2))?></td><td><?=e(ucfirst($r['status']))?></td><td><?=e($dates['starts'].' to '.$dates['expires'])?></td></tr><?php endforeach?></tbody></table></div><?php if(!$history):?><p class="p-4">No payments recorded yet.</p><?php endif?></section>
<?php if($m['active'] && (!$initial || $initial['status']==='approved')): ?><section class="panel p-4" id="renewal"><h2>Renew membership</h2><p>Membership fee: ₱500. Your renewal becomes valid for its selected academic year after admin verification.</p><form method="post" enctype="multipart/form-data" class="row g-3"><?=csrf()?><input type="hidden" name="action" value="renew_membership"><div class="col-md-6"><label class="form-label" for="renew-year">Academic year</label><select class="form-select" name="academic_year" id="renew-year" required><?php foreach(academicYearOptions() as $year):?><option <?=$year===$summary['current']?'selected':''?>><?=e($year)?></option><?php endforeach?></select></div><div class="col-md-6"><label class="form-label" for="renew-date">Date of payment</label><input class="form-control" type="date" id="renew-date" name="payment_date" max="<?=date('Y-m-d')?>" required></div><div class="col-md-6"><label class="form-label" for="renew-amount">Amount paid (PHP)</label><input class="form-control" type="number" id="renew-amount" name="payment_amount" min="0.01" max="9999999.99" step="0.01" required></div><div class="col-12"><label class="form-label" for="renew-proof">Payment proof</label><input class="form-control" type="file" id="renew-proof" name="payment_proof" accept="image/jpeg,image/png,image/webp,application/pdf" required><p class="form-text">JPG, PNG, WebP, or PDF up to 2 MB.</p></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="renew-consent" name="consent" value="1" required><label class="form-check-label" for="renew-consent">I confirm these details are correct and consent to their use for membership renewal verification.</label></div></div><div class="col-12"><button class="btn btn-primary">Submit renewal for verification</button></div></form></section><?php endif?></main><?php foot();
}
function renewalsPage(): void {
    pageTitle('MEMBERSHIP','Renewal requests','Review each academic-year payment before approving.');
    $id=(int)($_GET['id']??0);
    if ($id) {
        $r=one('SELECT r.id,r.academic_year,r.payment_date,r.payment_amount,r.proof_mime,r.status,m.first_name,m.last_name,m.middle_initial,m.email FROM membership_renewals r JOIN members m ON m.id=r.member_id WHERE r.id=?',[$id]);
        if (!$r) { emptyState('Renewal not found','Choose a renewal request.'); return; } ?>
<section class="panel p-4"><a href="index.php?page=renewals">All renewals</a><h2><?=e(name($r))?></h2><p><?=e($r['email'])?> · <?=e($r['academic_year'])?> · <?=e($r['payment_date'])?> · ₱<?=e($r['payment_amount'])?> · <?=e(ucfirst($r['status']))?></p><?php if($r['proof_mime']==='application/pdf'):?><iframe title="Renewal payment proof" src="index.php?page=renewal_proof&id=<?=$id?>" class="w-100 border rounded" style="height:600px"></iframe><?php else:?><img class="img-fluid border rounded" src="index.php?page=renewal_proof&id=<?=$id?>" alt="Renewal payment proof"><?php endif?><?php if($r['status']==='pending'):?><form method="post" class="mt-3"><?=csrf()?><input type="hidden" name="action" value="review_renewal"><input type="hidden" name="renewal_id" value="<?=$id?>"><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="verified" value="1" id="renew-verified"><label class="form-check-label" for="renew-verified">I have verified the payment proof and academic year.</label></div><div class="d-flex flex-wrap gap-3"><button class="btn btn-primary" name="decision" value="approved">Approve renewal</button><button class="btn btn-outline-danger" name="decision" value="rejected">Reject renewal</button></div></form><?php endif?></section><?php return;
    }
    $p=max(1,(int)($_GET['p']??1)); $count=(int)query('SELECT COUNT(*) FROM membership_renewals')->fetchColumn(); $pages=max(1,(int)ceil($count/30)); $p=min($p,$pages);
    $rows=query('SELECT r.id,r.academic_year,r.status,m.first_name,m.last_name,m.middle_initial FROM membership_renewals r JOIN members m ON m.id=r.member_id ORDER BY r.id DESC LIMIT 30 OFFSET '.(($p-1)*30))->fetchAll(); ?>
<section class="panel"><div class="table-responsive"><table class="table app-table"><thead><tr><th>Member</th><th>Academic year</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(name($r))?></td><td><?=e($r['academic_year'])?></td><td><?=e(ucfirst($r['status']))?></td><td><a class="btn btn-sm btn-outline-primary" href="index.php?page=renewals&id=<?=$r['id']?>">Review</a></td></tr><?php endforeach?></tbody></table></div><div class="p-3">Page <?=$p?> of <?=$pages?><?php if($p>1):?> <a href="index.php?page=renewals&p=<?=$p-1?>">Previous</a><?php endif?><?php if($p<$pages):?> <a href="index.php?page=renewals&p=<?=$p+1?>">Next</a><?php endif?></div></section><?php
}

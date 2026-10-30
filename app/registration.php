<?php
function academicYearOptions(): array {
    $start=(int)(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y');
    $years=[]; for ($y=$start-1;$y<=$start+4;$y++) $years[]='AY'.$y.'-'.($y+1);
    return $years;
}
function registrationDate(string $value, string $label): string {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$date || $date->format('Y-m-d')!==$value || $value>date('Y-m-d')) throw new DomainException('Enter a valid '.$label.' that is not in the future.');
    return $value;
}
function registerMembership(array $input, ?array $proof): void {
    $m=memberInput(array_merge($input,['member_status'=>'Member']));
    $academicYear=(string)($input['academic_year']??'');
    if (!in_array($academicYear,academicYearOptions(),true)) throw new DomainException('Choose an academic year from the list.');
    $details=[];
    foreach (['contact_number'=>40,'department'=>160,'institution_address'=>500,'interests'=>3000,'suggestions'=>3000] as $field=>$max) {
        $value=trim((string)($input[$field]??''));
        if ($value==='' || mb_strlen($value)>$max) throw new DomainException('Complete all registration fields within the displayed length limits.');
        $details[$field]=$value;
    }
    if (!$m['school'] || !$m['position']) throw new DomainException('Enter your institution and position.');
    if (!preg_match('/^[0-9+() .-]{7,40}$/D',$details['contact_number'])) throw new DomainException('Enter a valid contact number.');
    $birthday=registrationDate((string)($input['birthday']??''),'birthday');
    $paid=registrationDate((string)($input['payment_date']??''),'payment date');
    $year=filter_var($input['joined_year']??null,FILTER_VALIDATE_INT);
    if (!$year || $year<1900 || $year>(int)date('Y')) throw new DomainException('Enter a valid year you first joined CODITE Region IV.');
    $amount=trim((string)($input['payment_amount']??''));
    if (!preg_match('/^[0-9]{1,7}(\.[0-9]{1,2})?$/D',$amount) || (float)$amount<=0) throw new DomainException('Enter a valid payment amount.');
    if (!in_array($input['willing_speaker']??null,['0','1'],true)) throw new DomainException('Choose whether you are willing to be a speaker.');
    if (($input['consent']??'')!=='1') throw new DomainException('Please confirm the privacy consent before submitting.');
    if (!$proof || ($proof['error']??null)!==UPLOAD_ERR_OK || !is_string($proof['tmp_name']??null) || !is_uploaded_file($proof['tmp_name'])) throw new DomainException('Upload payment proof as JPG, PNG, WebP, or PDF, up to 2 MB.');
    $size=filesize($proof['tmp_name']); $mime=(new finfo(FILEINFO_MIME_TYPE))->file($proof['tmp_name']);
    if (!$size || $size>2097152 || !in_array($mime,['image/jpeg','image/png','image/webp','application/pdf'],true)) throw new DomainException('Payment proof must be JPG, PNG, WebP, or PDF, up to 2 MB.');
    if ($mime!=='application/pdf' && !@getimagesize($proof['tmp_name'])) throw new DomainException('Upload a valid image for payment proof.');
    $bytes=file_get_contents($proof['tmp_name']);
    transaction(function () use ($m,$details,$birthday,$paid,$year,$amount,$input,$mime,$bytes,$academicYear) {
        election(true);
        if (one('SELECT id FROM members WHERE email=?',[$m['email']])) throw new DomainException('This email already has a member record or registration request. Please contact the membership administrator.');
        query('INSERT INTO members (first_name,last_name,middle_initial,email,school,position,member_status,active) VALUES (?,?,?,?,?,?,?,0)',array_values($m));
        $id=(int)db()->lastInsertId();
        query('INSERT INTO membership_registrations (academic_year,member_id,contact_number,birthday,department,institution_address,joined_year,payment_date,payment_amount,interests,willing_speaker,suggestions,proof_mime,proof_data,consent_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$academicYear,$id,$details['contact_number'],$birthday,$details['department'],$details['institution_address'],$year,$paid,$amount,$details['interests'],(int)$input['willing_speaker'],$details['suggestions'],$mime,$bytes]);
        query('INSERT INTO audit_log (admin_id,action,details) VALUES (NULL,?,?)',['membership_requested',json_encode(['member_id'=>$id])]);
    });
}
function reviewMembership(int $id,string $decision,bool $verified): void {
    $a=requireAdmin();
    if (!in_array($decision,['approved','rejected'],true)) throw new DomainException('Choose Approve or Reject.');
    if ($decision==='approved' && !$verified) throw new DomainException('Confirm that you have verified the details and payment proof before approving.');
    transaction(function () use ($id,$decision,$a) {
        election(true);
        $r=one('SELECT status FROM membership_registrations WHERE member_id=? FOR UPDATE',[$id]);
        if (!$r || $r['status']!=='pending') throw new DomainException('This registration is no longer pending.');
        query('UPDATE membership_registrations SET status=?,reviewed_at=UTC_TIMESTAMP(),reviewed_by=? WHERE member_id=?',[$decision,$a['id'],$id]);
        query('UPDATE members SET active=? WHERE id=?',[$decision==='approved'?1:0,$id]);
        audit('membership_'.$decision,['member_id'=>$id]);
    });
}
function renderRegistration(): void {
    head('New Membership Registration'); ?>
<main class="container py-4" style="max-width:900px"><a class="text-link" href="index.php?page=login">Back to Assembly</a><section class="panel p-4 mt-3"><h1>New Membership Registration</h1><p>CODITE Region IV · Membership fee: ₱500</p><?php notices(); if (!empty($_SESSION['registration_sent'])): unset($_SESSION['registration_sent']); ?><div class="alert alert-success"><h2 class="h5">Registration received</h2><p class="mb-0">Your registration is pending administrator verification and approval. Submitting this form does not activate your membership.</p></div><?php else: $values=$_SESSION['registration_values']??[]; unset($_SESSION['registration_values']); ?>
<p>Your details and payment proof will be used to review your membership request and will be accessible to authorized administrators.</p><form method="post" enctype="multipart/form-data" class="row g-3"><?=csrf()?><input type="hidden" name="action" value="register_membership">
<?php foreach(['first_name'=>['First name','text',100],'last_name'=>['Last name','text',100],'middle_initial'=>['Middle initial (optional)','text',10],'email'=>['Email address for your membership certificate','email',254],'contact_number'=>['Contact number','tel',40],'birthday'=>['Birthday','date',10],'school'=>['Current institution / school / organization','text',160],'position'=>['Designation / position','text',120],'department'=>['Department / college','text',160],'institution_address'=>['Institution complete address','text',500],'joined_year'=>['Year you first joined CODITE Region IV','number',4],'payment_date'=>['Date of payment','date',10],'payment_amount'=>['Amount paid (PHP)','number',10]] as $key=>[$label,$type,$max]): ?><div class="col-md-6"><label class="form-label" for="reg-<?=e($key)?>"><?=e($label)?></label><input class="form-control" id="reg-<?=e($key)?>" name="<?=e($key)?>" type="<?=e($type)?>" maxlength="<?=$max?>" value="<?=e($values[$key]??'')?>" <?=$key!=='middle_initial'?'required':''?> <?=$type==='date'?'max="'.date('Y-m-d').'"':''?> <?=$key==='payment_amount'?'min="0.01" max="9999999.99" step="0.01"':''?> <?=$key==='joined_year'?'min="1900" max="'.date('Y').'"':''?>></div><?php endforeach?>
<div class="col-md-6"><label class="form-label" for="academic-year">Academic year</label><select class="form-select" name="academic_year" id="academic-year" required><option value="">Select academic year</option><?php foreach(academicYearOptions() as $ay):?><option value="<?=e($ay)?>" <?=($values['academic_year']??'')===$ay?'selected':''?>><?=e($ay)?></option><?php endforeach?></select></div><div class="col-md-6"><p class="mb-0"><strong>Membership type:</strong> New</p></div><div class="col-12"><label class="form-label" for="payment-proof">Proof of membership payment</label><input class="form-control" id="payment-proof" name="payment_proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required><div class="form-text">One JPG, PNG, WebP, or PDF. Maximum 2 MB. If you correct an error, select the file again.</div></div>
<?php foreach(['interests'=>'Academic and professional interests / areas of expertise','suggestions'=>'Suggestions for future CODITE topics, trainings, or activities'] as $key=>$label):?><div class="col-12"><label class="form-label" for="reg-<?=e($key)?>"><?=e($label)?></label><textarea class="form-control" id="reg-<?=e($key)?>" name="<?=e($key)?>" rows="3" maxlength="3000" required><?=e($values[$key]??'')?></textarea></div><?php endforeach?>
<div class="col-12"><label class="form-label" for="speaker">Willing to be a speaker/resource person for future events?</label><select class="form-select" name="willing_speaker" id="speaker" required><option value="">Choose</option><option value="1" <?=($values['willing_speaker']??'')==='1'?'selected':''?>>Yes</option><option value="0" <?=($values['willing_speaker']??'')==='0'?'selected':''?>>No</option></select></div><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="consent" name="consent" value="1" required><label class="form-check-label" for="consent">I consent to the collection and use of these details and payment proof for membership verification and administration. I confirm the information is accurate.</label></div></div><div class="col-12"><button class="btn btn-primary">Submit registration request</button></div></form><?php endif?></section></main><?php foot();
}
function registrationsPage(): void {
    pageTitle('MEMBERSHIP','Registration requests','Review applicant details and payment proof before approving membership.');
    $id=(int)($_GET['id']??0);
    if ($id) {
        $r=one('SELECT m.*,r.academic_year,r.proof_mime,r.contact_number,r.birthday,r.department,r.institution_address,r.joined_year,r.membership_type,r.payment_date,r.payment_amount,r.interests,r.willing_speaker,r.suggestions,r.status FROM members m JOIN membership_registrations r ON r.member_id=m.id WHERE m.id=?',[$id]);
        if (!$r) { emptyState('Request not found','Choose a registration request.'); return; } ?>
<section class="panel p-4"><a href="index.php?page=registrations">All requests</a><h2 class="mt-3"><?=e(name($r))?></h2><dl class="row"><?php foreach(['academic_year','email','contact_number','birthday','school','position','department','institution_address','joined_year','membership_type','payment_date','payment_amount','interests','suggestions','status'] as $field):?><dt class="col-sm-4"><?=e(ucwords(str_replace('_',' ',$field)))?></dt><dd class="col-sm-8 text-break"><?=e($r[$field])?></dd><?php endforeach?><dt class="col-sm-4">Willing speaker</dt><dd class="col-sm-8"><?=$r['willing_speaker']?'Yes':'No'?></dd></dl><h3 class="h5">Payment proof</h3><?php if($r['proof_mime']==='application/pdf'):?><iframe title="Payment proof" src="index.php?page=registration_proof&id=<?=$id?>" class="w-100 border rounded mb-3" style="height:600px"></iframe><?php else:?><img src="index.php?page=registration_proof&id=<?=$id?>" alt="Submitted payment proof" class="img-fluid border rounded mb-3"><?php endif?><p><a href="index.php?page=registration_proof&id=<?=$id?>" target="_blank" rel="noopener">View full-size payment proof</a></p><?php if($r['status']==='pending'):?><form method="post"><?=csrf()?><input type="hidden" name="action" value="review_membership"><input type="hidden" name="member_id" value="<?=$id?>"><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="verified" value="1" id="verified-details"><label class="form-check-label" for="verified-details">I have verified the applicant details and payment proof.</label></div><div class="d-flex gap-3 flex-wrap"><button class="btn btn-primary" name="decision" value="approved">Approve membership</button><button class="btn btn-outline-danger" name="decision" value="rejected" data-confirm="Reject this membership request?">Reject request</button></div></form><?php endif?></section>
<?php return; }
    $p=max(1,(int)($_GET['p']??1)); $count=(int)query('SELECT COUNT(*) FROM membership_registrations')->fetchColumn(); $pages=max(1,(int)ceil($count/30)); $p=min($p,$pages);
    $rows=query('SELECT m.id,m.first_name,m.last_name,m.middle_initial,m.email,r.status,r.created_at FROM members m JOIN membership_registrations r ON r.member_id=m.id ORDER BY r.created_at DESC,m.id DESC LIMIT 30 OFFSET '.(($p-1)*30))->fetchAll(); ?>
<section class="panel"><div class="table-responsive"><table class="table app-table"><thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(name($r))?></td><td><?=e($r['email'])?></td><td><?=e(ucfirst($r['status']))?></td><td><a class="btn btn-sm btn-outline-primary" href="index.php?page=registrations&id=<?=$r['id']?>">Review</a></td></tr><?php endforeach?></tbody></table></div><div class="p-3">Page <?=$p?> of <?=$pages?><?php if($p>1):?> <a href="index.php?page=registrations&p=<?=$p-1?>">Previous</a><?php endif?><?php if($p<$pages):?> <a href="index.php?page=registrations&p=<?=$p+1?>">Next</a><?php endif?></div></section><?php
}

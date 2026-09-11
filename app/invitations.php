<?php
// Call while holding the election row lock. The queue is unique per member and round.
function queueNomineeInvitations(array $el): int {
    $queued=0;
    foreach (tally('nomination') as $nominee) {
        if ($nominee['decision']!=='pending' || !$nominee['active']) continue;
        $s=query('INSERT IGNORE INTO nominee_invitations (id,member_id,generation,expires_at) VALUES (?,?,?,?)', [bin2hex(random_bytes(16)), $nominee['id'], $el['generation'], gmdate('Y-m-d H:i:s', time()+14*86400)]);
        $queued += $s->rowCount();
    }
    return $queued;
}
function invitationToken(array $invitation): string {
    $data='nomination-response:'.$invitation['id'].':'.$invitation['member_id'].':'.$invitation['generation'].':'.$invitation['expires_at'];
    return $invitation['id'].'.'.hash_hmac('sha256', $data, config()['app_key']);
}
function invitationForToken(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}\.[a-f0-9]{64}$/D', $token)) return null;
    $row=one("SELECT i.*,m.first_name,m.last_name,m.middle_initial,m.school,m.position,m.email,m.active,COALESCE(d.decision,'pending') AS decision FROM nominee_invitations i JOIN members m ON m.id=i.member_id LEFT JOIN nominee_decisions d ON d.member_id=m.id WHERE i.id=?", [substr($token,0,32)]);
    if (!$row || !hash_equals(invitationToken($row),$token) || !$row['active'] || (int)$row['generation']!==(int)election()['generation'] || strtotime($row['expires_at'].' UTC')<=time()) return null;
    return $row;
}
function respondToNomination(string $token, string $decision, ?array $photo=null): void {
    if (!in_array($decision,['accepted','denied'],true)) throw new DomainException('Choose Accept nomination or Decline nomination.');
    transaction(function () use ($token,$decision,$photo) {
        $el=election(true);
        if ($el['phase']!=='review') throw new DomainException('Nomination responses are closed. Please contact your administrator.');
        $invitation=invitationForToken($token);
        if (!$invitation) throw new DomainException('This invitation link is invalid or expired. Please contact your administrator.');
        if ($invitation['responded_at'] || $invitation['decision']!=='pending') throw new DomainException('Your response has already been recorded. Contact your administrator if it needs to change.');
        if (!one("SELECT member_id FROM nominee_decisions WHERE member_id=? AND is_manual=1",[$invitation['member_id']]) && !one("SELECT c.candidate_id FROM choices c JOIN submissions s ON s.id=c.submission_id WHERE s.stage='nomination' AND c.candidate_id=? LIMIT 1",[$invitation['member_id']])) throw new DomainException('This nomination is no longer available.');
        $hasPhoto=(bool)one('SELECT member_id FROM member_photos WHERE member_id=?',[$invitation['member_id']]);
        $noUpload=$photo===null || ($photo['error']??null)===UPLOAD_ERR_NO_FILE;
        if ($decision==='accepted' && (!$hasPhoto || !$noUpload)) {
            if (!$photo || !isset($photo['error'],$photo['tmp_name']) || $photo['error']!==UPLOAD_ERR_OK || !is_string($photo['tmp_name']) || !is_uploaded_file($photo['tmp_name'])) throw new DomainException('Please upload your profile picture before accepting. Use JPG, PNG, or WebP up to 2 MB.');
            $size=filesize($photo['tmp_name']);
            $info=@getimagesize($photo['tmp_name']);
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);
            if (!$size || $size>2*1024*1024 || !$info || !in_array($mime,['image/jpeg','image/png','image/webp'],true) || $info['mime']!==$mime || $info[0]>4096 || $info[1]>4096) throw new DomainException('Upload a valid JPG, PNG, or WebP picture up to 2 MB and 4096 pixels per side.');
            query('INSERT INTO member_photos (member_id,mime_type,image_data) VALUES (?,?,?) ON DUPLICATE KEY UPDATE mime_type=VALUES(mime_type),image_data=VALUES(image_data)',[$invitation['member_id'],$mime,file_get_contents($photo['tmp_name'])]);
        }
        query('INSERT INTO nominee_decisions (member_id,decision) VALUES (?,?) ON DUPLICATE KEY UPDATE decision=VALUES(decision)',[$invitation['member_id'],$decision]);
        query('UPDATE nominee_invitations SET responded_at=UTC_TIMESTAMP() WHERE id=?',[$invitation['id']]);
        query('INSERT INTO audit_log (admin_id,action,details) VALUES (NULL,?,?)',['nominee_email_response',json_encode(['member_id'=>$invitation['member_id'],'decision'=>$decision,'generation'=>$el['generation']],JSON_THROW_ON_ERROR)]);
    });
}
function invitationQueueStats(): array {
    $stats=['queued'=>0,'sending'=>0,'failed'=>0];
    $el=election();
    if ($el['phase']!=='review') return $stats;
    $rows=query("SELECT i.delivery_status,i.claim_expires_at FROM nominee_invitations i JOIN members m ON m.id=i.member_id LEFT JOIN nominee_decisions d ON d.member_id=i.member_id WHERE i.generation=? AND m.active=1 AND i.responded_at IS NULL AND COALESCE(d.decision,'pending')='pending' AND i.delivery_status<>'sent'",[$el['generation']])->fetchAll();
    foreach ($rows as $row) {
        $status=$row['delivery_status'];
        if ($status==='sending' && strtotime(($row['claim_expires_at']??'1970-01-01').' UTC')<=time()) $status='queued';
        $stats[$status]++;
    }
    return $stats;
}
function processNomineeInvitation(): array {
    $job=transaction(function () {
        $el=election(true);
        if ($el['phase']!=='review') return null;
        $i=one("SELECT i.*,m.first_name,m.last_name,m.middle_initial,m.email FROM nominee_invitations i JOIN members m ON m.id=i.member_id LEFT JOIN nominee_decisions d ON d.member_id=i.member_id WHERE i.generation=? AND m.active=1 AND i.responded_at IS NULL AND COALESCE(d.decision,'pending')='pending' AND (i.delivery_status='queued' OR (i.delivery_status='sending' AND i.claim_expires_at<=UTC_TIMESTAMP())) ORDER BY i.created_at,i.id LIMIT 1 FOR UPDATE",[$el['generation']]);
        if (!$i) return null;
        $i['claim_token']=bin2hex(random_bytes(16)); $i['election_title']=$el['title'];
        query("UPDATE nominee_invitations SET delivery_status='sending',claim_token=?,claim_expires_at=?,last_attempt_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=?",[$i['claim_token'],gmdate('Y-m-d H:i:s',time()+180),$i['id']]);
        return $i;
    });
    if (!$job) return ['outcome'=>'idle','queue'=>invitationQueueStats()];
    $outcome='sent';
    try {
        if (strtotime($job['expires_at'].' UTC')<=time()) throw new RuntimeException('Nominee invitation expired before sending.');
        $link=url('index.php?page=nominee_response&token='.invitationToken($job));
        $body="Hello ".name($job).",\n\nYou have been nominated in ".$job['election_title'].".\n\nWould you like to accept your nomination and stand for election? Open your private response page to accept or decline:\n\n".$link."\n\nWhen accepting, upload a profile picture if you do not have one saved. You can also replace your saved picture. Accepting adds you to the official list of candidates for voting. Opening the link alone does not record a response.\n\nThis private link expires on ".$job['expires_at']." UTC, or when voting opens. Please do not forward it.\n\n".siteName();
        sendEmail($job['email'],siteName().': You have been nominated',$body);
    } catch (Throwable $error) {
        $outcome='failed';
        error_log('Nominee invitation '.$job['id'].': '.$error->getMessage());
    }
    query('UPDATE nominee_invitations SET delivery_status=?,sent_at=IF(?=\'sent\',UTC_TIMESTAMP(),sent_at),claim_token=NULL,claim_expires_at=NULL WHERE id=? AND claim_token=?',[$outcome,$outcome,$job['id'],$job['claim_token']]);
    return ['outcome'=>$outcome,'queue'=>invitationQueueStats()];
}
function retryNomineeInvitations(): void {
    transaction(function () {
        $el=election(true);
        if ($el['phase']!=='review') throw new DomainException('Invitation emails can only be sent during nominee review.');
        queueNomineeInvitations($el);
        query("UPDATE nominee_invitations SET delivery_status='queued' WHERE generation=? AND delivery_status='failed' AND expires_at>UTC_TIMESTAMP() AND responded_at IS NULL",[$el['generation']]);
        audit('nominee_email_queue_retried');
    });
}
function resendNomineeInvitation(int $memberId): void {
    transaction(function () use ($memberId) {
        $el=election(true);
        if ($el['phase']!=='review') throw new DomainException('Invitation emails can only be sent during nominee review.');
        $nominees=array_column(tally('nomination'),null,'id');
        if (!isset($nominees[$memberId]) || $nominees[$memberId]['decision']!=='pending') throw new DomainException('Only pending nominees can receive another invitation.');
        queueNomineeInvitations($el);
        $i=one('SELECT * FROM nominee_invitations WHERE member_id=? AND generation=?',[$memberId,$el['generation']]);
        if (!$i) throw new DomainException('This nominee is no longer eligible.');
        if ($i['last_attempt_at'] && strtotime($i['last_attempt_at'].' UTC')>time()-60) throw new DomainException('Please wait 60 seconds before resending this invitation.');
        if ($i['delivery_status']==='sending' && strtotime($i['claim_expires_at'].' UTC')>time()) throw new DomainException('This invitation is already being sent.');
        // A new private link replaces an expired or previously answered invitation.
        if (strtotime($i['expires_at'].' UTC')<=time() || $i['responded_at']) {
            query('UPDATE nominee_invitations SET id=?,expires_at=?,responded_at=NULL WHERE id=?',[bin2hex(random_bytes(16)),gmdate('Y-m-d H:i:s',time()+14*86400),$i['id']]);
        }
        query("UPDATE nominee_invitations SET delivery_status='queued',claim_token=NULL,claim_expires_at=NULL WHERE member_id=? AND generation=?",[$memberId,$el['generation']]);
        audit('nominee_email_resend',['member_id'=>$memberId]);
    });
}

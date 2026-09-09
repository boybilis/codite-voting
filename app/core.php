<?php
declare(strict_types=1);

function siteName(): string { return 'Assembly by iBarakoTech'; }

function config(): array {
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/../config.local.php';
        $config['app_name'] = siteName();
        if (($config['environment'] ?? 'production') === 'production') {
            $config['base_url'] = require __DIR__ . '/site.php';
        }
    }
    return $config;
}
function appKeyError(mixed $key): ?string {
    if (!is_string($key) || $key === '') return 'app_key is missing or is not a text value.';
    if (str_contains($key, 'REPLACE')) return 'app_key still contains the placeholder text REPLACE. Replace the entire placeholder with your generated key.';
    if (strlen($key) < 32) return 'app_key is present but is only '.strlen($key).' characters long. Use a randomly generated key of at least 32 characters (64 hexadecimal characters recommended).';
    return null;
}
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $d = config()['db'];
        $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement { $s = db()->prepare($sql); $s->execute($params); return $s; }
function one(string $sql, array $params = []): ?array { return query($sql, $params)->fetch() ?: null; }
function election(bool $lock = false): array { return one('SELECT * FROM elections WHERE id=1' . ($lock ? ' FOR UPDATE' : '')); }
function transaction(callable $fn): mixed {
    db()->beginTransaction();
    try { $result = $fn(); db()->commit(); return $result; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = ''): string { return rtrim(config()['base_url'], '/') . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'] = [$message, $type]; }
function csrf(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function checkCsrf(): void { if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new DomainException('Your session expired. Refresh the page and try again.'); }
function name(array $m): string { return $m['first_name'] . ($m['middle_initial'] ? ' ' . rtrim($m['middle_initial'], '.') . '.' : '') . ' ' . $m['last_name']; }
function admin(): ?array { return isset($_SESSION['admin_id']) ? one('SELECT * FROM admins WHERE id=?', [$_SESSION['admin_id']]) : null; }
function requireAdmin(): array { $a = admin(); if (!$a) redirect('index.php?page=login'); return $a; }
function audit(string $action, array $details = []): void { query('INSERT INTO audit_log (admin_id,action,details) VALUES (?,?,?)', [$_SESSION['admin_id'] ?? null, $action, json_encode($details, JSON_THROW_ON_ERROR)]); }
function rateLimit(string $key, int $limit, int $seconds): void {
    $bucket = hash_hmac('sha256', $key, config()['app_key']);
    $allowed = transaction(function () use ($bucket, $limit, $seconds) {
        query('INSERT IGNORE INTO rate_limits (bucket,hits,expires_at) VALUES (?,0,?)', [$bucket, gmdate('Y-m-d H:i:s', time() + $seconds)]);
        $r = one('SELECT * FROM rate_limits WHERE bucket=? FOR UPDATE', [$bucket]);
        if (strtotime($r['expires_at'] . ' UTC') <= time()) { query('UPDATE rate_limits SET hits=1,expires_at=? WHERE bucket=?', [gmdate('Y-m-d H:i:s', time() + $seconds), $bucket]); return true; }
        if ($r['hits'] >= $limit) return false;
        query('UPDATE rate_limits SET hits=hits+1 WHERE bucket=?', [$bucket]); return true;
    });
    if (!$allowed) throw new DomainException('Too many attempts. Please wait before trying again.');
}
function sendCode(string $email, string $code, string $purpose): void {
    $body = "Your ".siteName()." verification code is: $code\n\nPurpose: $purpose\nThis code expires in 10 minutes. Do not share it. If you did not request it, ignore this message.";
    sendEmail($email, siteName().": your $purpose verification code", $body);
}
function sendEmail(string $email, string $subject, string $body): void {
    $c = config(); $m = $c['mail'];
    if ($m['transport'] === 'log' && $c['environment'] === 'local') {
        if (file_put_contents(__DIR__ . '/../storage/mail.log', gmdate('c') . " To: $email\nSubject: $subject\n$body\n\n", FILE_APPEND | LOCK_EX) === false) throw new RuntimeException('Cannot write local mail log.'); return;
    }
    if ($m['transport'] !== 'smtp') throw new RuntimeException('Invalid mail transport.');
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP(); $mail->Host = $m['host']; $mail->Port = (int)$m['port']; $mail->SMTPAuth = true;
    $mail->Username = $m['username']; $mail->Password = $m['password'];
    $mail->SMTPSecure = $m['encryption'] === 'ssl' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 15; $mail->CharSet = 'UTF-8';
    $mail->setFrom($m['from_email'], siteName()); $mail->addAddress($email);
    $mail->Subject = $subject; $mail->Body = $body; $mail->send();
}
function issueOtp(string $email, string $purpose, string $context): string {
    rateLimit('otp-email:' . $email, 5, 3600);
    rateLimit('otp-cooldown:' . $email, 1, 60);
    $id = bin2hex(random_bytes(16)); $code = (string)random_int(100000, 999999);
    query('UPDATE otp_challenges SET consumed=1 WHERE email=? AND purpose=?', [$email, $purpose]);
    query('INSERT INTO otp_challenges (id,email,purpose,code_hash,context_hash,expires_at) VALUES (?,?,?,?,?,?)', [$id, $email, $purpose, password_hash($code, PASSWORD_DEFAULT), hash('sha256', $context), gmdate('Y-m-d H:i:s', time()+600)]);
    try { sendCode($email, $code, $purpose); }
    catch (Throwable $error) { query('UPDATE otp_challenges SET consumed=1 WHERE id=?', [$id]); error_log($error->getMessage()); throw new DomainException('The verification email could not be sent. Please contact the election administrator.'); }
    return $id;
}
function consumeOtp(string $id, string $email, string $purpose, string $context, string $code): void {
    $valid = transaction(function () use ($id,$email,$purpose,$context,$code) {
        $r = one('SELECT * FROM otp_challenges WHERE id=? FOR UPDATE', [$id]);
        if (!$r || $r['email'] !== $email || $r['purpose'] !== $purpose || $r['context_hash'] !== hash('sha256', $context) || $r['consumed'] || $r['attempts'] >= 5 || strtotime($r['expires_at'].' UTC') <= time()) return false;
        query('UPDATE otp_challenges SET attempts=attempts+1 WHERE id=?', [$id]);
        if (!password_verify($code, $r['code_hash'])) return false;
        query('UPDATE otp_challenges SET consumed=1 WHERE id=?', [$id]); return true;
    });
    if (!$valid) throw new DomainException('Invalid or expired code. Codes allow five attempts and expire after 10 minutes.');
}
function memberInput(array $input): array {
    $m = [];
    foreach (['first_name'=>100,'last_name'=>100,'middle_initial'=>10,'email'=>254,'school'=>160,'position'=>120] as $key=>$max) {
        $m[$key] = trim((string)($input[$key] ?? ''));
        if (mb_strlen($m[$key]) > $max || preg_match('/[\x00-\x1F\x7F]/u', $m[$key])) throw new DomainException('A member field is too long or contains invalid characters.');
    }
    $m['email'] = strtolower($m['email']);
    if (!$m['first_name'] || !$m['last_name'] || !filter_var($m['email'], FILTER_VALIDATE_EMAIL)) throw new DomainException('Every member needs a first name, last name, and valid email.');
    return $m;
}
function addMembers(array $rows): array {
    return transaction(function () use ($rows) {
        $el = election(true);
        if (!in_array($el['phase'], ['draft','nomination'], true)) throw new DomainException('The member register is locked after nominations close.');
        $added = 0; $skipped = 0;
        foreach ($rows as $row) {
            $m = memberInput($row);
            if (one('SELECT id FROM members WHERE email=?', [$m['email']])) { $skipped++; continue; }
            query('INSERT INTO members (first_name,last_name,middle_initial,email,school,position) VALUES (?,?,?,?,?,?)', array_values($m)); $added++;
        }
        audit('members_added', compact('added','skipped')); return [$added,$skipped];
    });
}
function parseCsv(string $path): array {
    $f = fopen($path, 'r'); if (!$f) throw new DomainException('Unable to read CSV.');
    try {
        $header = fgetcsv($f, 0, ',', '"', '');
        if (!$header) throw new DomainException('The CSV is empty.');
        $header = array_map(fn($x)=>strtolower(trim(ltrim((string)$x, "\xEF\xBB\xBF"))), $header);
        if (count(array_unique($header)) !== count($header) || array_diff(['first_name','last_name','middle_initial','email'], $header)) throw new DomainException('Use the CSV template headers: first_name,last_name,middle_initial,email.');
        $rows=[]; $line=1;
        while (($row=fgetcsv($f, 0, ',', '"', '')) !== false) {
            $line++; if ($row === [null]) continue;
            if (count($rows)>=5000) throw new DomainException('Upload at most 5,000 members at a time.');
            if (count($row)!==count($header)) throw new DomainException("CSV row $line has the wrong number of fields.");
            try { $rows[]=memberInput(decodeMemberBackup(array_combine($header,$row))); } catch (DomainException $e) { throw new DomainException("CSV row $line: " . $e->getMessage()); }
        }
        if (!$rows) throw new DomainException('The CSV contains no members.'); return $rows;
    } finally { fclose($f); }
}
function tally(string $stage): array {
    if ($stage === 'nomination') return query("SELECT m.*, t.votes, COALESCE(d.decision,'pending') AS decision FROM (SELECT c.candidate_id, COUNT(*) AS votes FROM choices c JOIN submissions s ON s.id=c.submission_id WHERE s.stage='nomination' GROUP BY c.candidate_id) t JOIN members m ON m.id=t.candidate_id LEFT JOIN nominee_decisions d ON d.member_id=m.id ORDER BY votes DESC,m.last_name,m.first_name,m.id")->fetchAll();
    return query("SELECT m.*, (SELECT COUNT(*) FROM choices c JOIN submissions s ON s.id=c.submission_id WHERE c.candidate_id=m.id AND s.stage='voting') AS votes FROM members m JOIN nominee_decisions d ON d.member_id=m.id WHERE d.decision='accepted' ORDER BY votes DESC,m.last_name,m.first_name,m.id")->fetchAll();
}
function rankResults(array $rows, int $seats): array {
    $cutoff = count($rows) >= $seats ? (int)$rows[$seats-1]['votes'] : 0;
    $above = count(array_filter($rows,fn($r)=>(int)$r['votes']>$cutoff));
    $at = count(array_filter($rows,fn($r)=>(int)$r['votes']===$cutoff));
    $tie = $cutoff > 0 && $above+$at > $seats;
    $rank=0; $previous=null;
    foreach ($rows as $i=>&$r) {
        if ($previous !== (int)$r['votes']) $rank=$i+1;
        $previous=(int)$r['votes']; $r['rank']=$rank;
        $r['result'] = $r['votes']==0 ? 'No votes' : (($tie && (int)$r['votes']===$cutoff) ? 'Tied — review required' : ($i < $seats ? 'Elected' : 'Not elected'));
    } unset($r); return $rows;
}
function submitBallot(int $memberId, string $stage, int $generation, array $ids): void {
    transaction(function () use ($memberId,$stage,$generation,$ids) {
        $el=election(true);
        if ($el['phase']!==$stage || (int)$el['generation']!==$generation) throw new DomainException('This ballot is no longer open. Verify again for the current election.');
        if (!one('SELECT id FROM members WHERE id=? AND active=1',[$memberId])) throw new DomainException('Membership is no longer active.');
        if (one('SELECT id FROM submissions WHERE member_id=? AND stage=?',[$memberId,$stage])) throw new DomainException('You have already submitted for this stage.');
        $limit=(int)$el[$stage==='nomination'?'nomination_limit':'vote_limit'];
        $clean=[];
        foreach ($ids as $id) { if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<1) throw new DomainException('Invalid selection.'); $clean[]=(int)$id; }
        if (!$clean || count($clean)>$limit || count(array_unique($clean))!==count($clean)) throw new DomainException("Choose between 1 and $limit different members.");
        foreach ($clean as $id) {
            $valid=$stage==='nomination' ? one('SELECT id FROM members WHERE id=? AND active=1',[$id]) : one("SELECT m.id FROM members m JOIN nominee_decisions d ON d.member_id=m.id WHERE m.id=? AND m.active=1 AND d.decision='accepted'",[$id]);
            if (!$valid) throw new DomainException('One of your selections is no longer eligible.');
        }
        query('INSERT INTO submissions (member_id,stage) VALUES (?,?)',[$memberId,$stage]); $submission=(int)db()->lastInsertId();
        foreach($clean as $id) query('INSERT INTO choices (submission_id,candidate_id) VALUES (?,?)',[$submission,$id]);
    });
}
function changePhase(string $next): void {
    transaction(function () use ($next) {
        $el=election(true); $map=['draft'=>'nomination','nomination'=>'review','review'=>'voting','voting'=>'closed'];
        if (($map[$el['phase']]??null)!==$next) throw new DomainException('That phase transition is not allowed.');
        if ($next==='nomination' && !one('SELECT id FROM members WHERE active=1 LIMIT 1')) throw new DomainException('Add members before opening nominations.');
        if ($next==='voting') {
            $rows=tally('nomination');
            if (!$rows || array_filter($rows,fn($r)=>$r['decision']==='pending')) throw new DomainException('Accept or deny every nominee before opening voting.');
            if (!array_filter($rows,fn($r)=>$r['decision']==='accepted')) throw new DomainException('At least one nominee must accept before voting opens.');
        }
        query('UPDATE elections SET phase=? WHERE id=1',[$next]);
        if ($next === 'review') queueNomineeInvitations($el);
        audit('phase_changed',['from'=>$el['phase'],'to'=>$next]);
    });
}
function decideNominee(int $id, string $decision): void {
    transaction(function () use ($id,$decision) {
        if(election(true)['phase']!=='review') throw new DomainException('Nominee decisions can only be changed during review.');
        if(!in_array($decision,['pending','accepted','denied'],true)) throw new DomainException('Invalid decision.');
        if(!one("SELECT c.candidate_id FROM choices c JOIN submissions s ON s.id=c.submission_id WHERE s.stage='nomination' AND c.candidate_id=? LIMIT 1",[$id])) throw new DomainException('Member has not been nominated.');
        query('INSERT INTO nominee_decisions (member_id,decision) VALUES (?,?) ON DUPLICATE KEY UPDATE decision=VALUES(decision)',[$id,$decision]); audit('nominee_decision',['member_id'=>$id,'decision'=>$decision]);
    });
}
function resetElection(string $scope, int $generation): void {
    transaction(function () use ($scope,$generation) {
        $el=election(true);
        if((int)$el['generation']!==$generation) throw new DomainException('The election changed. Request a new reset code.');
        if(!in_array($scope,['votes','election','all'],true)) throw new DomainException('Invalid reset scope.');
        if($scope==='votes' && !in_array($el['phase'],['voting','closed'],true)) throw new DomainException('Voting can only be reset after voting has opened.');
        query('DELETE FROM nominee_invitations');
        query("DELETE FROM submissions" . ($scope==='votes'?" WHERE stage='voting'":''));
        if($scope!=='votes') query('DELETE FROM nominee_decisions');
        if($scope==='all') query('DELETE FROM members');
        query('UPDATE otp_challenges SET consumed=1');
        query('UPDATE elections SET phase=?,generation=generation+1 WHERE id=1',[$scope==='votes'?'review':'draft']);
        audit('reset',['scope'=>$scope,'previous_generation'=>$generation]);
    });
}

function updateMemberProfile(int $id, array $input): void {
    transaction(function () use ($id, $input) {
        if (!in_array(election(true)['phase'], ['draft','nomination'], true)) throw new DomainException('Member profiles are locked after nominations close.');
        $member = one('SELECT * FROM members WHERE id=?', [$id]);
        if (!$member) throw new DomainException('Member not found.');
        $validated = memberInput(array_merge($member, ['school'=>$input['school']??'', 'position'=>$input['position']??'']));
        query('UPDATE members SET school=?,position=? WHERE id=?', [$validated['school'],$validated['position'],$id]);
        audit('member_profile_updated', ['member_id'=>$id]);
    });
}

function csvText(string $value): string {
    return preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value;
}

function memberBackupText(string $value): string {
    // Escape spreadsheet formulas and leading apostrophes reversibly for re-import.
    return preg_match("/^[=+@\\-']/", $value) ? "'".$value : $value;
}
function decodeMemberBackup(array $record): array {
    if (!array_key_exists('assembly_backup_version', $record)) return $record;
    if ($record['assembly_backup_version'] !== '1') throw new DomainException('Unsupported member backup version.');
    foreach (['first_name','last_name','middle_initial','email','school','position'] as $field) {
        if (isset($record[$field]) && str_starts_with($record[$field], "'")) $record[$field] = substr($record[$field], 1);
    }
    return $record;
}

require_once __DIR__.'/invitations.php';

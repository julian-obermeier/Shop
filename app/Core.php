<?php
declare(strict_types=1);

function app_config(?string $key = null, mixed $default = null): mixed {
    static $cfg;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/app.php';
        if (!is_file($file)) {
            if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/install')) return [];
            http_response_code(503);
            exit('Anwendung noch nicht installiert. Bitte /install/ aufrufen.');
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['app']['timezone'] ?? 'Europe/Berlin');
    }
    if ($key === null) return $cfg;
    $value = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = app_config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], $c['port'] ?? 3306, $c['name'], $c['charset'] ?? 'utf8mb4');
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = ''): string { return rtrim((string)app_config('app.url', ''), '/') . '/' . ltrim($path, '/'); }

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('ANKAUFSESSID');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
session_boot();

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">'; }
function csrf_verify(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(419); exit('Ungültige oder abgelaufene Anfrage.');
    }
}
function flash(string $type, string $text): void { $_SESSION['_flash'][] = [$type, $text]; }
function pull_flashes(): array { $f = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $f; }
function redirect(string $path): never { header('Location: '.url($path)); exit; }

function seller(): ?array {
    $id = $_SESSION['seller_id'] ?? null;
    if (!$id) return null;
    $s = db()->prepare('SELECT * FROM sellers WHERE id=? LIMIT 1'); $s->execute([$id]);
    return $s->fetch() ?: null;
}
function admin(): ?array {
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) return null;
    $s = db()->prepare('SELECT * FROM admins WHERE id=? LIMIT 1'); $s->execute([$id]);
    return $s->fetch() ?: null;
}
function require_seller(): array { $s=seller(); if(!$s) redirect('/login'); return $s; }
function require_admin(): array { $a=admin(); if(!$a) redirect('/admin/login'); return $a; }

function render(string $title, string $content, array $data=[]): void {
    $seller = seller(); $admin = admin(); $flashes = pull_flashes();
    extract($data, EXTR_SKIP);
    require __DIR__.'/View.php';
}
function post(string $k, string $default=''): string { return trim((string)($_POST[$k] ?? $default)); }

function send_app_mail(string $to, string $subject, string $html): bool {
    $from = (string)app_config('mail.from', 'noreply@localhost');
    $name = (string)app_config('mail.name', app_config('app.name','Ankaufsplattform'));
    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: '.mb_encode_mimeheader($name).' <'.$from.">\r\n";
    return @mail($to, $subject, $html, $headers);
}

function make_token(): array {
    $raw = bin2hex(random_bytes(32));
    return [$raw, hash('sha256', $raw)];
}
function order_number(): string {
    $year = date('Y');
    db()->beginTransaction();
    try {
        $q=db()->prepare('SELECT next_value FROM number_sequences WHERE sequence_key=? FOR UPDATE');
        $q->execute(['order_'.$year]); $row=$q->fetch();
        if (!$row) {
            db()->prepare('INSERT INTO number_sequences(sequence_key,next_value) VALUES(?,2)')->execute(['order_'.$year]);
            $n=1;
        } else {
            $n=(int)$row['next_value'];
            db()->prepare('UPDATE number_sequences SET next_value=? WHERE sequence_key=?')->execute([$n+1,'order_'.$year]);
        }
        db()->commit();
        return $year.str_pad((string)$n,4,'0',STR_PAD_LEFT);
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
}
function money(float|int|string $v): string { return number_format((float)$v, 2, ',', '.').' €'; }

function private_upload(array $file, string $folder): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload fehlgeschlagen.');
    $max = 15 * 1024 * 1024;
    if (($file['size'] ?? 0) > $max) throw new RuntimeException('Datei ist zu groß.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','audio/mpeg'=>'mp3','audio/mp4'=>'m4a','audio/wav'=>'wav'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Dateiformat ist nicht erlaubt.');
    $base = __DIR__.'/../storage/private/'.$folder;
    if (!is_dir($base) && !mkdir($base, 0770, true) && !is_dir($base)) throw new RuntimeException('Speicherordner nicht verfügbar.');
    $name = bin2hex(random_bytes(24)).'.'.$allowed[$mime];
    $target = $base.'/'.$name;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('Datei konnte nicht gespeichert werden.');
    return ['path'=>$folder.'/'.$name,'mime'=>$mime,'size'=>(int)$file['size'],'sha256'=>hash_file('sha256',$target)];
}


function setting(string $key, mixed $default=null): mixed {
    static $cache=[];
    if(array_key_exists($key,$cache)) return $cache[$key];
    try{
        $st=db()->prepare("SELECT setting_value FROM settings WHERE setting_key=?");$st->execute([$key]);$v=$st->fetchColumn();
        return $cache[$key]=($v===false?$default:$v);
    }catch(Throwable $e){ return $default; }
}

function offer_evidence_rules(array $offer): array {
    $rules=[];
    if(!empty($offer['evidence_rules_json'])){
        $d=json_decode((string)$offer['evidence_rules_json'],true);
        if(is_array($d))$rules=$d;
    }
    $defaults=[
        'precheck_required_count'=>1,
        'daily'=>['morning'=>1,'midday'=>1,'evening'=>1],
    ];
    return array_replace_recursive($defaults,$rules);
}

function create_order_schedule(int $orderId): void {
    $st=db()->prepare("SELECT o.*,f.evidence_rules_json FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.id=?");$st->execute([$orderId]);$o=$st->fetch();
    if(!$o || (int)($o['duration_days']??0)<=0) return;
    $rules=offer_evidence_rules($o);
    $windows=[
        'morning'=>(string)setting('window_morning','06:00-10:00'),
        'midday'=>(string)setting('window_midday','12:00-16:00'),
        'evening'=>(string)setting('window_evening','18:00-23:59'),
    ];
    $now=new DateTimeImmutable('now',new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
    $today=$now->format('Y-m-d');
    $remaining=[];
    foreach($windows as $key=>$range){
        [$start,$end]=array_pad(explode('-',$range,2),2,'');
        if(!$start||!$end)continue;
        $starts=new DateTimeImmutable($today.' '.$start,$now->getTimezone());
        if($starts>$now)$remaining[$key]=[$start,$end];
    }
    $day1Date=$remaining?$today:$now->modify('+1 day')->format('Y-m-d');
    $run=db()->prepare("SELECT id FROM order_runs WHERE order_id=? ORDER BY run_no DESC LIMIT 1");$run->execute([$orderId]);$runId=$run->fetchColumn()?:null;
    $duration=(int)$o['duration_days'];$grace=max(0,(int)setting('grace_minutes',60));
    for($day=1;$day<=$duration;$day++){
        $date=(new DateTimeImmutable($day1Date,$now->getTimezone()))->modify('+'.($day-1).' day')->format('Y-m-d');
        db()->prepare("INSERT IGNORE INTO order_days(order_id,order_run_id,day_no,day_type,calendar_date,status) VALUES(?,?,?,'regular',?,'planned')")->execute([$orderId,$runId,$day,$date]);
        foreach($windows as $key=>$range){
            $count=(int)($rules['daily'][$key]??0); if($count<=0)continue;
            if($day===1 && $date===$today && !isset($remaining[$key]))continue;
            [$start,$end]=array_pad(explode('-',$range,2),2,'');if(!$start||!$end)continue;
            $starts=$date.' '.$start.':00';$ends=$date.' '.$end.(strlen($end)===5?':00':'');
            $endObj=new DateTimeImmutable($ends,$now->getTimezone());$graceEnd=$endObj->modify('+'.$grace.' minutes')->format('Y-m-d H:i:s');
            db()->prepare("INSERT INTO evidence_windows(order_id,day_no,window_key,starts_at,ends_at,grace_ends_at,required_count,status) VALUES(?,?,?,?,?,?,?,'planned')")->execute([$orderId,$day,$key,$starts,$ends,$graceEnd,$count]);
        }
    }
}

function try_start_order_after_precheck(int $orderId): bool {
    $st=db()->prepare("SELECT o.status,o.offer_id,f.evidence_rules_json FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.id=?");$st->execute([$orderId]);$o=$st->fetch();
    if(!$o||$o['status']!=='precheck')return false;
    $rules=offer_evidence_rules($o);$required=max(1,(int)($rules['precheck_required_count']??1));
    $q=db()->prepare("SELECT SUM(status='accepted') accepted,SUM(status='submitted') pending FROM evidences WHERE order_id=? AND evidence_type='precheck'");$q->execute([$orderId]);$x=$q->fetch()?:['accepted'=>0,'pending'=>0];
    if((int)$x['accepted']<$required || (int)$x['pending']>0)return false;
    db()->beginTransaction();
    try{
        db()->prepare("UPDATE orders SET status='running',started_at=NOW(),updated_at=NOW() WHERE id=? AND status='precheck'")->execute([$orderId]);
        db()->prepare("UPDATE order_runs SET status='running',started_at=COALESCE(started_at,NOW()) WHERE order_id=? AND status='precheck'")->execute([$orderId]);
        create_order_schedule($orderId);
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Vorabkontrolle vollständig freigegeben. Der Auftrag ist gestartet.')")->execute([$orderId]);
        db()->commit();return true;
    }catch(Throwable $e){db()->rollBack();throw $e;}
}

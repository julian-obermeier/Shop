<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

const APP_ROOT = __DIR__ . '/..';

function config(): array {
    static $config;
    if ($config !== null) return $config;
    $file = APP_ROOT . '/config/app.php';
    if (!is_file($file)) {
        header('Location: install/');
        exit;
    }
    $config = require $file;
    date_default_timezone_set((string)($config['app']['timezone'] ?? 'Europe/Berlin'));
    return $config;
}

function app_config(string $path, mixed $default = null): mixed {
    $value = config();
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return $default;
        $value = $value[$key];
    }
    return $value;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = app_config('db', []);
    $pdo = new PDO(
        'mysql:host=' . ($c['host'] ?? 'localhost') . ';port=' . ($c['port'] ?? 3306) . ';dbname=' . ($c['name'] ?? '') . ';charset=utf8mb4',
        (string)($c['user'] ?? ''),
        (string)($c['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function money(mixed $value): string { return number_format((float)$value, 2, ',', '.') . ' €'; }
function post(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}
function url(string $path = '/'): string {
    $base = rtrim((string)app_config('app.url', ''), '/');
    return $base . '/' . ltrim($path, '/');
}
function redirect(string $path): never { header('Location: ' . url($path)); exit; }
function flash(string $type, string $message): void { $_SESSION['_flash'][] = [$type, $message]; }
function pull_flashes(): array { $x = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return is_array($x) ? $x : []; }

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_verify(): void {
    $token = (string)($_POST['_csrf'] ?? '');
    if (!$token || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Ungültige oder abgelaufene Anfrage.');
    }
}

function current_user(): ?array {
    $role = $_SESSION['role'] ?? null;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if (!$role || !$id) return null;
    if ($role === 'admin') {
        $q = db()->prepare('SELECT id,email,name FROM admins WHERE id=? LIMIT 1');
    } elseif ($role === 'seller') {
        $q = db()->prepare('SELECT id,email,first_name,last_name,active FROM sellers WHERE id=? LIMIT 1');
    } else return null;
    $q->execute([$id]);
    $u = $q->fetch();
    if (!$u || ($role === 'seller' && !(int)$u['active'])) return null;
    $u['role'] = $role;
    return $u;
}
function require_login(): array { $u = current_user(); if (!$u) redirect('/login'); return $u; }
function require_admin(): array { $u = require_login(); if ($u['role'] !== 'admin') { http_response_code(403); exit('Zugriff verweigert.'); } return $u; }
function require_seller(): array { $u = require_login(); if ($u['role'] !== 'seller') { http_response_code(403); exit('Zugriff verweigert.'); } return $u; }

function render(string $title, string $content): void {
    // Every rendered POST form gets a CSRF token automatically. This keeps route views concise
    // while ensuring all state-changing form submissions pass the global CSRF check.
    $content = preg_replace_callback(
        '/<form\b([^>]*)method=[\"\']post[\"\']([^>]*)>/i',
        static fn(array $m): string => $m[0] . csrf_field(),
        $content
    ) ?? $content;
    $user = current_user();
    $flashes = pull_flashes();
    require APP_ROOT . '/app/View.php';
}
function not_found(): never { http_response_code(404); render('Nicht gefunden', '<div class="empty"><h1>404</h1><p>Seite nicht gefunden.</p></div>'); exit; }

function create_seller_invitation(int $adminId, ?string $email = null): array {
    $email=$email!==null&&trim($email)!==''?strtolower(trim($email)):null;
    if($email!==null&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Bitte eine gültige E-Mail-Adresse angeben.');
    if($email!==null){
        $q=db()->prepare('SELECT COUNT(*) FROM sellers WHERE email=?');$q->execute([$email]);
        if((int)$q->fetchColumn()>0) throw new RuntimeException('Für diese E-Mail-Adresse existiert bereits ein Verkäuferinnenkonto.');
        db()->prepare("UPDATE seller_invitations SET revoked_at=NOW() WHERE email=? AND used_at IS NULL AND revoked_at IS NULL AND expires_at>NOW()")->execute([$email]);
    }
    $token=bin2hex(random_bytes(32));
    $hash=hash('sha256',$token);
    $expires=(new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
    db()->prepare('INSERT INTO seller_invitations(admin_id,email,token_hash,expires_at) VALUES(?,?,?,?)')
        ->execute([$adminId,$email,$hash,$expires]);
    return [
        'id'=>(int)db()->lastInsertId(),
        'token'=>$token,
        'link'=>url('/invite/'.$token),
        'email'=>$email,
        'expires_at'=>$expires,
    ];
}
function seller_invitation_by_token(string $token): ?array {
    if(!preg_match('/^[a-f0-9]{64}$/',$token)) return null;
    $q=db()->prepare("SELECT i.*
        FROM seller_invitations i
        WHERE i.token_hash=? AND i.used_at IS NULL AND i.revoked_at IS NULL AND i.expires_at>NOW()
        LIMIT 1");
    $q->execute([hash('sha256',$token)]);
    $row=$q->fetch();
    return $row?:null;
}
function send_seller_invitation_email(string $email,string $link,string $expiresAt): bool {
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)) return false;
    $host=(string)(parse_url((string)app_config('app.url',''),PHP_URL_HOST)?:'localhost');
    $from=(string)app_config('mail.from','noreply@'.$host);
    $fromName=(string)app_config('mail.from_name',app_config('app.name','Auftragsportal'));
    $subject='Einladung zur Vermittlungsplattform';
    $body="Hallo,\n\n"
        ."du wurdest eingeladen, ein Verkäuferinnenkonto auf der privaten Vermittlungsplattform ".app_config('app.name','Auftragsportal')." anzulegen. Die Plattform übernimmt die organisatorische Abwicklung der Angebote und Aufträge.\n\n"
        ."Einladungslink:\n".$link."\n\n"
        ."Der Link ist einmalig verwendbar und gültig bis ".date('d.m.Y H:i',strtotime($expiresAt))." Uhr.\n\n"
        ."Falls du diese Einladung nicht erwartet hast, kannst du diese E-Mail ignorieren.";
    $headers=[
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: '.$fromName.' <'.$from.'>',
    ];
    return @mail($email,$subject,$body,implode("\r\n",$headers));
}
function pull_created_invitation(): ?array {
    $x=$_SESSION['_created_invitation']??null;
    unset($_SESSION['_created_invitation']);
    return is_array($x)?$x:null;
}

function offer_number(): string {
    $year = date('Y');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $key = 'offer_' . $year;
        $q = $pdo->prepare('SELECT next_value FROM sequences WHERE sequence_key=? FOR UPDATE');
        $q->execute([$key]);
        $row = $q->fetch();
        if (!$row) {
            $n = 1;
            $pdo->prepare('INSERT INTO sequences(sequence_key,next_value) VALUES(?,2)')->execute([$key]);
        } else {
            $n = (int)$row['next_value'];
            $pdo->prepare('UPDATE sequences SET next_value=? WHERE sequence_key=?')->execute([$n + 1, $key]);
        }
        $pdo->commit();
        return $year . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function offer_status_label(string $s): string {
    return match($s) {
        'draft' => 'Entwurf', 'sent' => 'Gesendet', 'accepted' => 'Angenommen',
        'active' => 'Aktiv', 'completed' => 'Abgeschlossen', 'cancelled' => 'Storniert', default => $s,
    };
}
function order_status_label(string $s): string {
    return match($s) {
        'precheck' => 'Vorabkontrolle', 'running' => 'Läuft', 'shipping' => 'Versand',
        'completed' => 'Abgeschlossen', 'cancelled' => 'Storniert', default => $s,
    };
}
function day_status_label(string $s): string {
    return match($s) {
        'planned' => 'Offen', 'submitted' => 'Zur Prüfung', 'fulfilled' => 'Erfüllt', 'not_fulfilled' => 'Nicht erfüllt', default => $s,
    };
}

function order_day_date(?string $startedAt, int $dayNo): ?DateTimeImmutable {
    if (!$startedAt || $dayNo < 1) return null;
    try {
        $start = new DateTimeImmutable($startedAt);
        return $start->setTime(0, 0)->modify('+' . ($dayNo - 1) . ' days');
    } catch (Throwable) {
        return null;
    }
}
function date_de(?DateTimeInterface $date): string {
    if (!$date) return '–';
    static $days = ['So','Mo','Di','Mi','Do','Fr','Sa'];
    return $days[(int)$date->format('w')] . ', ' . $date->format('d.m.Y');
}

function offer_final_date(int $offerId): ?DateTimeImmutable {
    $q=db()->prepare("SELECT o.id,o.started_at,o.required_success_days,COALESCE(MAX(d.day_no),0) max_day
        FROM orders o
        LEFT JOIN order_days d ON d.order_id=o.id
        WHERE o.offer_id=? AND o.is_final_day_position=0 AND o.required_success_days>1
          AND o.started_at IS NOT NULL AND o.status<>'cancelled'
        GROUP BY o.id,o.started_at,o.required_success_days");
    $q->execute([$offerId]);
    $latest=null;
    foreach($q->fetchAll() as $row){
        $dayNo=max((int)$row['required_success_days'],(int)$row['max_day']);
        $date=order_day_date((string)$row['started_at'],$dayNo);
        if($date && (!$latest || $date>$latest))$latest=$date;
    }
    return $latest;
}
function scheduled_order_day_date(array $order,int $dayNo): ?DateTimeImmutable {
    if(!empty($order['is_final_day_position']) && !empty($order['offer_id'])){
        $final=offer_final_date((int)$order['offer_id']);
        if($final)return $final->modify('+'.max(0,$dayNo-1).' days');
    }
    return order_day_date($order['started_at']??null,$dayNo);
}
function final_day_positions_ready_for_review(int $offerId): bool {
    $q=db()->prepare("SELECT COUNT(*) FROM orders
        WHERE offer_id=? AND is_final_day_position=0 AND required_success_days>1
          AND status NOT IN('shipping','completed','cancelled')");
    $q->execute([$offerId]);
    return (int)$q->fetchColumn()===0;
}
function sync_final_day_positions(int $offerId): void {
    $final=offer_final_date($offerId);
    if(!$final)return;
    $finalDate=$final->format('Y-m-d');

    $q=db()->prepare("SELECT * FROM orders
        WHERE offer_id=? AND is_final_day_position=1 AND status IN('running','shipping','completed')");
    $q->execute([$offerId]);

    foreach($q->fetchAll() as $o){
        $current=$o['started_at']?substr((string)$o['started_at'],0,10):null;
        if($current===$finalDate)continue;

        $paths=db()->prepare("SELECT u.file_path FROM day_uploads u
            JOIN order_days d ON d.id=u.day_id WHERE d.order_id=?");
        $paths->execute([$o['id']]);
        foreach($paths->fetchAll(PDO::FETCH_COLUMN) as $rel){
            $rel=ltrim((string)$rel,'/');
            if($rel!==''&&!str_contains($rel,'..')&&!str_contains($rel,"\0")){
                $file=APP_ROOT.'/storage/private/'.$rel;
                if(is_file($file))@unlink($file);
            }
        }

        db()->prepare("DELETE u FROM day_uploads u JOIN order_days d ON d.id=u.day_id WHERE d.order_id=?")->execute([$o['id']]);
        db()->prepare("DELETE FROM order_days WHERE order_id=? AND day_no>1")->execute([$o['id']]);
        db()->prepare("UPDATE order_day_events e JOIN order_days d ON d.id=e.day_id
            SET e.status='planned',e.seller_note=NULL,e.submitted_at=NULL,e.updated_at=NOW()
            WHERE d.order_id=?")->execute([$o['id']]);
        db()->prepare("UPDATE order_days SET status='planned',seller_note=NULL,admin_note=NULL,
            submitted_at=NULL,reviewed_at=NULL,reviewed_by=NULL,updated_at=NOW()
            WHERE order_id=?")->execute([$o['id']]);
        db()->prepare("DELETE FROM order_shipments WHERE order_id=?")->execute([$o['id']]);
        db()->prepare("UPDATE seller_wallet_entries SET status='reserved',available_at=NULL,updated_at=NOW()
            WHERE order_id=? AND status='available'")->execute([$o['id']]);
        db()->prepare("UPDATE orders SET status='running',started_at=?,successful_days=0,extension_days=0,
            completed_at=NULL,updated_at=NOW() WHERE id=?")
            ->execute([$finalDate.' 00:00:00',$o['id']]);
        log_event($offerId,(int)$o['id'],'final_day.rescheduled',['scheduled_date'=>$finalDate]);
    }
}
function latest_precheck_rejection(int $orderId): ?string {
    $q = db()->prepare("SELECT payload_json FROM activity_log WHERE order_id=? AND event_type='precheck.rejected' ORDER BY id DESC LIMIT 1");
    $q->execute([$orderId]);
    $raw = $q->fetchColumn();
    if (!$raw) return null;
    $payload = json_decode((string)$raw, true);
    $reason = is_array($payload) ? trim((string)($payload['reason'] ?? '')) : '';
    return $reason !== '' ? $reason : null;
}

function app_setting(string $key, ?string $default=null): ?string {
    $q=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
    $q->execute([$key]);
    $v=$q->fetchColumn();
    return $v===false?$default:(string)$v;
}
function set_app_setting(string $key, ?string $value): void {
    db()->prepare('INSERT INTO app_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()')
        ->execute([$key,$value]);
}
function shipping_address(): array {
    return [
        'keyword'=>app_setting('shipping.keyword','Suzuki2026')??'Suzuki2026',
        'name'=>app_setting('shipping.name','Postlagernd')??'Postlagernd',
        'street'=>app_setting('shipping.street','Hauptstraße 16')??'Hauptstraße 16',
        'postal_code'=>app_setting('shipping.postal_code','35435')??'35435',
        'city'=>app_setting('shipping.city','Wettenberg')??'Wettenberg',
        'country'=>app_setting('shipping.country','Deutschland')??'Deutschland',
        'extra'=>app_setting('shipping.extra','Post Filiale 550')??'Post Filiale 550',
    ];
}
function shipping_address_complete(array $a): bool {
    return trim((string)($a['keyword']??''))!=='' && trim((string)($a['name']??''))!=='' &&
        trim((string)($a['street']??''))!=='' && trim((string)($a['postal_code']??''))!=='' &&
        trim((string)($a['city']??''))!=='';
}

function daily_event_labels(int $count): array {
    return array_column(default_event_templates($count),'label');
}
function default_event_templates(int $count): array {
    $count=max(1,$count);
    return match($count){
        1=>[['label'=>'Ganztags','start'=>'00:00','end'=>'23:59','all_day'=>1]],
        2=>[
            ['label'=>'Morgens','start'=>'06:00','end'=>'12:00','all_day'=>0],
            ['label'=>'Abends','start'=>'18:00','end'=>'22:00','all_day'=>0],
        ],
        3=>[
            ['label'=>'Morgens','start'=>'06:00','end'=>'12:00','all_day'=>0],
            ['label'=>'Mittags','start'=>'13:00','end'=>'16:00','all_day'=>0],
            ['label'=>'Abends','start'=>'18:00','end'=>'22:00','all_day'=>0],
        ],
        4=>[
            ['label'=>'Morgens','start'=>'06:00','end'=>'10:00','all_day'=>0],
            ['label'=>'Mittags','start'=>'11:00','end'=>'14:00','all_day'=>0],
            ['label'=>'Nachmittags','start'=>'15:00','end'=>'17:00','all_day'=>0],
            ['label'=>'Abends','start'=>'18:00','end'=>'22:00','all_day'=>0],
        ],
        default=>array_map(static fn(int $n):array=>['label'=>'Nachweis '.$n,'start'=>'00:00','end'=>'23:59','all_day'=>1],range(1,$count)),
    };
}
function event_templates(?string $json,int $count): array {
    $decoded=$json?json_decode($json,true):null;
    if(!is_array($decoded)||count($decoded)!==$count)return default_event_templates($count);
    $out=[];
    foreach(array_values($decoded) as $i=>$x){
        if(!is_array($x))return default_event_templates($count);
        $all=!empty($x['all_day']);
        $label=trim((string)($x['label']??''))?:('Nachweis '.($i+1));
        $start=(string)($x['start']??'00:00');
        $end=(string)($x['end']??'23:59');
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end))return default_event_templates($count);
        if(!$all && $start>=$end)return default_event_templates($count);
        $out[]=['label'=>$label,'start'=>$all?'00:00':$start,'end'=>$all?'23:59':$end,'all_day'=>$all?1:0];
    }
    return $out;
}
function posted_event_templates(int $count): array {
    $labels=$_POST['event_label']??[];
    $starts=$_POST['event_start']??[];
    $ends=$_POST['event_end']??[];
    $all=$_POST['event_all_day']??[];
    if(!is_array($labels)||count($labels)!==$count)return default_event_templates($count);
    $out=[];
    for($i=0;$i<$count;$i++){
        $isAll=is_array($all)&&isset($all[$i])&&(string)$all[$i]==='1';
        $label=trim((string)($labels[$i]??''))?:('Nachweis '.($i+1));
        $start=trim((string)($starts[$i]??'00:00'));
        $end=trim((string)($ends[$i]??'23:59'));
        if($isAll){$start='00:00';$end='23:59';}
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)||(!$isAll&&$start>=$end)){
            throw new RuntimeException('Ungültiges Zeitfenster bei „'.$label.'“.');
        }
        $out[]=['label'=>$label,'start'=>$start,'end'=>$end,'all_day'=>$isAll?1:0];
    }
    return $out;
}
function event_templates_json(array $templates): string {
    return (string)json_encode($templates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function event_window_text(array $event): string {
    if(!empty($event['all_day']))return 'Ganztags · 00:00–24:00';
    return substr((string)($event['window_start']??$event['start']??''),0,5).'–'.substr((string)($event['window_end']??$event['end']??''),0,5).' Uhr';
}
function ensure_day_events(int $dayId, int $count): array {
    $q=db()->prepare('SELECT * FROM order_day_events WHERE day_id=? ORDER BY event_no');
    $q->execute([$dayId]);$events=$q->fetchAll();
    if($events)return $events;

    $q=db()->prepare('SELECT o.daily_event_windows_json FROM order_days d JOIN orders o ON o.id=d.order_id WHERE d.id=?');
    $q->execute([$dayId]);$json=$q->fetchColumn();
    $templates=event_templates($json===false?null:(string)$json,$count);
    $ins=db()->prepare("INSERT INTO order_day_events(day_id,event_no,label,window_start,window_end,all_day,status) VALUES(?,?,?,?,?,?,'planned')");
    foreach($templates as $i=>$t)$ins->execute([$dayId,$i+1,$t['label'],$t['start'].':00',$t['end'].':00',$t['all_day']]);
    $q=db()->prepare('SELECT * FROM order_day_events WHERE day_id=? ORDER BY event_no');$q->execute([$dayId]);
    return $q->fetchAll();
}
function day_events(int $dayId): array {
    $q=db()->prepare('SELECT * FROM order_day_events WHERE day_id=? ORDER BY event_no');$q->execute([$dayId]);return $q->fetchAll();
}
function event_window_state(array $event, ?DateTimeImmutable $date, ?DateTimeImmutable $now=null): string {
    if(($event['status']??'')==='submitted')return 'submitted';
    if(!$date)return 'future';
    $now=$now??new DateTimeImmutable('now');
    $day=$date->format('Y-m-d');
    if(!empty($event['all_day'])){
        $start=new DateTimeImmutable($day.' 00:00:00');
        $end=new DateTimeImmutable($day.' 23:59:59');
    }else{
        $start=new DateTimeImmutable($day.' '.substr((string)$event['window_start'],0,5).':00');
        $end=new DateTimeImmutable($day.' '.substr((string)$event['window_end'],0,5).':59');
    }
    if($now<$start)return 'future';
    if($now>$end)return 'closed';
    return 'open';
}
function day_is_missed(array $day,array $order): bool {
    if(($day['status']??'')!=='planned')return false;
    $date=scheduled_order_day_date($order,(int)$day['day_no']);
    if(!$date)return false;
    $events=day_events((int)$day['id']);
    if(!$events)$events=ensure_day_events((int)$day['id'],(int)$day['required_photo_count']);
    foreach($events as $ev)if(event_window_state($ev,$date)==='closed' && ($ev['status']??'')!=='submitted')return true;
    return false;
}
function wallet_status_label(string $s): string {
    return match($s){'reserved'=>'Vorgemerkt','available'=>'Auszahlbar','paid'=>'Ausgezahlt','cancelled'=>'Storniert',default=>$s};
}
function payout_method_label(?string $s): string {
    return match($s){'paypal'=>'PayPal','bank'=>'Banküberweisung',default=>'Nicht festgelegt'};
}
function wallet_summary(int $sellerId): array {
    $q=db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN status='reserved' THEN amount ELSE 0 END),0) reserved,
        COALESCE(SUM(CASE WHEN status='available' THEN amount ELSE 0 END),0) available,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid
        FROM seller_wallet_entries WHERE seller_id=?");
    $q->execute([$sellerId]);return $q->fetch()?:['reserved'=>0,'available'=>0,'paid'=>0];
}
function payout_profile(int $sellerId): array {
    $q=db()->prepare('SELECT * FROM seller_payout_profiles WHERE seller_id=?');$q->execute([$sellerId]);
    return $q->fetch()?:['seller_id'=>$sellerId,'payout_method'=>null,'paypal_email'=>null,'bank_holder'=>null,'bank_iban'=>null,'bank_bic'=>null];
}
function create_wallet_entry(int $sellerId,int $orderId,float $amount): void {
    db()->prepare("INSERT IGNORE INTO seller_wallet_entries(seller_id,order_id,amount,status) VALUES(?,?,?,'reserved')")
        ->execute([$sellerId,$orderId,max(0,$amount)]);
}
function shipment_for_order(int $orderId): ?array {
    $q=db()->prepare('SELECT * FROM order_shipments WHERE order_id=?');$q->execute([$orderId]);$x=$q->fetch();return $x?:null;
}
function create_shipping_phase(array $order): void {
    $orderId=(int)$order['id'];
    if(shipment_for_order($orderId))return;
    $q=db()->prepare("SELECT MAX(day_no) FROM order_days WHERE order_id=? AND status='fulfilled'");
    $q->execute([$orderId]);$lastDay=(int)$q->fetchColumn();
    $lastDate=scheduled_order_day_date($order,$lastDay)??new DateTimeImmutable('today');
    $due=$lastDate->modify('+1 day')->format('Y-m-d');
    $a=shipping_address();
    db()->prepare("INSERT INTO order_shipments(order_id,due_date,address_keyword,address_name,street,postal_code,city,country,extra,status)
        VALUES(?,?,?,?,?,?,?,?,?,'pending')")
        ->execute([$orderId,$due,$a['keyword']?:null,$a['name']?:null,$a['street']?:null,$a['postal_code']?:null,$a['city']?:null,$a['country']?:null,$a['extra']?:null]);
    db()->prepare("UPDATE orders SET status='shipping',updated_at=NOW() WHERE id=?")->execute([$orderId]);
    log_event((int)$order['offer_id'],$orderId,'order.shipping',['due_date'=>$due]);
}
function save_image_upload(array $file, string $folder): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Foto-Upload fehlgeschlagen.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!$tmp || !is_uploaded_file($tmp)) throw new RuntimeException('Ungültiger Upload.');
    if ((int)($file['size'] ?? 0) > 12 * 1024 * 1024) throw new RuntimeException('Ein Foto darf maximal 12 MB groß sein.');
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('Erlaubt sind JPG, PNG und WEBP.');
    $safeFolder = trim(preg_replace('#[^a-zA-Z0-9/_-]#', '', $folder) ?? '', '/');
    $dir = APP_ROOT . '/storage/private/' . $safeFolder;
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) throw new RuntimeException('Upload-Ordner konnte nicht erstellt werden.');
    $name = bin2hex(random_bytes(24)) . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!move_uploaded_file($tmp, $target)) throw new RuntimeException('Foto konnte nicht gespeichert werden.');
    return ['path'=>$safeFolder . '/' . $name,'mime'=>$mime,'size'=>(int)$file['size'],'sha256'=>hash_file('sha256',$target)];
}
function normalized_uploads(string $key): array {
    $f = $_FILES[$key] ?? null;
    if (!$f) return [];
    if (!is_array($f['name'] ?? null)) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = [
            'name'=>$name,'type'=>$f['type'][$i] ?? '','tmp_name'=>$f['tmp_name'][$i] ?? '',
            'error'=>$f['error'][$i] ?? UPLOAD_ERR_NO_FILE,'size'=>$f['size'][$i] ?? 0,
        ];
    }
    return $out;
}

function log_event(?int $offerId, ?int $orderId, string $event, array $payload = []): void {
    $u = current_user();
    db()->prepare('INSERT INTO activity_log(actor_role,actor_id,offer_id,order_id,event_type,payload_json) VALUES(?,?,?,?,?,?)')
      ->execute([$u['role'] ?? 'system', $u['id'] ?? null, $offerId, $orderId, $event, $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null]);
}

function sync_order_progress(int $orderId): void {
    $q=db()->prepare("SELECT COUNT(*) FROM order_days WHERE order_id=? AND status='fulfilled'");
    $q->execute([$orderId]);$ok=(int)$q->fetchColumn();
    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$orderId]);$o=$q->fetch();if(!$o)return;
    db()->prepare('UPDATE orders SET successful_days=?,updated_at=NOW() WHERE id=?')->execute([$ok,$orderId]);
    $o['successful_days']=$ok;
    if($ok>=(int)$o['required_success_days'] && $o['status']==='running')create_shipping_phase($o);
    sync_offer_status((int)$o['offer_id']);
}
function sync_offer_status(int $offerId): void {
    $q=db()->prepare("SELECT status FROM offers WHERE id=?");$q->execute([$offerId]);$current=(string)$q->fetchColumn();
    if(in_array($current,['draft','sent','cancelled'],true))return;
    $q=db()->prepare("SELECT COUNT(*) total,
        SUM(status='running') running_count,
        SUM(status='shipping') shipping_count,
        SUM(status='completed') completed_count
        FROM orders WHERE offer_id=?");
    $q->execute([$offerId]);$x=$q->fetch()?:[];
    $total=(int)($x['total']??0);$active=(int)($x['running_count']??0)+(int)($x['shipping_count']??0);$completed=(int)($x['completed_count']??0);
    if($total>0 && $completed===$total){
        db()->prepare("UPDATE offers SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$offerId]);
    }elseif($active>0){
        db()->prepare("UPDATE offers SET status='active',updated_at=NOW() WHERE id=?")->execute([$offerId]);
    }else{
        db()->prepare("UPDATE offers SET status='accepted',updated_at=NOW() WHERE id=?")->execute([$offerId]);
    }
}

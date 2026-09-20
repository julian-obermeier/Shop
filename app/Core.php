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
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        $c['port'] ?? 3306,
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string {
    return rtrim((string)app_config('app.url', ''), '/') . '/' . ltrim($path, '/');
}

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
    return (string)$_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">';
}

function csrf_verify(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('Ungültige oder abgelaufene Anfrage.');
    }
}

function flash(string $type, string $text): void {
    $_SESSION['_flash'][] = [$type, $text];
}

function pull_flashes(): array {
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($flashes) ? $flashes : [];
}

function redirect(string $path): never {
    header('Location: '.url($path));
    exit;
}

function seller(): ?array {
    $id = $_SESSION['seller_id'] ?? null;
    if (!$id) return null;
    $q = db()->prepare('SELECT * FROM sellers WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $q->execute([(int)$id]);
    return $q->fetch() ?: null;
}

function admin(): ?array {
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) return null;
    $q = db()->prepare('SELECT * FROM admins WHERE id=? LIMIT 1');
    $q->execute([(int)$id]);
    return $q->fetch() ?: null;
}

function require_seller(): array {
    $s = seller();
    if (!$s) redirect('/login');
    return $s;
}

function require_admin(): array {
    $a = admin();
    if (!$a) redirect('/admin/login');
    return $a;
}

function render(string $title, string $content, array $data = []): void {
    $seller = seller();
    $admin = admin();
    $flashes = pull_flashes();
    extract($data, EXTR_SKIP);
    require __DIR__.'/View.php';
}

function post(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function send_app_mail(string $to, string $subject, string $html): bool {
    $from = (string)app_config('mail.from', 'noreply@localhost');
    $name = (string)app_config('mail.name', app_config('app.name', 'Ankaufsplattform'));
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
    $pdo = db();
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT next_value FROM number_sequences WHERE sequence_key=? FOR UPDATE');
        $q->execute(['order_'.$year]);
        $row = $q->fetch();
        if (!$row) {
            $pdo->prepare('INSERT INTO number_sequences(sequence_key,next_value) VALUES(?,2)')
                ->execute(['order_'.$year]);
            $n = 1;
        } else {
            $n = (int)$row['next_value'];
            $pdo->prepare('UPDATE number_sequences SET next_value=? WHERE sequence_key=?')
                ->execute([$n + 1, 'order_'.$year]);
        }
        if ($owns) $pdo->commit();
        return $year.str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function money(float|int|string $value): string {
    return number_format((float)$value, 2, ',', '.').' €';
}

function setting(string $key, mixed $default = null): mixed {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $q = db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $q->execute([$key]);
        $value = $q->fetchColumn();
        return $cache[$key] = ($value === false ? $default : $value);
    } catch (Throwable) {
        return $default;
    }
}

function setting_value(string $key, mixed $default = null): mixed {
    return setting($key, $default);
}

function private_upload(array $file, string $folder): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload fehlgeschlagen.');
    }

    $maxMb = max(1, (int)setting('upload_max_mb', 50));
    $maxBytes = $maxMb * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Datei ist zu groß. Maximal '.$maxMb.' MB erlaubt.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];
    if (!isset($allowed[$mime])) throw new RuntimeException('Dateiformat ist nicht erlaubt.');

    $baseRoot = realpath(__DIR__.'/../storage/private') ?: __DIR__.'/../storage/private';
    $safeFolder = trim(str_replace(['..', '\\'], ['', '/'], $folder), '/');
    $base = $baseRoot.'/'.$safeFolder;
    if (!is_dir($base) && !mkdir($base, 0770, true) && !is_dir($base)) {
        throw new RuntimeException('Speicherordner nicht verfügbar.');
    }

    $name = bin2hex(random_bytes(24)).'.'.$allowed[$mime];
    $target = $base.'/'.$name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Datei konnte nicht gespeichert werden.');
    }

    return [
        'path' => $safeFolder.'/'.$name,
        'mime' => $mime,
        'size' => (int)$file['size'],
        'sha256' => (string)hash_file('sha256', $target),
    ];
}

function current_run_id(int $orderId): ?int {
    $q = db()->prepare('SELECT id FROM order_runs WHERE order_id=? ORDER BY run_no DESC LIMIT 1');
    $q->execute([$orderId]);
    $id = $q->fetchColumn();
    return $id === false ? null : (int)$id;
}

function offer_evidence_rules(array|int $offerOrOrderId): array {
    if (is_int($offerOrOrderId)) {
        $q = db()->prepare('SELECT f.evidence_rules_json FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.id=?');
        $q->execute([$offerOrOrderId]);
        $raw = $q->fetchColumn();
    } else {
        $raw = $offerOrOrderId['evidence_rules_json'] ?? null;
    }

    $rules = [];
    if ($raw) {
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) $rules = $decoded;
    }

    $precheck = (int)($rules['precheck_required_count'] ?? $rules['precheck_required'] ?? 1);
    $daily = is_array($rules['daily'] ?? null) ? $rules['daily'] : [];

    return [
        'precheck_required_count' => max(1, $precheck),
        'daily' => [
            'morning' => max(0, (int)($daily['morning'] ?? 1)),
            'midday' => max(0, (int)($daily['midday'] ?? 1)),
            'evening' => max(0, (int)($daily['evening'] ?? 1)),
        ],
    ];
}

function parse_window_setting(string $key, string $fallback): array {
    $value = (string)setting($key, $fallback);
    $parts = array_map('trim', explode('-', $value, 2));
    if (
        count($parts) !== 2 ||
        !preg_match('/^\d{2}:\d{2}$/', $parts[0]) ||
        !preg_match('/^\d{2}:\d{2}$/', $parts[1])
    ) {
        $parts = explode('-', $fallback, 2);
    }
    return $parts;
}

function schedule_order_days(int $orderId, ?DateTimeImmutable $startedAt = null): void {
    $q = db()->prepare('SELECT o.duration_days FROM orders o WHERE o.id=?');
    $q->execute([$orderId]);
    $duration = (int)$q->fetchColumn();
    if ($duration < 1) return;

    $runId = current_run_id($orderId);
    if (!$runId) return;

    $exists = db()->prepare('SELECT COUNT(*) FROM order_days WHERE order_id=? AND order_run_id=?');
    $exists->execute([$orderId, $runId]);
    if ((int)$exists->fetchColumn() > 0) return;

    $rules = offer_evidence_rules($orderId);
    $defs = [
        'morning' => parse_window_setting('window_morning', '06:00-10:00'),
        'midday' => parse_window_setting('window_midday', '12:00-16:00'),
        'evening' => parse_window_setting('window_evening', '18:00-23:59'),
    ];

    $active = [];
    foreach ($defs as $key => $range) {
        $count = (int)$rules['daily'][$key];
        if ($count > 0) $active[$key] = ['range' => $range, 'count' => $count];
    }

    $tz = new DateTimeZone((string)app_config('app.timezone', 'Europe/Berlin'));
    $startedAt = ($startedAt ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
    $sameDate = $startedAt->format('Y-m-d');
    $futureSameDay = [];

    foreach ($active as $key => $def) {
        $windowStart = new DateTimeImmutable($sameDate.' '.$def['range'][0].':00', $tz);
        if ($windowStart > $startedAt) $futureSameDay[$key] = $def;
    }

    if (!$active) {
        $firstDate = new DateTimeImmutable($sameDate.' 00:00:00', $tz);
    } else {
        $firstDate = $futureSameDay
            ? new DateTimeImmutable($sameDate.' 00:00:00', $tz)
            : (new DateTimeImmutable($sameDate.' 00:00:00', $tz))->modify('+1 day');
    }

    $grace = max(0, (int)setting('grace_minutes', 60));
    $dayInsert = db()->prepare(
        "INSERT INTO order_days(order_id,order_run_id,day_no,day_type,calendar_date,status)
         VALUES(?,?,?,'regular',?,'planned')"
    );
    $windowInsert = db()->prepare(
        "INSERT INTO evidence_windows(order_id,order_run_id,day_no,window_key,starts_at,ends_at,grace_ends_at,required_count,status)
         VALUES(?,?,?,?,?,?,?,?,?)"
    );

    for ($day = 1; $day <= $duration; $day++) {
        $date = $firstDate->modify('+'.($day - 1).' day');
        $dayInsert->execute([$orderId, $runId, $day, $date->format('Y-m-d')]);

        $use = ($day === 1 && $firstDate->format('Y-m-d') === $sameDate)
            ? $futureSameDay
            : $active;

        foreach ($use as $key => $def) {
            $range = $def['range'];
            $start = new DateTimeImmutable($date->format('Y-m-d').' '.$range[0].':00', $tz);
            $end = new DateTimeImmutable($date->format('Y-m-d').' '.$range[1].':00', $tz);
            $graceEnd = $end->modify('+'.$grace.' minutes');
            $status = $start <= new DateTimeImmutable('now', $tz) ? 'open' : 'planned';
            $windowInsert->execute([
                $orderId,
                $runId,
                $day,
                $key,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $graceEnd->format('Y-m-d H:i:s'),
                $def['count'],
                $status,
            ]);
        }
    }
}

function create_order_schedule(int $orderId): void {
    schedule_order_days($orderId);
}

function try_start_order_after_precheck(int $orderId): bool {
    $q = db()->prepare(
        "SELECT o.status,o.seller_id,o.order_no,o.offer_id,f.evidence_rules_json
         FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.id=?"
    );
    $q->execute([$orderId]);
    $order = $q->fetch();
    if (!$order || $order['status'] !== 'precheck') return false;

    $rules = offer_evidence_rules($order);
    $required = (int)$rules['precheck_required_count'];
    $q = db()->prepare(
        "SELECT COUNT(*) total,
                SUM(status='accepted') accepted_count,
                SUM(status='submitted') pending_count,
                SUM(status='rejected') rejected_count
         FROM evidences WHERE order_id=? AND evidence_type='precheck'"
    );
    $q->execute([$orderId]);
    $stats = $q->fetch() ?: [];
    if (
        (int)($stats['total'] ?? 0) < $required ||
        (int)($stats['accepted_count'] ?? 0) < $required ||
        (int)($stats['pending_count'] ?? 0) > 0 ||
        (int)($stats['rejected_count'] ?? 0) > 0
    ) return false;

    $started = new DateTimeImmutable('now', new DateTimeZone((string)app_config('app.timezone', 'Europe/Berlin')));
    db()->prepare("UPDATE orders SET status='running',started_at=?,updated_at=NOW() WHERE id=? AND status='precheck'")
        ->execute([$started->format('Y-m-d H:i:s'), $orderId]);
    db()->prepare("UPDATE order_runs SET status='running',started_at=COALESCE(started_at,?) WHERE id=?")
        ->execute([$started->format('Y-m-d H:i:s'), current_run_id($orderId)]);
    schedule_order_days($orderId, $started);
    schedule_existing_extra_days($orderId);
    return true;
}

function append_order_day(int $orderId, string $dayType, ?string $sourceRef = null): ?int {
    $runId = current_run_id($orderId);
    if (!$runId) return null;

    $q = db()->prepare(
        'SELECT MAX(day_no) max_day,MAX(calendar_date) max_date
         FROM order_days WHERE order_id=? AND order_run_id=?'
    );
    $q->execute([$orderId, $runId]);
    $last = $q->fetch() ?: [];

    $dayNo = max(1, (int)($last['max_day'] ?? 0) + 1);
    $tz = new DateTimeZone((string)app_config('app.timezone', 'Europe/Berlin'));
    $date = !empty($last['max_date'])
        ? (new DateTimeImmutable($last['max_date'].' 00:00:00', $tz))->modify('+1 day')
        : new DateTimeImmutable('tomorrow 00:00:00', $tz);

    db()->prepare(
        "INSERT INTO order_days(order_id,order_run_id,day_no,day_type,calendar_date,status,source_ref)
         VALUES(?,?,?,?,?,'planned',?)"
    )->execute([$orderId, $runId, $dayNo, $dayType, $date->format('Y-m-d'), $sourceRef]);

    $rules = offer_evidence_rules($orderId);
    $defs = [
        'morning' => parse_window_setting('window_morning', '06:00-10:00'),
        'midday' => parse_window_setting('window_midday', '12:00-16:00'),
        'evening' => parse_window_setting('window_evening', '18:00-23:59'),
    ];
    $grace = max(0, (int)setting('grace_minutes', 60));
    $insert = db()->prepare(
        "INSERT INTO evidence_windows(order_id,order_run_id,day_no,window_key,starts_at,ends_at,grace_ends_at,required_count,status)
         VALUES(?,?,?,?,?,?,?,?, 'planned')"
    );

    foreach ($defs as $key => $range) {
        $count = (int)$rules['daily'][$key];
        if ($count < 1) continue;
        $start = new DateTimeImmutable($date->format('Y-m-d').' '.$range[0].':00', $tz);
        $end = new DateTimeImmutable($date->format('Y-m-d').' '.$range[1].':00', $tz);
        $insert->execute([
            $orderId,
            $runId,
            $dayNo,
            $key,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            $end->modify('+'.$grace.' minutes')->format('Y-m-d H:i:s'),
            $count,
        ]);
    }

    return $dayNo;
}

function schedule_extra_day(int $extraDayId, ?int $runId = null): void {
    $q = db()->prepare('SELECT * FROM extra_days WHERE id=?');
    $q->execute([$extraDayId]);
    $extra = $q->fetch();
    if (!$extra || ($extra['status'] ?? 'confirmed') === 'removed') return;

    $orderId = (int)$extra['order_id'];
    $runId = $runId ?: current_run_id($orderId);
    if (!$runId) return;

    $sourceRef = 'extra:'.$extraDayId;
    $exists = db()->prepare(
        'SELECT COUNT(*) FROM order_days WHERE order_id=? AND order_run_id=? AND source_ref=?'
    );
    $exists->execute([$orderId, $runId, $sourceRef]);
    if ((int)$exists->fetchColumn() > 0) return;

    append_order_day($orderId, (string)$extra['source_type'], $sourceRef);
}

function schedule_existing_extra_days(int $orderId): void {
    $runId = current_run_id($orderId);
    if (!$runId) return;

    try {
        $q = db()->prepare("SELECT id FROM extra_days WHERE order_id=? AND status<>'removed' ORDER BY created_at,id");
        $q->execute([$orderId]);
    } catch (PDOException) {
        $q = db()->prepare('SELECT id FROM extra_days WHERE order_id=? ORDER BY created_at,id');
        $q->execute([$orderId]);
    }

    foreach ($q->fetchAll() as $row) {
        schedule_extra_day((int)$row['id'], $runId);
    }
}

function notify_seller(
    int $sellerId,
    string $type,
    string $title,
    string $body,
    ?string $link = null,
    ?string $dedupeKey = null,
    bool $email = false
): void {
    try {
        $q = db()->prepare(
            'INSERT INTO notifications(seller_id,notification_type,title,body,link,dedupe_key)
             VALUES(?,?,?,?,?,?)'
        );
        $q->execute([$sellerId, $type, $title, $body, $link, $dedupeKey]);
    } catch (PDOException $e) {
        if ($dedupeKey !== null && (int)($e->errorInfo[1] ?? 0) === 1062) return;
        try {
            $q = db()->prepare(
                'INSERT INTO notifications(seller_id,notification_type,title,body,link)
                 VALUES(?,?,?,?,?)'
            );
            $q->execute([$sellerId, $type, $title, $body, $link]);
        } catch (Throwable) {
            // Notifications must not break the business transaction.
        }
    }

    if ($email) {
        $q = db()->prepare('SELECT email FROM sellers WHERE id=? AND deleted_at IS NULL');
        $q->execute([$sellerId]);
        $to = $q->fetchColumn();
        if ($to) {
            $linkHtml = $link ? '<p><a href="'.e(url($link)).'">In der Plattform öffnen</a></p>' : '';
            send_app_mail((string)$to, $title, '<p>'.e($body).'</p>'.$linkHtml);
        }
    }
}

function log_event(string $type, ?int $sellerId = null, ?int $orderId = null, array $payload = []): void {
    db()->prepare(
        'INSERT INTO system_events(seller_id,order_id,event_type,payload_json) VALUES(?,?,?,?)'
    )->execute([
        $sellerId,
        $orderId,
        $type,
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
    ]);
}


function reject_if_archived_route(string $path, string $method): void {
    if($method!=='POST') return;
    $orderId=null;$orderNo=null;

    if(preg_match('#/auftrag/(\d{8})(?:/|$)#',$path,$m)){
        if(str_ends_with($path,'/wiederherstellen')) return;
        $orderNo=$m[1];
    }elseif(preg_match('#^/admin/nachweis/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.id,o.order_no,o.archived_at FROM evidences e JOIN orders o ON o.id=e.order_id WHERE e.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/verstoss/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM violations v JOIN orders o ON o.id=v.order_id WHERE v.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/aufgabe/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE t.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/spontan/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM spontaneous_requests r JOIN orders o ON o.id=r.order_id WHERE r.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/beschaedigung/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM damage_cases d JOIN orders o ON o.id=d.order_id WHERE d.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }

    if($orderNo!==null){
        $q=db()->prepare('SELECT archived_at FROM orders WHERE order_no=?');$q->execute([$orderNo]);$archived=$q->fetchColumn();
        if($archived){flash('error','Der archivierte Auftrag ist schreibgeschützt. Stelle ihn im Adminbereich zuerst wieder her.');redirect(str_starts_with($path,'/admin/')?'/admin/auftrag/'.$orderNo:'/auftrag/'.$orderNo);}
    }
}

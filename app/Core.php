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
        'precheck' => 'Vorabkontrolle', 'running' => 'Läuft', 'completed' => 'Abgeschlossen', 'cancelled' => 'Storniert', default => $s,
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
function latest_precheck_rejection(int $orderId): ?string {
    $q = db()->prepare("SELECT payload_json FROM activity_log WHERE order_id=? AND event_type='precheck.rejected' ORDER BY id DESC LIMIT 1");
    $q->execute([$orderId]);
    $raw = $q->fetchColumn();
    if (!$raw) return null;
    $payload = json_decode((string)$raw, true);
    $reason = is_array($payload) ? trim((string)($payload['reason'] ?? '')) : '';
    return $reason !== '' ? $reason : null;
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
    $q = db()->prepare("SELECT COUNT(*) FROM order_days WHERE order_id=? AND status='fulfilled'");
    $q->execute([$orderId]); $ok = (int)$q->fetchColumn();
    $q = db()->prepare('SELECT required_success_days,offer_id,status FROM orders WHERE id=?');
    $q->execute([$orderId]); $o = $q->fetch(); if (!$o) return;
    db()->prepare('UPDATE orders SET successful_days=?,updated_at=NOW() WHERE id=?')->execute([$ok,$orderId]);
    if ($ok >= (int)$o['required_success_days'] && $o['status'] === 'running') {
        db()->prepare("UPDATE orders SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$orderId]);
        log_event((int)$o['offer_id'],$orderId,'order.completed');
    }
    sync_offer_status((int)$o['offer_id']);
}
function sync_offer_status(int $offerId): void {
    $q = db()->prepare("SELECT status FROM offers WHERE id=?"); $q->execute([$offerId]); $current = (string)$q->fetchColumn();
    if (in_array($current,['draft','sent','cancelled'],true)) return;
    $q = db()->prepare("SELECT COUNT(*) total, SUM(status='running') running_count, SUM(status='completed') completed_count FROM orders WHERE offer_id=?");
    $q->execute([$offerId]); $x = $q->fetch() ?: [];
    $total=(int)($x['total']??0); $running=(int)($x['running_count']??0); $completed=(int)($x['completed_count']??0);
    if ($total > 0 && $completed === $total) {
        db()->prepare("UPDATE offers SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$offerId]);
    } elseif ($running > 0) {
        db()->prepare("UPDATE offers SET status='active',updated_at=NOW() WHERE id=?")->execute([$offerId]);
    } else {
        db()->prepare("UPDATE offers SET status='accepted',updated_at=NOW() WHERE id=?")->execute([$offerId]);
    }
}

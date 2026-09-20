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

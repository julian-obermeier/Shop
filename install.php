<?php
declare(strict_types=1);

const SHOP_REPOSITORY_ZIP = 'https://codeload.github.com/julian-obermeier/Shop/zip/refs/heads/main';
const SHOP_INSTALLER_VERSION = '1.0.0';

@set_time_limit(0);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
ignore_user_abort(true);

$scriptDir = __DIR__;
$baseDir = (basename($scriptDir) === 'public' && is_file(dirname($scriptDir).'/composer.json'))
    ? dirname($scriptDir)
    : $scriptDir;

$lockFile = $baseDir.'/.installed';
$tmpDir = $baseDir.'/.installer-tmp';

session_name('wear_earn_installer');
session_start();

// Bei einer Ein-Datei-Installation im Domain-Webroot wird /install automatisch
// als hübsche Installer-URL aktiviert. Eine vorhandene .htaccess wird niemals
// an dieser Stelle überschrieben.
if ($baseDir === $scriptDir && !is_file($baseDir.'/.htaccess')) {
    @file_put_contents(
        $baseDir.'/.htaccess',
        "Options -Indexes\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^install/?$ install.php [L,QSA]\n</IfModule>\n"
    );

    $requestPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (str_ends_with($requestPath, '/install.php')) {
        header('Location: /install');
        exit;
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function currentBaseUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http').'://'.$host;
}

function csrfToken(): string
{
    if (empty($_SESSION['installer_csrf'])) {
        $_SESSION['installer_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['installer_csrf'];
}

function verifyCsrf(): void
{
    $given = (string)($_POST['_token'] ?? '');
    if ($given === '' || !hash_equals(csrfToken(), $given)) {
        throw new RuntimeException('Die Installationssitzung ist abgelaufen. Bitte lade /install neu.');
    }
}

function canExec(): bool
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('exec') && !in_array('exec', $disabled, true);
}

function runCommand(string $command, string $cwd): array
{
    if (!canExec()) {
        throw new RuntimeException('Die PHP-Funktion exec() ist auf diesem Webspace deaktiviert.');
    }

    $output = [];
    $status = 0;
    exec('cd '.escapeshellarg($cwd).' && '.$command.' 2>&1', $output, $status);

    return [$status, trim(implode("\n", $output))];
}

function executable(string $name): ?string
{
    if (!canExec()) {
        return null;
    }

    $out = [];
    $status = 0;
    exec('command -v '.escapeshellarg($name).' 2>/dev/null', $out, $status);

    return ($status === 0 && !empty($out[0])) ? trim((string)$out[0]) : null;
}

function phpCli(): string
{
    foreach (['/usr/bin/php83', '/usr/bin/php84', '/usr/bin/php85', PHP_BINARY] as $candidate) {
        if ($candidate && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    $php = executable('php83') ?: executable('php');
    if ($php) {
        return $php;
    }

    throw new RuntimeException('Kein geeigneter PHP-CLI-Interpreter gefunden. Benötigt wird PHP 8.3 oder höher.');
}

function composerCli(): string
{
    foreach (array_filter([
        executable('composer'),
        '/usr/local/bin/composer',
        '/usr/bin/composer',
    ]) as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('Composer wurde nicht gefunden.');
}

function downloadFile(string $url, string $destination): void
{
    if (extension_loaded('curl')) {
        $fp = fopen($destination, 'wb');
        if (!$fp) {
            throw new RuntimeException('Temporäre Download-Datei kann nicht erstellt werden.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_USERAGENT => 'WearEarnInstaller/'.SHOP_INSTALLER_VERSION,
        ]);

        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$ok || $code >= 400) {
            @unlink($destination);
            throw new RuntimeException('Download von GitHub fehlgeschlagen'.($error ? ': '.$error : ' (HTTP '.$code.')'));
        }
        return;
    }

    if ((bool)ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 180,
                'follow_location' => 1,
                'user_agent' => 'WearEarnInstaller/'.SHOP_INSTALLER_VERSION,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false || strlen($data) < 1000) {
            throw new RuntimeException('Download von GitHub fehlgeschlagen.');
        }

        if (file_put_contents($destination, $data) === false) {
            throw new RuntimeException('Download konnte nicht gespeichert werden.');
        }
        return;
    }

    throw new RuntimeException('Weder cURL noch allow_url_fopen stehen für den Download zur Verfügung.');
}

function deleteTree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        deleteTree($path.DIRECTORY_SEPARATOR.$item);
    }

    @rmdir($path);
}

function copyTree(string $source, string $destination, array $skipTopLevel = []): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $top = explode(DIRECTORY_SEPARATOR, $relative)[0] ?? '';

        if (in_array($top, $skipTopLevel, true)) {
            continue;
        }

        $target = $destination.DIRECTORY_SEPARATOR.$relative;

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                throw new RuntimeException('Verzeichnis konnte nicht erstellt werden: '.$relative);
            }
            continue;
        }

        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Zielverzeichnis konnte nicht erstellt werden: '.dirname($relative));
        }

        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('Datei konnte nicht kopiert werden: '.$relative);
        }
    }
}

function extractSource(string $zipFile, string $destination): string
{
    deleteTree($destination);
    mkdir($destination, 0775, true);

    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('Das GitHub-Archiv konnte nicht geöffnet werden.');
        }
        if (!$zip->extractTo($destination)) {
            $zip->close();
            throw new RuntimeException('Das GitHub-Archiv konnte nicht entpackt werden.');
        }
        $zip->close();
    } else {
        $unzip = executable('unzip');
        if (!$unzip) {
            throw new RuntimeException('Weder ZipArchive noch unzip sind verfügbar.');
        }
        [$status, $output] = runCommand(
            escapeshellarg($unzip).' -q '.escapeshellarg($zipFile).' -d '.escapeshellarg($destination),
            __DIR__
        );
        if ($status !== 0) {
            throw new RuntimeException('Archiv konnte nicht entpackt werden: '.$output);
        }
    }

    $entries = array_values(array_filter(scandir($destination) ?: [], fn ($entry) => $entry !== '.' && $entry !== '..'));
    if (count($entries) !== 1 || !is_dir($destination.'/'.$entries[0])) {
        throw new RuntimeException('Unerwartete Struktur des GitHub-Archivs.');
    }

    return $destination.'/'.$entries[0];
}

function envQuote(string $value): string
{
    return '"'.str_replace(
        ["\\", '"', "\r", "\n"],
        ["\\\\", '\\"', '', '\n'],
        $value
    ).'"';
}

function existingEnvValue(string $path, string $key): ?string
{
    if (!is_file($path)) {
        return null;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!str_starts_with(trim($line), $key.'=')) {
            continue;
        }

        $value = trim(substr($line, strlen($key) + 1));
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = stripcslashes(substr($value, 1, -1));
        }
        return $value;
    }

    return null;
}

function writeEnvironment(string $baseDir, array $settings): void
{
    $envPath = $baseDir.'/.env';
    $existingKey = existingEnvValue($envPath, 'APP_KEY');
    $appKey = ($existingKey && str_starts_with($existingKey, 'base64:'))
        ? $existingKey
        : 'base64:'.base64_encode(random_bytes(32));

    if (is_file($envPath)) {
        $backupDir = $baseDir.'/.installer-backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }
        copy($envPath, $backupDir.'/.env.'.date('Ymd-His').'.bak');
    }

    $host = (string)parse_url($settings['app_url'], PHP_URL_HOST);
    $mailFrom = $settings['mail_from_address'] ?: 'no-reply@'.$host;

    $lines = [
        'APP_NAME='.envQuote('Wear&Earn'),
        'APP_ENV=production',
        'APP_KEY='.$appKey,
        'APP_DEBUG=false',
        'APP_URL='.envQuote($settings['app_url']),
        '',
        'APP_LOCALE=de',
        'APP_FALLBACK_LOCALE=de',
        'APP_TIMEZONE=Europe/Berlin',
        '',
        'LOG_CHANNEL=stack',
        'LOG_LEVEL=warning',
        '',
        'DB_CONNECTION=mysql',
        'DB_HOST='.envQuote($settings['db_host']),
        'DB_PORT='.(int)$settings['db_port'],
        'DB_DATABASE='.envQuote($settings['db_name']),
        'DB_USERNAME='.envQuote($settings['db_user']),
        'DB_PASSWORD='.envQuote($settings['db_password']),
        '',
        'SESSION_DRIVER=database',
        'SESSION_LIFETIME=120',
        'SESSION_ENCRYPT=true',
        'SESSION_SECURE_COOKIE=true',
        'SESSION_SAME_SITE=lax',
        '',
        'CACHE_STORE=database',
        'QUEUE_CONNECTION=database',
        '',
        'MAIL_MAILER='.($settings['mail_host'] ? 'smtp' : 'log'),
        'MAIL_HOST='.envQuote($settings['mail_host']),
        'MAIL_PORT='.(int)$settings['mail_port'],
        'MAIL_USERNAME='.envQuote($settings['mail_username']),
        'MAIL_PASSWORD='.envQuote($settings['mail_password']),
        'MAIL_ENCRYPTION='.envQuote($settings['mail_encryption']),
        'MAIL_FROM_ADDRESS='.envQuote($mailFrom),
        'MAIL_FROM_NAME='.envQuote('Wear&Earn'),
        '',
        'PUSH_VAPID_SUBJECT='.envQuote($settings['app_url']),
        'PUSH_VAPID_PUBLIC_KEY=',
        'PUSH_VAPID_PRIVATE_KEY=',
        '',
    ];

    if (file_put_contents($envPath, implode("\n", $lines)) === false) {
        throw new RuntimeException('.env konnte nicht geschrieben werden.');
    }

    @chmod($envPath, 0600);
}

function databaseConnection(array $settings): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $settings['db_host'],
        (int)$settings['db_port'],
        $settings['db_name']
    );

    return new PDO($dsn, $settings['db_user'], $settings['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

function createAdmin(PDO $pdo, array $settings): string
{
    $existing = $pdo->query("SELECT id, email, username FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();

    if ($existing) {
        return 'Vorhandenes Admin-Konto beibehalten: '.($existing['username'] ?: $existing['email']);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO users
        (role, username, first_name, last_name, birth_date, email, email_verified_at, password, status, created_at, updated_at)
        VALUES
        ('admin', :username, :first_name, :last_name, '1970-01-01', :email, NOW(), :password, 'active', NOW(), NOW())"
    );

    $stmt->execute([
        ':username' => $settings['admin_username'],
        ':first_name' => $settings['admin_first_name'],
        ':last_name' => $settings['admin_last_name'],
        ':email' => $settings['admin_email'],
        ':password' => password_hash($settings['admin_password'], PASSWORD_BCRYPT),
    ]);

    return 'Admin-Konto angelegt: '.$settings['admin_username'];
}

function ensureDirectories(string $baseDir): void
{
    foreach ([
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
        'bootstrap/cache',
    ] as $relative) {
        $path = $baseDir.'/'.$relative;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Verzeichnis konnte nicht erstellt werden: '.$relative);
        }
        @chmod($path, 0775);
    }
}

function finalRootHtaccess(): string
{
    return <<<'HTACCESS'
Options -Indexes

<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteRule ^install/?$ install.php [L,QSA]
    RewriteRule ^install\.php$ - [L]

    RewriteCond %{DOCUMENT_ROOT}/public/$1 -f
    RewriteRule ^(.+)$ public/$1 [L]

    RewriteCond %{DOCUMENT_ROOT}/public/$1 -d
    RewriteRule ^(.+)$ public/$1 [L]

    RewriteRule ^(?:app|bootstrap|config|database|resources|routes|storage|tests|vendor|\.git|\.github|\.installer-backups|\.installer-tmp)(?:/|$) - [R=404,L,NC]
    RewriteRule ^(?:\.env(?:\..*)?|\.installed|artisan|composer\.(?:json|lock)|phpunit\.xml|package(?:-lock)?\.json)$ - [R=404,L,NC]

    RewriteRule ^ public/index.php [L]
</IfModule>
HTACCESS;
}

function writeRootHtaccess(string $baseDir): void
{
    if (file_put_contents($baseDir.'/.htaccess', finalRootHtaccess()) === false) {
        throw new RuntimeException('Die .htaccess-Datei konnte nicht geschrieben werden.');
    }
}

function preflight(string $baseDir): array
{
    $checks = [[
        'label' => 'PHP 8.3+',
        'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'detail' => PHP_VERSION,
    ]];

    foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'gd'] as $extension) {
        $checks[] = [
            'label' => 'PHP-Erweiterung '.$extension,
            'ok' => extension_loaded($extension),
            'detail' => extension_loaded($extension) ? 'vorhanden' : 'fehlt',
        ];
    }

    $checks[] = [
        'label' => 'Archiv entpacken',
        'ok' => class_exists(ZipArchive::class) || executable('unzip') !== null,
        'detail' => class_exists(ZipArchive::class) ? 'ZipArchive' : (executable('unzip') ? 'unzip' : 'nicht verfügbar'),
    ];

    $checks[] = [
        'label' => 'GitHub-Download',
        'ok' => extension_loaded('curl') || (bool)ini_get('allow_url_fopen'),
        'detail' => extension_loaded('curl') ? 'cURL' : ((bool)ini_get('allow_url_fopen') ? 'allow_url_fopen' : 'nicht verfügbar'),
    ];

    $checks[] = [
        'label' => 'Shell-Befehle',
        'ok' => canExec(),
        'detail' => canExec() ? 'exec() verfügbar' : 'exec() deaktiviert',
    ];

    $composer = executable('composer')
        ?: (is_file('/usr/local/bin/composer') ? '/usr/local/bin/composer' : (is_file('/usr/bin/composer') ? '/usr/bin/composer' : null));

    $checks[] = [
        'label' => 'Composer',
        'ok' => $composer !== null,
        'detail' => $composer ?: 'nicht gefunden',
    ];

    $checks[] = [
        'label' => 'Schreibrechte',
        'ok' => is_writable($baseDir),
        'detail' => $baseDir,
    ];

    return $checks;
}

function render(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
    echo '<title>'.h($title).' · Wear&Earn Installer</title>';
    echo <<<'CSS'
<style>
:root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033;background:#f3f5f8}
*{box-sizing:border-box}body{margin:0;min-height:100vh;background:linear-gradient(180deg,#f8fafc 0,#edf1f6 100%)}
.wrap{max-width:820px;margin:0 auto;padding:18px 14px 48px}.brand{display:flex;align-items:center;gap:12px;margin:10px 0 20px}.mark{width:44px;height:44px;border-radius:14px;display:grid;place-items:center;background:#172033;color:#fff;font-weight:800}
.card{background:#fff;border:1px solid #e3e7ee;border-radius:20px;padding:20px;box-shadow:0 16px 45px rgba(31,41,55,.08);margin-bottom:14px}
h1{font-size:1.7rem;margin:.2rem 0 .5rem}h2{font-size:1.15rem;margin:0 0 12px}p{line-height:1.55}.muted{color:#667085}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.full{grid-column:1/-1}
label{display:block;font-size:.9rem;font-weight:650;color:#344054}input,select{width:100%;margin-top:6px;padding:12px;border:1px solid #cfd5df;border-radius:12px;background:#fff;font:inherit}
button,.btn{display:inline-flex;justify-content:center;align-items:center;border:0;border-radius:12px;padding:12px 16px;background:#172033;color:#fff;font-weight:750;text-decoration:none;cursor:pointer}.btn.secondary{background:#eef2f6;color:#172033}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.check{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid #edf0f4}.ok{color:#087a55}.bad{color:#b42318}.notice{padding:12px 14px;border-radius:12px;background:#f7f9fc;border:1px solid #e0e5ed;margin:12px 0}.error{background:#fff1f0;border-color:#f7c7c2;color:#8f1d14}.success{background:#ecfdf3;border-color:#a6f4c5;color:#05603a}
pre{white-space:pre-wrap;word-break:break-word;background:#111827;color:#e5e7eb;padding:14px;border-radius:12px;max-height:300px;overflow:auto;font-size:.78rem}
small{font-weight:500;color:#667085}
@media(max-width:640px){.grid{grid-template-columns:1fr}.full{grid-column:auto}.card{padding:16px;border-radius:16px}.wrap{padding:10px 10px 36px}h1{font-size:1.45rem}.actions>*{width:100%}}
</style>
CSS;
    echo '</head><body><div class="wrap"><div class="brand"><div class="mark">W&E</div><div><strong>Wear&Earn</strong><br><small>Web-Installer '.h(SHOP_INSTALLER_VERSION).'</small></div></div>';
    echo $body;
    echo '</div></body></html>';
    exit;
}

if (is_file($lockFile)) {
    render(
        'Bereits installiert',
        '<div class="card"><h1>Installation abgeschlossen</h1><p>Der Installer ist aus Sicherheitsgründen gesperrt.</p><div class="actions"><a class="btn" href="/login">Zum Login</a></div></div>'
    );
}

csrfToken();
$step = (string)($_GET['step'] ?? 'preflight');

try {
    if ($step === 'preflight') {
        $checks = preflight($baseDir);
        $allOk = !in_array(false, array_column($checks, 'ok'), true);

        $rows = '';
        foreach ($checks as $check) {
            $rows .= '<div class="check"><span>'.h($check['label']).'<br><small>'.h($check['detail']).'</small></span><strong class="'.($check['ok'] ? 'ok' : 'bad').'">'.($check['ok'] ? 'OK' : 'FEHLT').'</strong></div>';
        }

        $body = '<div class="card"><h1>Installation</h1><p class="muted">Der Installer lädt den aktuellen Shop direkt aus GitHub, richtet Laravel ein und erstellt die Datenbankstruktur.</p>'.$rows;
        $body .= $allOk
            ? '<div class="actions"><a class="btn" href="?step=settings">Weiter zur Konfiguration</a></div>'
            : '<div class="notice error">Mindestens eine zwingende Servervoraussetzung fehlt.</div>';
        $body .= '</div>';

        render('Voraussetzungen', $body);
    }

    if ($step === 'settings') {
        $body = '<div class="card"><h1>Shop konfigurieren</h1><p class="muted">Die Zugangsdaten werden ausschließlich in deiner lokalen <code>.env</code> auf dem Webspace gespeichert.</p>';
        $body .= '<form method="post" action="?step=install"><input type="hidden" name="_token" value="'.h(csrfToken()).'"><div class="grid">';
        $body .= '<label class="full">Shop-URL<input name="app_url" type="url" required value="'.h(currentBaseUrl()).'"></label>';
        $body .= '<label>Datenbank-Host<input name="db_host" required value="127.0.0.1"></label>';
        $body .= '<label>Datenbank-Port<input name="db_port" inputmode="numeric" required value="3306"></label>';
        $body .= '<label>Datenbankname<input name="db_name" required autocomplete="off"></label>';
        $body .= '<label>Datenbank-Benutzer<input name="db_user" required autocomplete="off"></label>';
        $body .= '<label class="full">Datenbank-Passwort<input name="db_password" type="password" required autocomplete="new-password"></label>';
        $body .= '<div class="full"><h2>Admin-Konto</h2></div>';
        $body .= '<label>Benutzername<input name="admin_username" required minlength="3" maxlength="80" pattern="[A-Za-z0-9._-]+"></label>';
        $body .= '<label>E-Mail<input name="admin_email" type="email" required></label>';
        $body .= '<label>Vorname<input name="admin_first_name" required></label>';
        $body .= '<label>Nachname<input name="admin_last_name" required></label>';
        $body .= '<label class="full">Admin-Passwort<input name="admin_password" type="password" required minlength="12" autocomplete="new-password"><small>Mindestens 12 Zeichen.</small></label>';
        $body .= '<div class="full"><h2>Mailversand (optional)</h2><p class="muted">Leer lassen = E-Mails werden zunächst nur ins Laravel-Log geschrieben.</p></div>';
        $body .= '<label>SMTP-Host<input name="mail_host"></label>';
        $body .= '<label>SMTP-Port<input name="mail_port" inputmode="numeric" value="587"></label>';
        $body .= '<label>SMTP-Benutzer<input name="mail_username"></label>';
        $body .= '<label>SMTP-Passwort<input name="mail_password" type="password"></label>';
        $body .= '<label>Verschlüsselung<select name="mail_encryption"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="">Keine</option></select></label>';
        $body .= '<label>Absender-E-Mail<input name="mail_from_address" type="email"></label>';
        $body .= '<label class="full"><input type="checkbox" name="download_source" value="1" checked style="width:auto;margin-right:8px">Aktuellen Quellcode aus GitHub laden/aktualisieren</label>';
        $body .= '</div><div class="actions"><button type="submit">Installation starten</button><a class="btn secondary" href="?step=preflight">Zurück</a></div></form></div>';

        render('Konfiguration', $body);
    }

    if ($step === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();

        $settings = [
            'app_url' => rtrim(trim((string)($_POST['app_url'] ?? '')), '/'),
            'db_host' => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
            'db_port' => (int)($_POST['db_port'] ?? 3306),
            'db_name' => trim((string)($_POST['db_name'] ?? '')),
            'db_user' => trim((string)($_POST['db_user'] ?? '')),
            'db_password' => (string)($_POST['db_password'] ?? ''),
            'admin_username' => trim((string)($_POST['admin_username'] ?? '')),
            'admin_email' => trim((string)($_POST['admin_email'] ?? '')),
            'admin_first_name' => trim((string)($_POST['admin_first_name'] ?? '')),
            'admin_last_name' => trim((string)($_POST['admin_last_name'] ?? '')),
            'admin_password' => (string)($_POST['admin_password'] ?? ''),
            'mail_host' => trim((string)($_POST['mail_host'] ?? '')),
            'mail_port' => (int)($_POST['mail_port'] ?? 587),
            'mail_username' => trim((string)($_POST['mail_username'] ?? '')),
            'mail_password' => (string)($_POST['mail_password'] ?? ''),
            'mail_encryption' => trim((string)($_POST['mail_encryption'] ?? 'tls')),
            'mail_from_address' => trim((string)($_POST['mail_from_address'] ?? '')),
            'download_source' => isset($_POST['download_source']),
        ];

        if (!filter_var($settings['app_url'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Die Shop-URL ist ungültig.');
        }
        if ($settings['db_name'] === '' || $settings['db_user'] === '' || $settings['db_password'] === '') {
            throw new RuntimeException('Die Datenbankangaben sind unvollständig.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $settings['admin_username'])) {
            throw new RuntimeException('Der Admin-Benutzername ist ungültig.');
        }
        if (!filter_var($settings['admin_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Die Admin-E-Mail ist ungültig.');
        }
        if (strlen($settings['admin_password']) < 12) {
            throw new RuntimeException('Das Admin-Passwort muss mindestens 12 Zeichen lang sein.');
        }

        $steps = ['Datenbankverbindung wird geprüft …'];
        $pdo = databaseConnection($settings);
        $steps[] = 'Datenbankverbindung erfolgreich.';

        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        if ($settings['download_source']) {
            $steps[] = 'Aktueller Shop wird von GitHub geladen …';
            $zipFile = $tmpDir.'/shop-main.zip';
            downloadFile(SHOP_REPOSITORY_ZIP, $zipFile);
            $sourceDir = extractSource($zipFile, $tmpDir.'/source');

            copyTree($sourceDir, $baseDir, [
                '.git',
                '.github',
                '.env',
                '.installed',
                '.installer-tmp',
                '.installer-backups',
                'install.php',
                'vendor',
            ]);
            $steps[] = 'Quellcode wurde aktualisiert.';
        }

        if (!is_file($baseDir.'/composer.json') || !is_file($baseDir.'/artisan')) {
            throw new RuntimeException('Laravel-Projektdateien fehlen nach dem Download.');
        }

        ensureDirectories($baseDir);

        $steps[] = 'Composer-Abhängigkeiten werden installiert …';
        $php = phpCli();
        $composer = composerCli();
        [$composerStatus, $composerOutput] = runCommand(
            escapeshellarg($php).' '.escapeshellarg($composer).' install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress',
            $baseDir
        );
        if ($composerStatus !== 0) {
            throw new RuntimeException("Composer-Installation fehlgeschlagen:\n".$composerOutput);
        }
        $steps[] = 'Composer-Abhängigkeiten installiert.';

        writeEnvironment($baseDir, $settings);
        $steps[] = '.env wurde erstellt.';

        $artisan = escapeshellarg($php).' artisan';

        foreach ([
            ['Laravel-Cache wird geleert …', 'optimize:clear'],
            ['Datenbankmigrationen werden ausgeführt …', 'migrate --force'],
            ['Grunddaten werden angelegt …', 'db:seed --force'],
        ] as [$label, $command]) {
            $steps[] = $label;
            [$status, $output] = runCommand($artisan.' '.$command, $baseDir);
            if ($status !== 0) {
                throw new RuntimeException($label."\n".$output);
            }
        }

        $steps[] = createAdmin($pdo, $settings);

        foreach (['storage:link', 'config:cache', 'route:cache', 'view:cache'] as $command) {
            [$status, $output] = runCommand($artisan.' '.$command, $baseDir);
            if ($status !== 0 && $command !== 'storage:link') {
                throw new RuntimeException('Laravel-Abschlussbefehl fehlgeschlagen ('.$command."):\n".$output);
            }
        }

        writeRootHtaccess($baseDir);

        file_put_contents($lockFile, json_encode([
            'installed_at' => date(DATE_ATOM),
            'installer_version' => SHOP_INSTALLER_VERSION,
            'app_url' => $settings['app_url'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($lockFile, 0600);
        deleteTree($tmpDir);

        $body = '<div class="card"><div class="notice success"><strong>Installation erfolgreich.</strong><br>Der Installer ist jetzt automatisch gesperrt.</div><h1>Wear&Earn ist bereit</h1>';
        $body .= '<p>Die Anwendung, Datenbank und das Admin-Konto wurden eingerichtet.</p><div class="actions"><a class="btn" href="'.h($settings['app_url'].'/login').'">Zum Login</a></div>';
        $body .= '<h2 style="margin-top:24px">Installationsprotokoll</h2><pre>'.h(implode("\n", $steps)).'</pre>';
        $body .= '<div class="notice">Für Erinnerungen und Fristen muss zusätzlich ein Scheduler-Cronjob im KAS eingerichtet werden: <code>/usr/bin/php83 '.h($baseDir).'/artisan schedule:run</code>.</div></div>';

        render('Fertig', $body);
    }

    header('Location: ?step=preflight');
    exit;
} catch (Throwable $e) {
    $body = '<div class="card"><div class="notice error"><strong>Installation abgebrochen</strong><br>'.nl2br(h($e->getMessage())).'</div>';
    $body .= '<p>Es wurden keine Datenbanktabellen absichtlich gelöscht. Du kannst den Fehler beheben und erneut starten.</p>';
    $body .= '<div class="actions"><a class="btn" href="?step=preflight">Erneut prüfen</a><a class="btn secondary" href="?step=settings">Konfiguration ändern</a></div></div>';
    render('Fehler', $body, 500);
}

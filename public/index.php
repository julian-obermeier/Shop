<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$autoload=__DIR__.'/../vendor/autoload.php';

if (! is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    echo <<<'HTML'
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Anwendung wird bereitgestellt</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f6f7f9;color:#172033;display:grid;min-height:100vh;place-items:center}
main{max-width:680px;margin:24px;padding:32px;border-radius:18px;background:#fff;box-shadow:0 18px 60px rgba(20,32,51,.10)}
h1{margin-top:0;font-size:1.8rem}p{line-height:1.6;margin-bottom:0}.muted{color:#667085;margin-top:10px}
</style>
</head>
<body>
<main>
<h1>Anwendung noch nicht vollständig bereitgestellt</h1>
<p>Die Server-Abhängigkeiten der Anwendung fehlen. Bitte das vollständige Produktionspaket inklusive <code>vendor</code>-Verzeichnis bereitstellen.</p>
<p class="muted">HTTP 503 · Deployment unvollständig</p>
</main>
</body>
</html>
HTML;
    exit;
}

require $autoload;

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());

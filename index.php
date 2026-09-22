<?php
declare(strict_types=1);
require __DIR__ . '/app/Core.php';
require __DIR__ . '/app/Migrations.php';
run_migrations();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = parse_url((string)app_config('app.url',''), PHP_URL_PATH) ?: '';
if ($base && $base !== '/' && str_starts_with($path, $base)) $path = substr($path, strlen($base)) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') csrf_verify();


foreach (glob(__DIR__.'/app/routes/*.php') as $routeFile) require $routeFile;

not_found();

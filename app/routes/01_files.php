<?php
declare(strict_types=1);
// Private image streaming
if (preg_match('#^/file/(precheck|day)/(\d+)$#', $path, $m) && $method === 'GET') {
    $u = require_login();
    if ($m[1] === 'precheck') {
        $q = db()->prepare('SELECT p.*,o.seller_id owner_id FROM precheck_uploads p JOIN orders o ON o.id=p.order_id WHERE p.id=?');
    } else {
        $q = db()->prepare('SELECT p.*,o.seller_id owner_id FROM day_uploads p JOIN order_days d ON d.id=p.day_id JOIN orders o ON o.id=d.order_id WHERE p.id=?');
    }
    $q->execute([(int)$m[2]]); $f = $q->fetch(); if (!$f) not_found();
    if ($u['role'] !== 'admin' && (int)$u['id'] !== (int)$f['owner_id']) { http_response_code(403); exit('Zugriff verweigert.'); }
    $rel = ltrim((string)$f['file_path'],'/');
    if (!$rel || str_contains($rel,'..') || str_contains($rel,"\0")) not_found();
    $real = __DIR__ . '/storage/private/' . $rel; if (!is_file($real)) not_found();
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    header('Content-Type: ' . $f['mime_type']); header('Content-Length: ' . filesize($real));
    readfile($real); exit;
}


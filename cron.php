<?php
declare(strict_types=1);
require __DIR__.'/app/Core.php';
$pdo=db();
$now=date('Y-m-d H:i:s');
$pdo->prepare("DELETE FROM email_verifications WHERE expires_at < ?")->execute([$now]);
$pdo->prepare("DELETE FROM password_resets WHERE expires_at < ? OR used_at IS NOT NULL")->execute([$now]);
// V1 scheduler hook: weitere Nachweis-/Frist-/Benachrichtigungsjobs werden hier idempotent ergänzt.
echo '['.date('c')."] cron ok\n";

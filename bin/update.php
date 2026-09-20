<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require dirname(__DIR__).'/app/Core.php';
$pdo=db();
$schema=file_get_contents(dirname(__DIR__).'/database/schema.sql');
if($schema===false) throw new RuntimeException('schema.sql fehlt.');
$pdo->exec($schema);
$pdo->prepare("INSERT IGNORE INTO migrations(migration) VALUES(?)")->execute(['v1-baseline']);
echo "V1-Datenbankschema geprüft/aktualisiert.\n";

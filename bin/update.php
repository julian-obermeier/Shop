<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require dirname(__DIR__).'/app/Core.php';
require dirname(__DIR__).'/app/Migrator.php';
try{
    $r=run_app_migrations(db(),dirname(__DIR__).'/database/migrations');
    echo "Angewendet: ".count($r['applied'])."\n";
    foreach($r['applied'] as $m) echo " + ".$m."\n";
    echo "Übersprungen: ".count($r['skipped'])."\n";
    foreach($r['warnings'] as $w) echo " ! ".$w."\n";
    echo "Datenbankupdate abgeschlossen.\n";
}catch(Throwable $e){
    fwrite(STDERR,"Update fehlgeschlagen: ".$e->getMessage()."\n");
    exit(1);
}

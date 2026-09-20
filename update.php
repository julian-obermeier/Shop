<?php
declare(strict_types=1);
require __DIR__.'/app/Core.php';
require_admin();

$pdo=db();
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 migration VARCHAR(190) NOT NULL UNIQUE,
 applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$files=glob(__DIR__.'/database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);
$applied=[];
$skipped=[];
foreach($files as $file){
    $name=basename($file,'.sql');
    $q=$pdo->prepare('SELECT COUNT(*) FROM migrations WHERE migration=?');
    $q->execute([$name]);
    if((int)$q->fetchColumn()>0){$skipped[]=$name;continue;}
    $sql=file_get_contents($file);
    if($sql===false) throw new RuntimeException('Migration konnte nicht gelesen werden: '.$name);
    try{
        $pdo->exec($sql);
        $pdo->prepare('INSERT INTO migrations(migration) VALUES(?)')->execute([$name]);
        $applied[]=$name;
    }catch(Throwable $e){
        http_response_code(500);
        echo '<h1>Update fehlgeschlagen</h1><p>'.e($name).'</p><pre>'.e($e->getMessage()).'</pre>';
        exit;
    }
}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>V1 Update</title>
<style>body{font-family:system-ui;background:#f7f5f8;color:#211d25}.box{max-width:760px;margin:40px auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 15px 45px #0001}code{background:#f1edf2;padding:2px 5px;border-radius:5px}.ok{color:#217a52}</style></head><body><div class="box"><h1>V1 Update</h1>
<?php if($applied):?><p class="ok"><strong><?=count($applied)?> Migration(en) angewendet.</strong></p><ul><?php foreach($applied as $m):?><li><code><?=e($m)?></code></li><?php endforeach;?></ul><?php else:?><p class="ok"><strong>Datenbank ist aktuell.</strong></p><?php endif;?>
<p><?=count($skipped)?> bereits vorhandene Migration(en) übersprungen.</p><p><a href="<?=e(url('/admin'))?>">Zurück zum Admin-Bereich</a></p></div></body></html>
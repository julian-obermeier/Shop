<?php
declare(strict_types=1);
require __DIR__.'/app/Core.php';
require __DIR__.'/app/Migrator.php';
require_admin();

try{
    $result=run_app_migrations(db(),__DIR__.'/database/migrations');
}catch(Throwable $e){
    http_response_code(500);
    echo '<h1>Update fehlgeschlagen</h1><pre>'.e($e->getMessage()).'</pre>';
    exit;
}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>V1 Update</title>
<style>body{font-family:system-ui;background:#f7f5f8;color:#211d25}.box{max-width:820px;margin:40px auto;background:#fff;padding:28px;border-radius:18px;box-shadow:0 15px 45px #0001}code{background:#f1edf2;padding:2px 5px;border-radius:5px}.ok{color:#217a52}.warn{color:#8a5a00}</style></head><body><div class="box"><h1>V1 Update</h1>
<?php if($result['applied']):?><p class="ok"><strong><?=count($result['applied'])?> Migration(en) angewendet.</strong></p><ul><?php foreach($result['applied'] as $m):?><li><code><?=e($m)?></code></li><?php endforeach;?></ul><?php else:?><p class="ok"><strong>Datenbank ist aktuell.</strong></p><?php endif;?>
<p><?=count($result['skipped'])?> bereits angewendete Migration(en) übersprungen.</p>
<?php if($result['warnings']):?><div class="warn"><strong><?=count($result['warnings'])?> bereits vorhandene Schemaelemente erkannt:</strong><ul><?php foreach($result['warnings'] as $w):?><li><?=e($w)?></li><?php endforeach;?></ul></div><?php endif;?>
<p><a href="<?=e(url('/admin'))?>">Zurück zum Admin-Bereich</a></p></div></body></html>
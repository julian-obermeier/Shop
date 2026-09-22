<?php
declare(strict_types=1);
// SELLER ORDER DETAIL + UPLOADS
if (preg_match('#^/seller/order/(\d+)$#',$path,$m) && $method==='GET') {
    $s=require_seller();$id=(int)$m[1];
    $q=db()->prepare('SELECT * FROM orders WHERE id=? AND seller_id=?');$q->execute([$id,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT * FROM precheck_uploads WHERE order_id=? ORDER BY id');$q->execute([$id]);$pre=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM order_days WHERE order_id=? ORDER BY day_no');$q->execute([$id]);$days=$q->fetchAll();
    $current=null;foreach($days as $d){if(in_array($d['status'],['planned','submitted'],true)){$current=$d;break;}}
    $rejection=latest_precheck_rejection($id);
    $currentDate=$current?order_day_date($o['started_at'],$current['day_no']):null;
    $today=new DateTimeImmutable('today');
    $canSubmit=$currentDate && $currentDate<=$today;

    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Auftrag <?=e($o['order_no'])?></span><h1><?=e($o['title_snapshot'])?></h1><p><?=money($o['compensation'])?></p></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></div>
    <div class="stats compact">
        <div class="stat"><span>Erfolgreich</span><strong><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?></strong></div>
        <div class="stat"><span>Verlängerung</span><strong>+<?=e($o['extension_days'])?></strong></div>
        <div class="stat"><span>Start</span><strong class="stat-date"><?=e($o['started_at']?date_de(order_day_date($o['started_at'],1)):'–')?></strong></div>
    </div>

    <?php if($o['status']==='precheck'):?>
    <section class="panel">
        <h2>1. Vorabkontrolle</h2>
        <p><?=nl2br(e($o['precheck_instructions']))?></p>
        <?php if($rejection):?><div class="notice warning"><strong>Vom Admin zurückgewiesen:</strong><br><?=nl2br(e($rejection))?><br><span>Bitte die Vorabkontrolle vollständig neu einreichen.</span></div><?php endif;?>
        <p class="muted"><?=count($pre)?>/<?=e($o['precheck_photo_count'])?> Fotos hochgeladen</p>
        <?php if($pre):?><div class="photo-grid"><?php foreach($pre as $p):?><a href="<?=e(url('/file/precheck/'.$p['id']))?>" target="_blank"><img src="<?=e(url('/file/precheck/'.$p['id']))?>" alt="Vorabfoto"></a><?php endforeach;?></div><?php endif;?>
        <?php $remaining=(int)$o['precheck_photo_count']-count($pre);if($remaining>0):?>
            <form method="post" enctype="multipart/form-data" action="<?=e(url('/seller/order/'.$id.'/precheck'))?>">
                <label>Noch <?=e($remaining)?> Foto(s) erforderlich<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple required></label>
                <button class="btn">Vorabfotos hochladen</button>
            </form>
        <?php else:?><div class="notice success">Vorabkontrolle vollständig eingereicht. Sie wartet auf Freigabe.</div><?php endif;?>
    </section>
    <?php endif;?>

    <?php if(in_array($o['status'],['running','completed'],true)):?>
    <section class="panel">
        <h2>2. Durchführung</h2>
        <p><?=nl2br(e($o['daily_instructions']))?></p>
        <?php if($o['started_at']):?><div class="notice"><strong>Geplanter Start:</strong> <?=e(date_de(order_day_date($o['started_at'],1)))?></div><?php endif;?>

        <?php if($o['status']==='completed'):?>
            <div class="notice success">Auftrag vollständig abgeschlossen.</div>
        <?php elseif($current):?>
            <div class="current-day">
                <span class="eyebrow"><?=((int)$current['is_extension'])?'Verlängerungstag':'Aktueller Tag'?></span>
                <h3>Tag <?=e($current['day_no'])?> · <?=e(date_de($currentDate))?></h3>
                <?php if($current['status']==='submitted'):?>
                    <div class="notice">Deine <?=e($current['required_photo_count'])?> Fotos wurden eingereicht und warten auf Prüfung.</div>
                <?php elseif(!$canSubmit):?>
                    <div class="notice"><strong>Noch nicht freigeschaltet.</strong><br>Dieser Durchführungstag kann ab <?=e(date_de($currentDate))?> eingereicht werden.</div>
                <?php else:?>
                    <p><?=e($current['required_photo_count'])?> Fotos sind erforderlich.</p>
                    <?php if($currentDate<$today):?><div class="notice warning">Dieser Tag ist bereits fällig. Du kannst ihn weiterhin jetzt einreichen.</div><?php endif;?>
                    <form method="post" enctype="multipart/form-data" action="<?=e(url('/seller/day/'.$current['id'].'/submit'))?>">
                        <label>Fotos<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple required></label>
                        <label>Kommentar (optional)<textarea name="seller_note" rows="3"></textarea></label>
                        <button class="btn">Tag einreichen</button>
                    </form>
                <?php endif;?>
            </div>
        <?php endif;?>

        <div class="timeline">
        <?php foreach($days as $d):
            $scheduled=order_day_date($o['started_at'],$d['day_no']);
        ?>
            <div class="timeline-row">
                <span class="dot dot-<?=e($d['status'])?>"></span>
                <div>
                    <div><strong>Tag <?=e($d['day_no'])?><?=((int)$d['is_extension'])?' · Verlängerung':''?></strong><span><?=e(date_de($scheduled))?></span></div>
                    <div class="timeline-meta"><span><?=e(day_status_label($d['status']))?></span><?php if($d['admin_note']):?><span>Admin: <?=e($d['admin_note'])?></span><?php endif;?></div>
                </div>
            </div>
        <?php endforeach;?>
        </div>
    </section>
    <?php endif;?>

    <?php render('Auftrag '.$o['order_no'],ob_get_clean());exit;
}

if (preg_match('#^/seller/order/(\d+)/precheck$#',$path,$m) && $method==='POST') {
    $s=require_seller();$id=(int)$m[1];
    $q=db()->prepare('SELECT * FROM orders WHERE id=? AND seller_id=?');$q->execute([$id,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck'){flash('error','Vorabkontrolle ist geschlossen.');redirect('/seller/order/'.$id);}
    $q=db()->prepare('SELECT COUNT(*) FROM precheck_uploads WHERE order_id=?');$q->execute([$id]);$have=(int)$q->fetchColumn();
    $remaining=(int)$o['precheck_photo_count']-$have;$files=normalized_uploads('photos');
    if(!$files||count($files)>$remaining){flash('error','Bitte höchstens die noch benötigten '.$remaining.' Foto(s) hochladen.');redirect('/seller/order/'.$id);}
    try{
        foreach($files as $f){
            $up=save_image_upload($f,'order-'.$id.'/precheck');
            db()->prepare('INSERT INTO precheck_uploads(order_id,seller_id,file_path,mime_type,file_size,sha256) VALUES(?,?,?,?,?,?)')->execute([$id,$s['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);
        }
        log_event((int)$o['offer_id'],$id,'precheck.uploaded',['count'=>count($files)]);flash('success','Vorabfotos gespeichert.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('/seller/order/'.$id);
}

if (preg_match('#^/seller/day/(\d+)/submit$#',$path,$m) && $method==='POST') {
    $s=require_seller();$dayId=(int)$m[1];
    $q=db()->prepare("SELECT d.*,o.offer_id,o.seller_id,o.status order_status,o.started_at FROM order_days d JOIN orders o ON o.id=d.order_id WHERE d.id=? AND o.seller_id=?");$q->execute([$dayId,$s['id']]);$d=$q->fetch();if(!$d)not_found();
    if($d['order_status']!=='running'||$d['status']!=='planned'){flash('error','Dieser Tag kann nicht eingereicht werden.');redirect('/seller/order/'.$d['order_id']);}
    $scheduled=order_day_date($d['started_at'],$d['day_no']);
    if(!$scheduled || $scheduled>new DateTimeImmutable('today')){flash('error','Dieser Durchführungstag ist noch nicht freigeschaltet.');redirect('/seller/order/'.$d['order_id']);}
    $q=db()->prepare("SELECT id FROM order_days WHERE order_id=? AND status IN('planned','submitted') ORDER BY day_no LIMIT 1");$q->execute([$d['order_id']]);
    if((int)$q->fetchColumn()!==$dayId){flash('error','Bitte die Tage der Reihe nach bearbeiten.');redirect('/seller/order/'.$d['order_id']);}
    $files=normalized_uploads('photos');
    if(count($files)!==(int)$d['required_photo_count']){flash('error','Für diesen Tag sind genau '.$d['required_photo_count'].' Fotos erforderlich.');redirect('/seller/order/'.$d['order_id']);}

    db()->beginTransaction();
    try{
        foreach($files as $f){
            $up=save_image_upload($f,'order-'.$d['order_id'].'/day-'.$d['day_no']);
            db()->prepare('INSERT INTO day_uploads(day_id,seller_id,file_path,mime_type,file_size,sha256) VALUES(?,?,?,?,?,?)')->execute([$dayId,$s['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);
        }
        db()->prepare("UPDATE order_days SET status='submitted',seller_note=?,submitted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([post('seller_note')?:null,$dayId]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();flash('error',$e->getMessage());redirect('/seller/order/'.$d['order_id']);}
    log_event((int)$d['offer_id'],(int)$d['order_id'],'day.submitted',['day_no'=>$d['day_no'],'scheduled_date'=>$scheduled->format('Y-m-d')]);
    flash('success','Tag wurde eingereicht und wartet auf Prüfung.');redirect('/seller/order/'.$d['order_id']);
}

<?php
declare(strict_types=1);
// ADMIN REVIEW CENTER
if ($path==='/admin/reviews' && $method==='GET') {
    require_admin();
    $pre=db()->query("SELECT o.*,s.first_name,s.last_name,COUNT(p.id) photo_count
        FROM orders o
        JOIN sellers s ON s.id=o.seller_id
        LEFT JOIN precheck_uploads p ON p.order_id=o.id
        WHERE o.status='precheck'
        GROUP BY o.id
        HAVING photo_count>=o.precheck_photo_count
        ORDER BY o.created_at")->fetchAll();
    $days=db()->query("SELECT d.*,o.order_no,o.title_snapshot,o.started_at,o.offer_id,s.first_name,s.last_name
        FROM order_days d
        JOIN orders o ON o.id=d.order_id
        JOIN sellers s ON s.id=o.seller_id
        WHERE d.status='submitted'
        ORDER BY d.submitted_at,d.id")->fetchAll();

    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Prüfcenter</span><h1>Offene Prüfungen</h1><p>Vorabkontrollen und Tagesnachweise an einem Ort.</p></div></div>
    <div class="stats compact">
        <div class="stat"><span>Vorabkontrollen bereit</span><strong><?=count($pre)?></strong></div>
        <div class="stat"><span>Tagesnachweise</span><strong><?=count($days)?></strong></div>
        <div class="stat"><span>Gesamt offen</span><strong><?=count($pre)+count($days)?></strong></div>
    </div>

    <div class="section-head"><h2>Vorabkontrollen</h2></div>
    <?php if($pre):?><div class="review-grid">
    <?php foreach($pre as $o):
        $uq=db()->prepare('SELECT * FROM precheck_uploads WHERE order_id=? ORDER BY id');$uq->execute([$o['id']]);$ups=$uq->fetchAll();
    ?>
        <article class="panel review-card">
            <div class="card-top"><div><span class="eyebrow"><?=e($o['order_no'])?></span><h3><?=e($o['title_snapshot'])?></h3><p class="muted"><?=e($o['first_name'].' '.$o['last_name'])?></p></div><span class="status status-precheck">Vorabkontrolle</span></div>
            <p><?=nl2br(e($o['precheck_instructions']))?></p>
            <div class="photo-grid"><?php foreach($ups as $u):?><a href="<?=e(url('/file/precheck/'.$u['id']))?>" target="_blank"><img src="<?=e(url('/file/precheck/'.$u['id']))?>" alt="Vorabnachweis"></a><?php endforeach;?></div>
            <div class="review-split">
                <form method="post" action="<?=e(url('/admin/order/'.$o['id'].'/approve-precheck'))?>">
                    <input type="hidden" name="return_to" value="reviews">
                    <label>Startdatum<input type="date" name="start_date" min="<?=e(date('Y-m-d'))?>" value="<?=e(date('Y-m-d'))?>" required></label>
                    <button class="btn full">✓ Freigeben & starten</button>
                </form>
                <form method="post" action="<?=e(url('/admin/order/'.$o['id'].'/reject-precheck'))?>">
                    <input type="hidden" name="return_to" value="reviews">
                    <label>Begründung<textarea name="reason" rows="3" required placeholder="Was muss neu fotografiert oder korrigiert werden?"></textarea></label>
                    <button class="btn danger full">↺ Zurückweisen & neu anfordern</button>
                </form>
            </div>
            <a class="text-link" href="<?=e(url('/admin/order/'.$o['id']))?>">Auftrag vollständig öffnen →</a>
        </article>
    <?php endforeach;?></div>
    <?php else:?><div class="empty">Keine vollständige Vorabkontrolle wartet auf Prüfung.</div><?php endif;?>

    <div class="section-head"><h2>Tagesnachweise</h2></div>
    <?php if($days):?><div class="review-grid">
    <?php foreach($days as $d):
        $uq=db()->prepare('SELECT * FROM day_uploads WHERE day_id=? ORDER BY id');$uq->execute([$d['id']]);$ups=$uq->fetchAll();
        $scheduled=order_day_date($d['started_at'],$d['day_no']);
    ?>
        <article class="panel review-card">
            <div class="card-top"><div><span class="eyebrow"><?=e($d['order_no'])?> · Tag <?=e($d['day_no'])?></span><h3><?=e($d['title_snapshot'])?></h3><p class="muted"><?=e($d['first_name'].' '.$d['last_name'])?> · <?=e(date_de($scheduled))?><?=((int)$d['is_extension'])?' · Verlängerung':''?></p></div><span class="status status-submitted">Zur Prüfung</span></div>
            <?php if($d['seller_note']):?><div class="notice">Kommentar der Verkäuferin: <?=e($d['seller_note'])?></div><?php endif;?>
            <div class="photo-grid"><?php foreach($ups as $u):?><a href="<?=e(url('/file/day/'.$u['id']))?>" target="_blank"><img src="<?=e(url('/file/day/'.$u['id']))?>" alt="Tagesnachweis"></a><?php endforeach;?></div>
            <form class="review-actions" method="post" action="<?=e(url('/admin/day/'.$d['id'].'/review'))?>">
                <input type="hidden" name="return_to" value="reviews">
                <textarea name="admin_note" rows="3" placeholder="Notiz / Begründung optional"></textarea>
                <div><button class="btn" name="decision" value="fulfilled">✓ Erfüllt</button><button class="btn danger" name="decision" value="not_fulfilled">✕ Nicht erfüllt · +1 Tag</button></div>
            </form>
            <a class="text-link" href="<?=e(url('/admin/order/'.$d['order_id']))?>">Auftrag vollständig öffnen →</a>
        </article>
    <?php endforeach;?></div>
    <?php else:?><div class="empty">Keine Tagesnachweise warten auf Prüfung.</div><?php endif;?>

    <?php render('Prüfcenter',ob_get_clean());exit;
}

// ADMIN ORDERS LIST/DETAIL
if ($path==='/admin/orders' && $method==='GET') {
    require_admin();$orders=db()->query("SELECT o.*,s.first_name,s.last_name FROM orders o JOIN sellers s ON s.id=o.seller_id ORDER BY FIELD(o.status,'running','precheck','completed','cancelled'),o.created_at DESC")->fetchAll();
    ob_start();?><div class="page-head"><div><span class="eyebrow">Aufträge</span><h1>Alle Aufträge</h1></div><a class="btn ghost" href="<?=e(url('/admin/reviews'))?>">Prüfcenter öffnen</a></div><div class="list"><?php foreach($orders as $o):?><a class="list-row" href="<?=e(url('/admin/order/'.$o['id']))?>"><div><strong><?=e($o['order_no'].' · '.$o['title_snapshot'])?></strong><span><?=e($o['first_name'].' '.$o['last_name'])?> · <?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?> erfolgreich · +<?=e($o['extension_days'])?> Tag(e)<?php if($o['started_at']):?> · Start <?=e(date_de(order_day_date($o['started_at'],1)))?><?php endif;?></span></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></a><?php endforeach;?><?php if(!$orders):?><div class="empty">Noch keine Aufträge vorhanden.</div><?php endif;?></div><?php render('Aufträge',ob_get_clean());exit;
}

if (preg_match('#^/admin/order/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();$id=(int)$m[1];$q=db()->prepare("SELECT o.*,s.first_name,s.last_name FROM orders o JOIN sellers s ON s.id=o.seller_id WHERE o.id=?");$q->execute([$id]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT * FROM precheck_uploads WHERE order_id=? ORDER BY id');$q->execute([$id]);$pre=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM order_days WHERE order_id=? ORDER BY day_no');$q->execute([$id]);$days=$q->fetchAll();
    $rejection=latest_precheck_rejection($id);
    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Auftrag <?=e($o['order_no'])?></span><h1><?=e($o['title_snapshot'])?></h1><p><?=e($o['first_name'].' '.$o['last_name'])?> · <?=money($o['compensation'])?></p></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></div>
    <div class="stats compact">
        <div class="stat"><span>Erfolgreich</span><strong><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?></strong></div>
        <div class="stat"><span>Verlängerung</span><strong>+<?=e($o['extension_days'])?></strong></div>
        <div class="stat"><span>Start</span><strong class="stat-date"><?=e($o['started_at']?date_de(order_day_date($o['started_at'],1)):'–')?></strong></div>
    </div>

    <section class="panel">
        <div class="section-head"><h2>Vorabkontrolle</h2><span class="muted"><?=count($pre)?>/<?=e($o['precheck_photo_count'])?> Fotos vorhanden</span></div>
        <p><?=nl2br(e($o['precheck_instructions']))?></p>
        <?php if($rejection && $o['status']==='precheck'):?><div class="notice warning"><strong>Letzte Rückmeldung:</strong> <?=e($rejection)?></div><?php endif;?>
        <?php if($pre):?><div class="photo-grid"><?php foreach($pre as $p):?><a href="<?=e(url('/file/precheck/'.$p['id']))?>" target="_blank"><img src="<?=e(url('/file/precheck/'.$p['id']))?>" alt="Vorabnachweis"></a><?php endforeach;?></div><?php endif;?>
        <?php if($o['status']==='precheck'):?>
            <div class="review-split">
                <form method="post" action="<?=e(url('/admin/order/'.$id.'/approve-precheck'))?>">
                    <label>Geplanter Start<input type="date" name="start_date" min="<?=e(date('Y-m-d'))?>" value="<?=e(date('Y-m-d'))?>" required></label>
                    <button class="btn full" <?=count($pre)<(int)$o['precheck_photo_count']?'disabled':''?>>✓ Freigeben & starten</button>
                </form>
                <form method="post" action="<?=e(url('/admin/order/'.$id.'/reject-precheck'))?>">
                    <label>Zurückweisungsgrund<textarea name="reason" rows="3" required placeholder="Was soll erneut eingereicht werden?"></textarea></label>
                    <button class="btn danger full" <?=!$pre?'disabled':''?>>↺ Zurückweisen</button>
                </form>
            </div>
        <?php endif;?>
    </section>

    <?php if($days):?>
    <section class="panel"><h2>Durchführungstage</h2><div class="day-list">
    <?php foreach($days as $d):
        $scheduled=order_day_date($o['started_at'],$d['day_no']);
        $uq=db()->prepare('SELECT * FROM day_uploads WHERE day_id=? ORDER BY id');$uq->execute([$d['id']]);$ups=$uq->fetchAll();
    ?>
        <div class="day-row">
            <div class="day-main">
                <div class="day-title"><strong>Tag <?=e($d['day_no'])?><?=((int)$d['is_extension'])?' · Verlängerung':''?></strong><span class="day-date"><?=e(date_de($scheduled))?></span></div>
                <span class="status status-<?=e($d['status'])?>"><?=e(day_status_label($d['status']))?></span>
                <?php if($d['seller_note']):?><p class="muted">Kommentar: <?=e($d['seller_note'])?></p><?php endif;?>
                <?php if($d['admin_note']):?><p class="muted">Admin-Notiz: <?=e($d['admin_note'])?></p><?php endif;?>
                <div class="photo-grid small"><?php foreach($ups as $u):?><a href="<?=e(url('/file/day/'.$u['id']))?>" target="_blank"><img src="<?=e(url('/file/day/'.$u['id']))?>" alt="Tagesnachweis"></a><?php endforeach;?></div>
            </div>
            <?php if($d['status']==='submitted'):?>
            <form class="review-actions" method="post" action="<?=e(url('/admin/day/'.$d['id'].'/review'))?>">
                <textarea name="admin_note" rows="2" placeholder="Notiz optional"></textarea>
                <div><button class="btn" name="decision" value="fulfilled">✓ Erfüllt</button><button class="btn danger" name="decision" value="not_fulfilled">✕ Nicht erfüllt · +1 Tag</button></div>
            </form>
            <?php endif;?>
        </div>
    <?php endforeach;?></div></section>
    <?php endif;?>
    <?php render('Auftrag '.$o['order_no'],ob_get_clean());exit;
}

if (preg_match('#^/admin/order/(\d+)/approve-precheck$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck'){flash('error','Vorabkontrolle ist bereits abgeschlossen.');redirect('/admin/order/'.$id);}
    $c=db()->prepare('SELECT COUNT(*) FROM precheck_uploads WHERE order_id=?');$c->execute([$id]);
    if((int)$c->fetchColumn()<(int)$o['precheck_photo_count']){flash('error','Es fehlen Vorabfotos.');redirect('/admin/order/'.$id);}
    $startDate=post('start_date');
    $start=DateTimeImmutable::createFromFormat('!Y-m-d',$startDate);
    $today=new DateTimeImmutable('today');
    if(!$start || $start->format('Y-m-d')!==$startDate || $start<$today){flash('error','Bitte ein gültiges Startdatum ab heute wählen.');redirect('/admin/order/'.$id);}
    db()->beginTransaction();
    try{
        db()->prepare("UPDATE orders SET status='running',precheck_approved_at=NOW(),started_at=?,updated_at=NOW() WHERE id=?")->execute([$startDate.' 00:00:00',$id]);
        $ins=db()->prepare("INSERT INTO order_days(order_id,day_no,is_extension,required_photo_count,status) VALUES(?,?,0,?,'planned')");
        for($d=1;$d<=(int)$o['required_success_days'];$d++)$ins->execute([$id,$d,$o['daily_photo_count']]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    log_event((int)$o['offer_id'],$id,'precheck.approved',['start_date'=>$startDate]);sync_offer_status((int)$o['offer_id']);
    flash('success','Vorabkontrolle freigegeben. Start: '.date_de($start).'.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$id);
}

if (preg_match('#^/admin/order/(\d+)/reject-precheck$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$reason=post('reason');
    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck'){flash('error','Diese Vorabkontrolle kann nicht mehr zurückgewiesen werden.');redirect('/admin/order/'.$id);}
    if($reason===''){flash('error','Bitte einen Zurückweisungsgrund angeben.');redirect('/admin/order/'.$id);}
    $q=db()->prepare('SELECT id,file_path FROM precheck_uploads WHERE order_id=?');$q->execute([$id]);$uploads=$q->fetchAll();
    if(!$uploads){flash('error','Es sind noch keine Vorabfotos vorhanden.');redirect('/admin/order/'.$id);}
    db()->beginTransaction();
    try{
        db()->prepare('DELETE FROM precheck_uploads WHERE order_id=?')->execute([$id]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    foreach($uploads as $u){
        $rel=ltrim((string)$u['file_path'],'/');
        if($rel!=='' && !str_contains($rel,'..') && !str_contains($rel,"\0")){
            $file=APP_ROOT.'/storage/private/'.$rel;
            if(is_file($file)) @unlink($file);
        }
    }
    log_event((int)$o['offer_id'],$id,'precheck.rejected',['reason'=>$reason]);
    flash('success','Vorabkontrolle zurückgewiesen. Die Verkäuferin kann die Fotos neu einreichen.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$id);
}

if (preg_match('#^/admin/day/(\d+)/review$#',$path,$m) && $method==='POST') {
    $a=require_admin();$dayId=(int)$m[1];$decision=post('decision');
    if(!in_array($decision,['fulfilled','not_fulfilled'],true)){flash('error','Ungültige Entscheidung.');redirect('/admin/orders');}
    $q=db()->prepare('SELECT d.*,o.offer_id,o.daily_photo_count,o.required_success_days FROM order_days d JOIN orders o ON o.id=d.order_id WHERE d.id=?');$q->execute([$dayId]);$d=$q->fetch();if(!$d)not_found();
    if($d['status']!=='submitted'){flash('error','Dieser Tag wurde bereits bewertet.');redirect('/admin/order/'.$d['order_id']);}
    db()->beginTransaction();
    try{
        db()->prepare('UPDATE order_days SET status=?,admin_note=?,reviewed_at=NOW(),reviewed_by=?,updated_at=NOW() WHERE id=?')->execute([$decision,post('admin_note')?:null,$a['id'],$dayId]);
        if($decision==='not_fulfilled'){
            $q=db()->prepare('SELECT id FROM order_days WHERE extension_for_day_id=?');$q->execute([$dayId]);
            if(!$q->fetchColumn()){
                $q=db()->prepare('SELECT COALESCE(MAX(day_no),0)+1 FROM order_days WHERE order_id=?');$q->execute([$d['order_id']]);$next=(int)$q->fetchColumn();
                db()->prepare("INSERT INTO order_days(order_id,day_no,is_extension,extension_for_day_id,required_photo_count,status) VALUES(?,?,1,?,?,'planned')")->execute([$d['order_id'],$next,$dayId,$d['daily_photo_count']]);
                db()->prepare('UPDATE orders SET extension_days=extension_days+1,updated_at=NOW() WHERE id=?')->execute([$d['order_id']]);
            }
        }
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    log_event((int)$d['offer_id'],(int)$d['order_id'],'day.reviewed',['day_id'=>$dayId,'decision'=>$decision]);
    sync_order_progress((int)$d['order_id']);
    flash('success',$decision==='fulfilled'?'Tag als erfüllt bestätigt.':'Tag nicht erfüllt: ein zusätzlicher Tag wurde angehängt.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$d['order_id']);
}

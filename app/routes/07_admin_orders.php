<?php
declare(strict_types=1);

// ADMIN REVIEW CENTER
if ($path==='/admin/reviews' && $method==='GET') {
    require_admin();

    $pre=db()->query("SELECT o.*,s.first_name,s.last_name,
        (SELECT COUNT(*) FROM precheck_uploads p WHERE p.order_id=o.id) photo_count
        FROM orders o
        JOIN sellers s ON s.id=o.seller_id
        WHERE o.status='precheck'
          AND (SELECT COUNT(*) FROM precheck_uploads p2 WHERE p2.order_id=o.id)>=o.precheck_photo_count
        ORDER BY o.created_at")->fetchAll();

    $submitted=db()->query("SELECT d.*,o.order_no,o.title_snapshot,o.started_at,o.offer_id,o.daily_photo_count,o.required_success_days,o.is_final_day_position,o.align_to_offer_end,s.first_name,s.last_name
        FROM order_days d
        JOIN orders o ON o.id=d.order_id
        JOIN sellers s ON s.id=o.seller_id
        WHERE d.status='submitted'
        ORDER BY d.submitted_at,d.id")->fetchAll();
    foreach($submitted as &$x)$x['_missed']=false;unset($x);

    $candidates=db()->query("SELECT d.*,o.order_no,o.title_snapshot,o.started_at,o.offer_id,o.daily_photo_count,o.required_success_days,o.is_final_day_position,o.align_to_offer_end,s.first_name,s.last_name
        FROM order_days d
        JOIN orders o ON o.id=d.order_id
        JOIN sellers s ON s.id=o.seller_id
        WHERE o.status='running' AND d.status='planned'
          AND d.id=(SELECT d2.id FROM order_days d2 WHERE d2.order_id=o.id AND d2.status IN('planned','submitted') ORDER BY d2.day_no LIMIT 1)
        ORDER BY d.day_no")->fetchAll();
    $missed=[];
    foreach($candidates as $d){
        if(day_is_missed($d,$d)){$d['_missed']=true;$missed[]=$d;}
    }
    $days=array_merge($submitted,$missed);

    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Prüfcenter</span><h1>Offene Prüfungen</h1><p>Vorabkontrollen, vollständige Tagesnachweise und verpasste Pflichtfenster.</p></div></div>
    <div class="stats">
        <div class="stat"><span>Vorabkontrollen</span><strong><?=count($pre)?></strong></div>
        <div class="stat"><span>Tagesnachweise</span><strong><?=count($submitted)?></strong></div>
        <div class="stat"><span>Frist verpasst</span><strong><?=count($missed)?></strong></div>
        <div class="stat"><span>Gesamt offen</span><strong><?=count($pre)+count($days)?></strong></div>
    </div>

    <div class="section-head"><h2>Vorabkontrollen</h2></div>
    <?php if($pre):?><div class="review-grid">
    <?php foreach($pre as $o):
        $uq=db()->prepare('SELECT * FROM precheck_uploads WHERE order_id=? ORDER BY id');$uq->execute([$o['id']]);$ups=$uq->fetchAll();
        $autoAligned=!empty($o['align_to_offer_end']);
        $autoDate=$autoAligned?offer_aligned_start_date((int)$o['offer_id'],(int)$o['required_success_days']):null;
    ?>
        <article class="panel review-card">
            <div class="card-top"><div><span class="eyebrow"><?=e($o['order_no'])?></span><h3><?=e($o['title_snapshot'])?></h3><p class="muted"><?=e($o['first_name'].' '.$o['last_name'])?></p></div><span class="status status-precheck">Vorabkontrolle</span></div>
            <?php if((int)$o['precheck_photo_count']>0):?><p><?=nl2br(e($o['precheck_instructions']))?></p><?php else:?><div class="notice success">Für diese Position sind keine Vorabfotos erforderlich.</div><?php endif;?>
            <div class="photo-grid"><?php foreach($ups as $u):?><a href="<?=e(url('/file/precheck/'.$u['id']))?>" target="_blank"><img src="<?=e(url('/file/precheck/'.$u['id']))?>" alt="Vorabnachweis"></a><?php endforeach;?></div>
            <div class="review-split">
                <form method="post" action="<?=e(url('/admin/order/'.$o['id'].'/approve-precheck'))?>">
                    <input type="hidden" name="return_to" value="reviews">
                    <?php if($autoAligned):?>
                        <div class="notice"><strong>Automatisch ans Angebotsende gekoppelt</strong><br><?=e($autoDate?date_de($autoDate):'Noch nicht berechenbar – zuerst eine mehrtägige Position starten.')?></div>
                    <?php else:?>
                        <label>Startdatum<input type="date" name="start_date" min="<?=e(date('Y-m-d'))?>" value="<?=e(date('Y-m-d'))?>" required></label>
                    <?php endif;?>
                    <button class="btn full" <?=($autoAligned&&!$autoDate)?'disabled':''?>>✓ Freigeben & starten</button>
                </form>
                <?php if((int)$o['precheck_photo_count']>0):?>
                <form method="post" action="<?=e(url('/admin/order/'.$o['id'].'/reject-precheck'))?>">
                    <input type="hidden" name="return_to" value="reviews">
                    <label>Begründung<textarea name="reason" rows="3" required placeholder="Was muss neu fotografiert oder korrigiert werden?"></textarea></label>
                    <button class="btn danger full">↺ Zurückweisen & neu anfordern</button>
                </form>
                <?php else:?><div class="notice success"><strong>Keine Vorabfotos erforderlich.</strong><br>Der Auftrag kann direkt freigegeben werden.</div><?php endif;?>
            </div>
            <a class="text-link" href="<?=e(url('/admin/order/'.$o['id']))?>">Auftrag vollständig öffnen →</a>
        </article>
    <?php endforeach;?></div>
    <?php else:?><div class="empty">Keine vollständige Vorabkontrolle wartet auf Prüfung.</div><?php endif;?>

    <div class="section-head"><h2>Tage prüfen</h2></div>
    <?php if($days):?><div class="review-grid">
    <?php foreach($days as $d):
        $uq=db()->prepare('SELECT u.*,e.label,e.event_no,e.window_start,e.window_end,e.all_day FROM day_uploads u LEFT JOIN order_day_events e ON e.id=u.event_id WHERE u.day_id=? ORDER BY COALESCE(e.event_no,999),u.id');$uq->execute([$d['id']]);$ups=$uq->fetchAll();
        $events=day_events((int)$d['id']);if(!$events)$events=ensure_day_events((int)$d['id'],(int)$d['daily_photo_count']);
        $scheduled=scheduled_order_day_date($d,(int)$d['day_no']);
    ?>
        <article class="panel review-card">
            <div class="card-top">
                <div><span class="eyebrow"><?=e($d['order_no'])?> · Tag <?=e($d['day_no'])?></span><h3><?=e($d['title_snapshot'])?></h3><p class="muted"><?=e($d['first_name'].' '.$d['last_name'])?> · <?=e(date_de($scheduled))?><?=((int)$d['is_extension'])?' · Verlängerung':''?></p></div>
                <span class="status <?=!empty($d['_missed'])?'status-not_fulfilled':'status-submitted'?>"><?=!empty($d['_missed'])?'Frist verpasst':'Zur Prüfung'?></span>
            </div>

            <?php if(!empty($d['_missed'])):?><div class="notice warning"><strong>Mindestens ein Pflicht-Zeitfenster ist ohne Nachweis abgelaufen.</strong> Der Tag kann nicht mehr vollständig eingereicht werden.</div><?php endif;?>
            <?php if($d['seller_note']):?><div class="notice">Kommentar der Verkäuferin: <?=e($d['seller_note'])?></div><?php endif;?>

            <div class="event-review-list">
                <?php foreach($events as $ev):
                    $u=null;foreach($ups as $candidate){if((int)($candidate['event_id']??0)===(int)$ev['id']){$u=$candidate;break;}}
                    $state=event_window_state($ev,$scheduled);
                ?>
                    <div class="event-review-row">
                        <div><strong><?=e($ev['label'])?></strong><span><?=e(event_window_text($ev))?></span></div>
                        <?php if($u):?><a href="<?=e(url('/file/day/'.$u['id']))?>" target="_blank"><img src="<?=e(url('/file/day/'.$u['id']))?>" alt="<?=e($ev['label'])?>"></a>
                        <?php else:?><span class="status <?=e($state==='closed'?'status-not_fulfilled':'status-planned')?>"><?=e($state==='closed'?'Verpasst':'Offen')?></span><?php endif;?>
                    </div>
                <?php endforeach;?>
            </div>

            <?php $finalLocked=!empty($d['align_to_offer_end'])&&(int)$d['required_success_days']===1&&!final_day_positions_ready_for_review((int)$d['offer_id']);?>
            <?php if($finalLocked):?>
                <div class="notice"><strong>Noch nicht final bewertbar.</strong><br>Diese Ein-Tages-Position liegt auf dem gemeinsamen letzten Tag. Zuerst müssen die mehrtägigen Positionen endgültig abgeschlossen sein.</div>
            <?php else:?>
            <form class="review-actions" method="post" action="<?=e(url('/admin/day/'.$d['id'].'/review'))?>">
                <input type="hidden" name="return_to" value="reviews">
                <textarea name="admin_note" rows="3" placeholder="Notiz / Begründung optional"></textarea>
                <div>
                    <?php if(empty($d['_missed'])):?><button class="btn" name="decision" value="fulfilled">✓ Erfüllt</button><?php endif;?>
                    <button class="btn danger" name="decision" value="not_fulfilled">✕ Nicht erfüllt · +1 Tag</button>
                </div>
            </form>
            <?php endif;?>
            <a class="text-link" href="<?=e(url('/admin/order/'.$d['order_id']))?>">Auftrag vollständig öffnen →</a>
        </article>
    <?php endforeach;?></div>
    <?php else:?><div class="empty">Keine Tage warten auf Prüfung.</div><?php endif;?>

    <?php render('Prüfcenter',ob_get_clean());exit;
}

// ADMIN ORDERS LIST
if ($path==='/admin/orders' && $method==='GET') {
    require_admin();
    $orders=db()->query("SELECT o.*,s.first_name,s.last_name FROM orders o JOIN sellers s ON s.id=o.seller_id ORDER BY FIELD(o.status,'running','shipping','precheck','completed','cancelled'),o.created_at DESC")->fetchAll();
    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Aufträge</span><h1>Alle Aufträge</h1></div><a class="btn ghost" href="<?=e(url('/admin/reviews'))?>">Prüfcenter öffnen</a></div>
    <div class="list"><?php foreach($orders as $o):?><a class="list-row" href="<?=e(url('/admin/order/'.$o['id']))?>"><div><strong><?=e($o['order_no'].' · '.$o['title_snapshot'])?></strong><span><?=e($o['first_name'].' '.$o['last_name'])?> · <?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?> erfolgreich · +<?=e($o['extension_days'])?> Tag(e)<?php if($o['started_at']):?> · Start <?=e(date_de(scheduled_order_day_date($o,1)))?><?php endif;?></span></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></a><?php endforeach;?><?php if(!$orders):?><div class="empty">Noch keine Aufträge vorhanden.</div><?php endif;?></div>
    <?php render('Aufträge',ob_get_clean());exit;
}

// ADMIN ORDER DETAIL
if (preg_match('#^/admin/order/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare("SELECT o.*,s.first_name,s.last_name FROM orders o JOIN sellers s ON s.id=o.seller_id WHERE o.id=?");$q->execute([$id]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT * FROM precheck_uploads WHERE order_id=? ORDER BY id');$q->execute([$id]);$pre=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM order_days WHERE order_id=? ORDER BY day_no');$q->execute([$id]);$days=$q->fetchAll();
    $rejection=latest_precheck_rejection($id);$shipment=shipment_for_order($id);
    $q=db()->prepare('SELECT * FROM seller_wallet_entries WHERE order_id=?');$q->execute([$id]);$wallet=$q->fetch();

    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Auftrag <?=e($o['order_no'])?></span><h1><?=e($o['title_snapshot'])?></h1><p><?=e($o['first_name'].' '.$o['last_name'])?> · <?=money($o['compensation'])?></p></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></div>
    <div class="stats">
        <div class="stat"><span>Erfolgreich</span><strong><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?></strong></div>
        <div class="stat"><span>Verlängerung</span><strong>+<?=e($o['extension_days'])?></strong></div>
        <div class="stat"><span>Start</span><strong class="stat-date"><?=e($o['started_at']?date_de(scheduled_order_day_date($o,1)):'–')?></strong></div>
        <div class="stat"><span>Wallet</span><strong class="stat-date"><?=e($wallet?wallet_status_label($wallet['status']):'–')?></strong></div>
    </div>

    <section class="panel">
        <div class="section-head"><h2>Vorabkontrolle</h2><span class="muted"><?=count($pre)?>/<?=e($o['precheck_photo_count'])?> Fotos vorhanden</span></div>
        <p><?=nl2br(e($o['precheck_instructions']))?></p>
        <?php if($rejection && $o['status']==='precheck'):?><div class="notice warning"><strong>Letzte Rückmeldung:</strong> <?=e($rejection)?></div><?php endif;?>
        <?php if($pre):?><div class="photo-grid"><?php foreach($pre as $p):?><a href="<?=e(url('/file/precheck/'.$p['id']))?>" target="_blank"><img src="<?=e(url('/file/precheck/'.$p['id']))?>" alt="Vorabnachweis"></a><?php endforeach;?></div><?php endif;?>
        <?php if($o['status']==='precheck'):?>
            <?php $autoAligned=!empty($o['align_to_offer_end']);$autoDate=$autoAligned?offer_aligned_start_date((int)$o['offer_id'],(int)$o['required_success_days']):null;?>
            <div class="review-split">
                <form method="post" action="<?=e(url('/admin/order/'.$id.'/approve-precheck'))?>">
                    <?php if($autoAligned):?>
                        <div class="notice"><strong>Synchronisierter Zeitraum</strong><br><?=e($autoDate?date_de($autoDate):'Noch nicht berechenbar – zuerst eine mehrtägige Position starten.')?></div>
                    <?php else:?>
                        <label>Geplanter Start<input type="date" name="start_date" min="<?=e(date('Y-m-d'))?>" value="<?=e(date('Y-m-d'))?>" required></label>
                    <?php endif;?>
                    <button class="btn full" <?=(count($pre)<(int)$o['precheck_photo_count']||($autoAligned&&!$autoDate))?'disabled':''?>>✓ Freigeben & starten</button>
                </form>
                <?php if((int)$o['precheck_photo_count']>0):?>
                <form method="post" action="<?=e(url('/admin/order/'.$id.'/reject-precheck'))?>">
                    <label>Zurückweisungsgrund<textarea name="reason" rows="3" required placeholder="Was soll erneut eingereicht werden?"></textarea></label>
                    <button class="btn danger full" <?=!$pre?'disabled':''?>>↺ Zurückweisen</button>
                </form>
                <?php else:?><div class="notice success">Keine Vorabkontrolle nötig.</div><?php endif;?>
            </div>
        <?php endif;?>
    </section>

    <?php if($days):?>
    <section class="panel"><h2>Durchführungstage</h2><div class="day-list">
    <?php foreach($days as $d):
        $scheduled=scheduled_order_day_date($o,(int)$d['day_no']);
        $events=day_events((int)$d['id']);if(!$events && $d['status']==='planned')$events=ensure_day_events((int)$d['id'],(int)$d['required_photo_count']);
        $missed=day_is_missed($d,$o);
        $uq=db()->prepare('SELECT u.*,e.label,e.event_no FROM day_uploads u LEFT JOIN order_day_events e ON e.id=u.event_id WHERE u.day_id=? ORDER BY COALESCE(e.event_no,999),u.id');$uq->execute([$d['id']]);$ups=$uq->fetchAll();
    ?>
        <div class="day-row">
            <div class="day-main">
                <div class="day-title"><strong>Tag <?=e($d['day_no'])?><?=((int)$d['is_extension'])?' · Verlängerung':''?></strong><span class="day-date"><?=e(date_de($scheduled))?></span></div>
                <span class="status <?=e($missed?'status-not_fulfilled':'status-'.$d['status'])?>"><?=e($missed?'Frist verpasst':day_status_label($d['status']))?></span>
                <?php if($d['admin_note']):?><p class="muted">Admin-Notiz: <?=e($d['admin_note'])?></p><?php endif;?>
                <?php if($events):?><div class="event-mini-list"><?php foreach($events as $ev):
                    $state=event_window_state($ev,$scheduled);
                    $hasUpload=false;foreach($ups as $u){if((int)($u['event_id']??0)===(int)$ev['id']){$hasUpload=true;break;}}
                ?><div><span><strong><?=e($ev['label'])?></strong> · <?=e(event_window_text($ev))?></span><span class="status <?=e($hasUpload?'status-fulfilled':($state==='closed'?'status-not_fulfilled':'status-planned'))?>"><?=e($hasUpload?'Eingereicht':($state==='closed'?'Verpasst':'Offen'))?></span></div><?php endforeach;?></div><?php endif;?>
                <div class="photo-grid small"><?php foreach($ups as $u):?><figure class="evidence-photo"><figcaption><?=e($u['label']??'Nachweis')?></figcaption><a href="<?=e(url('/file/day/'.$u['id']))?>" target="_blank"><img src="<?=e(url('/file/day/'.$u['id']))?>" alt="<?=e($u['label']??'Tagesnachweis')?>"></a></figure><?php endforeach;?></div>
            </div>

            <?php if($d['status']==='submitted' || $missed):
                $finalLocked=!empty($o['align_to_offer_end'])&&(int)$o['required_success_days']===1&&!final_day_positions_ready_for_review((int)$o['offer_id']);
            ?>
                <?php if($finalLocked):?>
                    <div class="notice"><strong>Synchronisierte Ein-Tages-Position:</strong><br>Die Bewertung wird freigeschaltet, sobald alle mehrtägigen Positionen dieses Angebots endgültig abgeschlossen sind.</div>
                <?php else:?>
                <form class="review-actions" method="post" action="<?=e(url('/admin/day/'.$d['id'].'/review'))?>">
                    <textarea name="admin_note" rows="2" placeholder="Notiz optional"></textarea>
                    <div>
                        <?php if(!$missed):?><button class="btn" name="decision" value="fulfilled">✓ Erfüllt</button><?php endif;?>
                        <button class="btn danger" name="decision" value="not_fulfilled">✕ Nicht erfüllt · +1 Tag</button>
                    </div>
                </form>
                <?php endif;?>
            <?php endif;?>
        </div>
    <?php endforeach;?></div></section>
    <?php endif;?>

    <?php if($shipment):?>
    <section class="panel">
        <div class="section-head"><h2>Versand</h2><span class="status <?=e($shipment['status']==='confirmed'?'status-fulfilled':'status-shipping')?>"><?=e($shipment['status']==='confirmed'?'Bestätigt':'Offen')?></span></div>
        <p><strong>Fällig:</strong> <?=e(date_de(new DateTimeImmutable($shipment['due_date'])))?></p>
        <address class="shipping-address"><?php if($shipment['address_keyword']):?><strong>Kennwort: <?=e($shipment['address_keyword'])?></strong><br><?php endif;?><?=e($shipment['address_name']??'')?><?php if($shipment['extra']):?><br><?=nl2br(e($shipment['extra']))?><?php endif;?><?php if($shipment['street']):?><br><?=e($shipment['street'])?><?php endif;?><?php if($shipment['postal_code']||$shipment['city']):?><br><?=e(trim(($shipment['postal_code']??'').' '.($shipment['city']??'')))?><?php endif;?><?php if($shipment['country']&&$shipment['country']!=='Deutschland'):?><br><?=e($shipment['country'])?><?php endif;?></address>
        <?php if($shipment['confirmed_at']):?><p>Bestätigt: <?=e(date('d.m.Y H:i',strtotime($shipment['confirmed_at'])))?> Uhr<?php if($shipment['tracking_number']):?> · Sendungsnummer <?=e($shipment['tracking_number'])?><?php endif;?></p><?php endif;?>
    </section>
    <?php endif;?>

    <?php render('Auftrag '.$o['order_no'],ob_get_clean());exit;
}

// PRECHECK APPROVE
if (preg_match('#^/admin/order/(\d+)/approve-precheck$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck'){flash('error','Vorabkontrolle ist bereits abgeschlossen.');redirect('/admin/order/'.$id);}
    $c=db()->prepare('SELECT COUNT(*) FROM precheck_uploads WHERE order_id=?');$c->execute([$id]);
    if((int)$c->fetchColumn()<(int)$o['precheck_photo_count']){flash('error','Es fehlen Vorabfotos.');redirect('/admin/order/'.$id);}
    $today=new DateTimeImmutable('today');
    if(!empty($o['align_to_offer_end'])){
        $start=offer_aligned_start_date((int)$o['offer_id'],(int)$o['required_success_days']);
        if(!$start){flash('error','Der gekoppelte Zeitraum ist noch nicht berechenbar. Bitte zuerst mindestens eine nicht gekoppelte mehrtägige Position starten.');redirect('/admin/order/'.$id);}
        $startDate=$start->format('Y-m-d');
    }else{
        $startDate=post('start_date');$start=DateTimeImmutable::createFromFormat('!Y-m-d',$startDate);
        if(!$start||$start->format('Y-m-d')!==$startDate||$start<$today){flash('error','Bitte ein gültiges Startdatum ab heute wählen.');redirect('/admin/order/'.$id);}
    }

    db()->beginTransaction();
    try{
        db()->prepare("UPDATE orders SET status='running',precheck_approved_at=NOW(),started_at=?,updated_at=NOW() WHERE id=?")->execute([$startDate.' 00:00:00',$id]);
        $ins=db()->prepare("INSERT INTO order_days(order_id,day_no,is_extension,required_photo_count,status) VALUES(?,?,0,?,'planned')");
        for($d=1;$d<=(int)$o['required_success_days'];$d++){
            $ins->execute([$id,$d,$o['daily_photo_count']]);
            ensure_day_events((int)db()->lastInsertId(),(int)$o['daily_photo_count']);
        }
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}

    log_event((int)$o['offer_id'],$id,'precheck.approved',['start_date'=>$startDate,'end_aligned'=>(bool)$o['align_to_offer_end']]);
    sync_end_aligned_positions((int)$o['offer_id']);
    sync_offer_status((int)$o['offer_id']);
    flash('success','Vorabkontrolle freigegeben. Start: '.date_de($start).'.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$id);
}

// PRECHECK REJECT
if (preg_match('#^/admin/order/(\d+)/reject-precheck$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$reason=post('reason');
    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$id]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck'){flash('error','Diese Vorabkontrolle kann nicht mehr zurückgewiesen werden.');redirect('/admin/order/'.$id);}
    if($reason===''){flash('error','Bitte einen Zurückweisungsgrund angeben.');redirect('/admin/order/'.$id);}
    $q=db()->prepare('SELECT id,file_path FROM precheck_uploads WHERE order_id=?');$q->execute([$id]);$uploads=$q->fetchAll();
    if(!$uploads){flash('error','Es sind noch keine Vorabfotos vorhanden.');redirect('/admin/order/'.$id);}

    db()->beginTransaction();
    try{db()->prepare('DELETE FROM precheck_uploads WHERE order_id=?')->execute([$id]);db()->commit();}
    catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}

    foreach($uploads as $u){
        $rel=ltrim((string)$u['file_path'],'/');
        if($rel!==''&&!str_contains($rel,'..')&&!str_contains($rel,"\0")){
            $file=APP_ROOT.'/storage/private/'.$rel;if(is_file($file))@unlink($file);
        }
    }
    log_event((int)$o['offer_id'],$id,'precheck.rejected',['reason'=>$reason]);
    flash('success','Vorabkontrolle zurückgewiesen. Die Verkäuferin kann die Fotos neu einreichen.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$id);
}

// DAY REVIEW: complete submissions or expired incomplete day
if (preg_match('#^/admin/day/(\d+)/review$#',$path,$m) && $method==='POST') {
    $a=require_admin();$dayId=(int)$m[1];$decision=post('decision');
    if(!in_array($decision,['fulfilled','not_fulfilled'],true)){flash('error','Ungültige Entscheidung.');redirect('/admin/orders');}

    $q=db()->prepare("SELECT d.*,o.offer_id,o.daily_photo_count,o.required_success_days,o.started_at AS order_started_at,o.status AS order_status,o.is_final_day_position,o.align_to_offer_end
        FROM order_days d JOIN orders o ON o.id=d.order_id WHERE d.id=?");
    $q->execute([$dayId]);$d=$q->fetch();if(!$d)not_found();

    $orderCtx=['started_at'=>$d['order_started_at'],'offer_id'=>$d['offer_id'],'required_success_days'=>$d['required_success_days'],'is_final_day_position'=>$d['is_final_day_position'],'align_to_offer_end'=>$d['align_to_offer_end']];
    $missed=$d['status']==='planned'&&$d['order_status']==='running'&&day_is_missed($d,$orderCtx);
    if(!empty($d['align_to_offer_end'])&&(int)$d['required_success_days']===1&&!final_day_positions_ready_for_review((int)$d['offer_id'])){
        flash('error','Diese Ein-Tages-Position kann erst bewertet werden, wenn alle mehrtägigen Positionen endgültig abgeschlossen sind.');
        redirect('/admin/order/'.$d['order_id']);
    }
    if($d['status']!=='submitted'&&!$missed){flash('error','Dieser Tag ist noch nicht prüfbar.');redirect('/admin/order/'.$d['order_id']);}
    if($missed&&$decision!=='not_fulfilled'){flash('error','Ein Tag mit verpasstem Pflichtfenster kann nicht als erfüllt bewertet werden.');redirect('/admin/order/'.$d['order_id']);}

    db()->beginTransaction();
    try{
        db()->prepare('UPDATE order_days SET status=?,admin_note=?,reviewed_at=NOW(),reviewed_by=?,updated_at=NOW() WHERE id=?')
            ->execute([$decision,post('admin_note')?:null,$a['id'],$dayId]);

        if($decision==='not_fulfilled'){
            $q=db()->prepare('SELECT id FROM order_days WHERE extension_for_day_id=?');$q->execute([$dayId]);
            if(!$q->fetchColumn()){
                $q=db()->prepare('SELECT COALESCE(MAX(day_no),0)+1 FROM order_days WHERE order_id=?');$q->execute([$d['order_id']]);$next=(int)$q->fetchColumn();
                db()->prepare("INSERT INTO order_days(order_id,day_no,is_extension,extension_for_day_id,required_photo_count,status) VALUES(?,?,1,?,?,'planned')")
                    ->execute([$d['order_id'],$next,$dayId,$d['daily_photo_count']]);
                ensure_day_events((int)db()->lastInsertId(),(int)$d['daily_photo_count']);
                db()->prepare('UPDATE orders SET extension_days=extension_days+1,updated_at=NOW() WHERE id=?')->execute([$d['order_id']]);
            }
        }
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}

    log_event((int)$d['offer_id'],(int)$d['order_id'],'day.reviewed',['day_id'=>$dayId,'decision'=>$decision,'missed_window'=>$missed]);
    if($decision==='not_fulfilled' && empty($d['align_to_offer_end']))sync_end_aligned_positions((int)$d['offer_id']);
    sync_order_progress((int)$d['order_id']);
    flash('success',$decision==='fulfilled'?'Tag als erfüllt bestätigt.':'Tag nicht erfüllt: ein zusätzlicher Tag wurde angehängt.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$d['order_id']);
}

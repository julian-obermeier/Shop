<?php
declare(strict_types=1);

function correction_reason(): string {
    $reason=post('reason');
    if($reason==='')throw new RuntimeException('Bitte einen Grund für die Korrektur angeben.');
    return mb_substr($reason,0,1500);
}

if (preg_match('#^/admin/event/(\d+)/request-resubmission$#',$path,$m) && $method==='POST') {
    $a=require_admin();$eventId=(int)$m[1];
    try{$reason=correction_reason();}catch(Throwable $e){flash('error',$e->getMessage());redirect('/admin/reviews');}

    $q=db()->prepare("SELECT e.*,d.id day_id,d.day_no,d.status day_status,d.order_id,
        o.offer_id,o.seller_id,o.order_no,o.status order_status
        FROM order_day_events e
        JOIN order_days d ON d.id=e.day_id
        JOIN orders o ON o.id=d.order_id
        WHERE e.id=?");
    $q->execute([$eventId]);$ev=$q->fetch();if(!$ev)not_found();
    if($ev['order_status']!=='running'||!in_array($ev['day_status'],['planned','submitted'],true)){
        flash('error','Dieser Nachweis kann in diesem Status nicht erneut angefordert werden.');redirect('/admin/order/'.$ev['order_id']);
    }
    if($ev['status']!=='submitted'){flash('error','Dieser Nachweis wurde noch nicht eingereicht.');redirect('/admin/order/'.$ev['order_id']);}

    $q=db()->prepare('SELECT * FROM day_uploads WHERE event_id=? ORDER BY id');
    $q->execute([$eventId]);$uploads=$q->fetchAll();
    if(!$uploads){flash('error','Zu diesem Nachweis wurde keine Datei gefunden.');redirect('/admin/order/'.$ev['order_id']);}

    db()->beginTransaction();
    try{
        db()->prepare('DELETE FROM day_uploads WHERE event_id=?')->execute([$eventId]);
        db()->prepare("UPDATE order_day_events SET status='planned',review_note=?,resubmission_requested_at=NOW(),
            resubmission_requested_by=?,submitted_at=NULL,updated_at=NOW() WHERE id=?")
            ->execute([$reason,$a['id'],$eventId]);
        db()->prepare("UPDATE order_days SET status='planned',submitted_at=NULL,late_submission_allowed=1,
            late_submission_note=?,late_submission_requested_at=NOW(),late_submission_requested_by=?,updated_at=NOW() WHERE id=?")
            ->execute([$reason,$a['id'],$ev['day_id']]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();throw $e;
    }
    foreach($uploads as $u){
        $rel=ltrim((string)$u['file_path'],'/');
        if($rel!==''&&!str_contains($rel,'..')&&!str_contains($rel,"\0")){
            $file=APP_ROOT.'/storage/private/'.$rel;if(is_file($file))@unlink($file);
        }
    }
    notify_seller((int)$ev['seller_id'],'evidence','Ein Nachweis muss erneut eingereicht werden',
        $ev['order_no'].' · Tag '.$ev['day_no'].' · '.$ev['label'].":
".$reason,
        '/seller/order/'.$ev['order_id'],'evidence-resubmit:'.$eventId.':'.time());
    log_event((int)$ev['offer_id'],(int)$ev['order_id'],'evidence_event.resubmission_requested',[
        'event_id'=>$eventId,'day_no'=>(int)$ev['day_no'],'label'=>$ev['label'],'reason'=>$reason
    ]);
    flash('success','Der einzelne Nachweis wurde verworfen und erneut angefordert.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$ev['order_id']);
}

if (preg_match('#^/admin/order/(\d+)/change-start$#',$path,$m) && $method==='POST') {
    require_admin();$orderId=(int)$m[1];
    try{$reason=correction_reason();}catch(Throwable $e){flash('error',$e->getMessage());redirect('/admin/order/'.$orderId);}
    $date=post('start_date');$start=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$start||$start->format('Y-m-d')!==$date){flash('error','Bitte ein gültiges Startdatum angeben.');redirect('/admin/order/'.$orderId);}

    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$orderId]);$o=$q->fetch();if(!$o)not_found();
    if(!in_array($o['status'],['running','precheck'],true)){flash('error','Das Startdatum kann in diesem Status nicht geändert werden.');redirect('/admin/order/'.$orderId);}
    if(!empty($o['align_to_offer_end'])){flash('error','Endgekoppelte Positionen werden über die Basisposition korrigiert.');redirect('/admin/order/'.$orderId);}
    $old=$o['started_at'];

    db()->beginTransaction();
    try{
        if(!empty($o['sync_start_with_offer'])){
            db()->prepare("UPDATE orders SET started_at=?,updated_at=NOW()
                WHERE offer_id=? AND sync_start_with_offer=1 AND align_to_offer_end=0 AND status<>'cancelled'")
                ->execute([$date.' 00:00:00',$o['offer_id']]);
        }else{
            db()->prepare('UPDATE orders SET started_at=?,updated_at=NOW() WHERE id=?')->execute([$date.' 00:00:00',$orderId]);
        }
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}

    sync_end_aligned_positions((int)$o['offer_id']);
    notify_seller((int)$o['seller_id'],'correction','Startdatum wurde korrigiert',
        $o['order_no'].' startet nun am '.$start->format('d.m.Y').'. Grund: '.$reason,
        '/seller/order/'.$orderId,'start-correction:'.$orderId.':'.time());
    log_event((int)$o['offer_id'],$orderId,'order.start_corrected',['from'=>$old,'to'=>$date,'reason'=>$reason]);
    flash('success','Startdatum wurde korrigiert und gekoppelte Positionen wurden neu synchronisiert.');
    redirect('/admin/order/'.$orderId);
}

if (preg_match('#^/admin/order/(\d+)/add-manual-day$#',$path,$m) && $method==='POST') {
    require_admin();$orderId=(int)$m[1];
    try{$reason=correction_reason();}catch(Throwable $e){flash('error',$e->getMessage());redirect('/admin/order/'.$orderId);}
    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$orderId]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='running'||!$o['started_at']){flash('error','Ein zusätzlicher Tag kann nur einem laufenden terminierten Auftrag hinzugefügt werden.');redirect('/admin/order/'.$orderId);}

    db()->beginTransaction();
    try{
        $q=db()->prepare('SELECT COALESCE(MAX(day_no),0)+1 FROM order_days WHERE order_id=? FOR UPDATE');$q->execute([$orderId]);$next=(int)$q->fetchColumn();
        db()->prepare("INSERT INTO order_days(order_id,day_no,is_extension,manual_extension,required_photo_count,status,admin_note)
            VALUES(?,?,1,1,?,'planned',?)")->execute([$orderId,$next,$o['daily_photo_count'],$reason]);
        $dayId=(int)db()->lastInsertId();ensure_day_events($dayId,(int)$o['daily_photo_count']);
        db()->prepare('UPDATE orders SET required_success_days=required_success_days+1,extension_days=extension_days+1,updated_at=NOW() WHERE id=?')->execute([$orderId]);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}

    sync_end_aligned_positions((int)$o['offer_id']);
    notify_seller((int)$o['seller_id'],'correction','Zusätzlicher Durchführungstag',
        $o['order_no'].' wurde um einen zusätzlichen Pflichttag erweitert. Grund: '.$reason,
        '/seller/order/'.$orderId,'manual-day:'.$dayId);
    log_event((int)$o['offer_id'],$orderId,'order.manual_day_added',['day_no'=>$next,'reason'=>$reason]);
    flash('success','Ein zusätzlicher Pflichttag wurde angehängt.');
    redirect('/admin/order/'.$orderId);
}

if (preg_match('#^/admin/order/(\d+)/remove-manual-day$#',$path,$m) && $method==='POST') {
    require_admin();$orderId=(int)$m[1];
    try{$reason=correction_reason();}catch(Throwable $e){flash('error',$e->getMessage());redirect('/admin/order/'.$orderId);}
    $q=db()->prepare('SELECT * FROM orders WHERE id=?');$q->execute([$orderId]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare("SELECT d.*,(SELECT COUNT(*) FROM day_uploads u WHERE u.day_id=d.id) uploads
        FROM order_days d WHERE d.order_id=? AND d.manual_extension=1 ORDER BY d.day_no DESC LIMIT 1");
    $q->execute([$orderId]);$d=$q->fetch();
    if(!$d||(int)$d['uploads']>0||$d['status']!=='planned'){flash('error','Es gibt keinen unberührten manuell hinzugefügten Tag, der entfernt werden kann.');redirect('/admin/order/'.$orderId);}

    db()->beginTransaction();
    try{
        db()->prepare('DELETE FROM order_days WHERE id=?')->execute([$d['id']]);
        db()->prepare('UPDATE orders SET required_success_days=GREATEST(1,required_success_days-1),extension_days=GREATEST(0,extension_days-1),updated_at=NOW() WHERE id=?')->execute([$orderId]);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    sync_end_aligned_positions((int)$o['offer_id']);
    notify_seller((int)$o['seller_id'],'correction','Zusätzlicher Tag entfernt',
        $o['order_no'].' wurde korrigiert; ein manuell hinzugefügter Tag entfällt. Grund: '.$reason,
        '/seller/order/'.$orderId,'manual-day-removed:'.$d['id'].':'.time());
    log_event((int)$o['offer_id'],$orderId,'order.manual_day_removed',['day_no'=>(int)$d['day_no'],'reason'=>$reason]);
    flash('success','Der manuell hinzugefügte Tag wurde entfernt.');
    redirect('/admin/order/'.$orderId);
}

if (preg_match('#^/admin/day/(\d+)/reset-review$#',$path,$m) && $method==='POST') {
    require_admin();$dayId=(int)$m[1];
    try{$reason=correction_reason();}catch(Throwable $e){flash('error',$e->getMessage());redirect('/admin/reviews');}
    $q=db()->prepare("SELECT d.*,o.offer_id,o.seller_id,o.order_no,o.status order_status,o.id order_id
        FROM order_days d JOIN orders o ON o.id=d.order_id WHERE d.id=?");
    $q->execute([$dayId]);$d=$q->fetch();if(!$d)not_found();
    if(!in_array($d['status'],['fulfilled','not_fulfilled'],true)){flash('error','Für diesen Tag gibt es keine abgeschlossene Entscheidung zurückzusetzen.');redirect('/admin/order/'.$d['order_id']);}
    if($d['order_status']==='completed'){flash('error','Bei vollständig abgeschlossenem Versand kann die Tagesentscheidung nicht mehr zurückgesetzt werden.');redirect('/admin/order/'.$d['order_id']);}

    if($d['status']==='not_fulfilled'){
        $q=db()->prepare("SELECT x.*,(SELECT COUNT(*) FROM day_uploads u WHERE u.day_id=x.id) uploads
            FROM order_days x WHERE x.extension_for_day_id=? LIMIT 1");
        $q->execute([$dayId]);$ext=$q->fetch();
        if($ext&&($ext['status']!=='planned'||(int)$ext['uploads']>0)){flash('error','Der zugehörige Verlängerungstag wurde bereits bearbeitet. Die Entscheidung kann nicht mehr automatisch zurückgesetzt werden.');redirect('/admin/order/'.$d['order_id']);}
    }else{$ext=null;}

    $q=db()->prepare("SELECT COUNT(*) total,SUM(status='submitted') submitted FROM order_day_events WHERE day_id=?");
    $q->execute([$dayId]);$counts=$q->fetch();$newStatus=((int)$counts['total']>0&&(int)$counts['submitted']===(int)$counts['total'])?'submitted':'planned';

    db()->beginTransaction();
    try{
        if($ext){
            db()->prepare('DELETE FROM order_days WHERE id=?')->execute([$ext['id']]);
            db()->prepare('UPDATE orders SET extension_days=GREATEST(0,extension_days-1) WHERE id=?')->execute([$d['order_id']]);
        }
        if($d['order_status']==='shipping'){
            db()->prepare("DELETE FROM order_shipments WHERE order_id=? AND status='pending'")->execute([$d['order_id']]);
            db()->prepare("UPDATE seller_wallet_entries SET status='reserved',available_at=NULL,updated_at=NOW() WHERE order_id=? AND status='available'")->execute([$d['order_id']]);
            db()->prepare("UPDATE orders SET status='running',completed_at=NULL,updated_at=NOW() WHERE id=?")->execute([$d['order_id']]);
        }
        db()->prepare("UPDATE order_days SET status=?,admin_note=?,fulfilled_by_override=0,reviewed_at=NULL,reviewed_by=NULL,updated_at=NOW() WHERE id=?")
            ->execute([$newStatus,'Korrektur: '.$reason,$dayId]);
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}

    sync_order_progress((int)$d['order_id']);
    notify_seller((int)$d['seller_id'],'correction','Tagesentscheidung wurde korrigiert',
        $d['order_no'].' · Tag '.$d['day_no'].' wird erneut geprüft. Grund: '.$reason,
        '/seller/order/'.$d['order_id'],'day-reset:'.$dayId.':'.time());
    log_event((int)$d['offer_id'],(int)$d['order_id'],'day.review_reset',['day_id'=>$dayId,'from'=>$d['status'],'to'=>$newStatus,'reason'=>$reason]);
    flash('success','Die Tagesentscheidung wurde zurückgesetzt.');
    redirect('/admin/order/'.$d['order_id']);
}

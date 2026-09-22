<?php
declare(strict_types=1);
// SELLER ORDER DETAIL + SEPARATE TIMED EVIDENCE EVENTS
if (preg_match('#^/seller/order/(\d+)$#',$path,$m) && $method==='GET') {
    $s=require_seller();$id=(int)$m[1];
    $q=db()->prepare('SELECT * FROM orders WHERE id=? AND seller_id=?');$q->execute([$id,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT * FROM precheck_uploads WHERE order_id=? ORDER BY id');$q->execute([$id]);$pre=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM order_days WHERE order_id=? ORDER BY day_no');$q->execute([$id]);$days=$q->fetchAll();
    $current=null;foreach($days as $d){if(in_array($d['status'],['planned','submitted'],true)){$current=$d;break;}}
    $rejection=latest_precheck_rejection($id);
    $currentDate=$current?scheduled_order_day_date($o,(int)$current['day_no']):null;
    $today=new DateTimeImmutable('today');
    $currentEvents=[];
    if($current){
        $currentEvents=day_events((int)$current['id']);
        if(!$currentEvents && $current['status']==='planned')$currentEvents=ensure_day_events((int)$current['id'],(int)$current['required_photo_count']);
    }
    $shipment=shipment_for_order($id);
    $offerShippingReady=$shipment?offer_ready_for_shipping((int)$o['offer_id']):false;
    $offerShipDue=$shipment?offer_shipping_due_date((int)$o['offer_id']):null;
    $q=db()->prepare('SELECT * FROM seller_wallet_entries WHERE order_id=?');$q->execute([$id]);$wallet=$q->fetch();

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Auftrag <?=e($o['order_no'])?></span><h1><?=e($o['title_snapshot'])?></h1><p><?=money($o['compensation'])?></p></div>
        <span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span>
    </div>
    <div class="stats compact">
        <div class="stat"><span>Erfolgreich</span><strong><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?></strong></div>
        <div class="stat"><span>Verlängerung</span><strong>+<?=e($o['extension_days'])?></strong></div>
        <div class="stat"><span>Wallet</span><strong class="stat-date"><?=e($wallet?wallet_status_label($wallet['status']):'–')?></strong></div>
    </div>

    <?php if($o['status']==='precheck'):?>
    <section class="panel">
        <h2>1. Vorabkontrolle / Startfreigabe</h2>
        <?php if((int)$o['precheck_photo_count']===0):?>
            <div class="notice success"><strong>Keine Vorabfotos erforderlich.</strong><br>Dieser Auftrag wartet nur noch auf die Startfreigabe durch die Plattform.</div>
        <?php else:?>
            <p><?=nl2br(e($o['precheck_instructions']))?></p>
            <?php if($rejection):?><div class="notice warning"><strong>Von der Plattformprüfung zurückgewiesen:</strong><br><?=nl2br(e($rejection))?><br><span>Bitte die Vorabkontrolle vollständig neu einreichen.</span></div><?php endif;?>
            <p class="muted"><?=count($pre)?>/<?=e($o['precheck_photo_count'])?> Fotos hochgeladen</p>
            <?php if($pre):?><div class="photo-grid"><?php foreach($pre as $p):?><a href="<?=e(url('/file/precheck/'.$p['id']))?>" target="_blank"><img src="<?=e(url('/file/precheck/'.$p['id']))?>" alt="Vorabfoto"></a><?php endforeach;?></div><?php endif;?>
            <?php $remaining=(int)$o['precheck_photo_count']-count($pre);if($remaining>0):?>
                <form method="post" enctype="multipart/form-data" action="<?=e(url('/seller/order/'.$id.'/precheck'))?>">
                    <label>Noch <?=e($remaining)?> Foto(s) erforderlich<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple required></label>
                    <button class="btn">Vorabfotos hochladen</button>
                </form>
            <?php else:?><div class="notice success">Vorabkontrolle vollständig eingereicht. Sie wartet auf Freigabe.</div><?php endif;?>
        <?php endif;?>
        <?php if(!empty($o['align_to_offer_end'])):?><div class="notice"><strong>Ans Angebotsende gekoppelt:</strong> Diese Position läuft auf den letzten <?=e($o['required_success_days'])?> Gesamttag(en) und verschiebt sich automatisch mit, wenn sich eine längere Basisposition verlängert.</div><?php endif;?>
    </section>
    <?php endif;?>

    <?php if(in_array($o['status'],['running','shipping','completed'],true)):?>
    <section class="panel">
        <h2>2. Durchführung</h2>
        <p><strong>Je Nachweisvorgang:</strong><br><?=nl2br(e($o['daily_instructions']))?></p>
        <p class="muted"><?=e($o['daily_photo_count'])?> getrennte Nachweisvorgänge pro Tag · jeweils genau 1 Foto · nur im jeweiligen Zeitfenster</p>
        <?php if($o['started_at']):?><div class="notice"><strong><?=!empty($o['align_to_offer_end'])?'Synchronisierter Zeitraum ab':'Geplanter Start'?>:</strong> <?=e(date_de(scheduled_order_day_date($o,1)))?><?php if(!empty($o['align_to_offer_end'])):?><br><span>Diese Position endet gemeinsam mit den längsten Basispositionen und verschiebt sich bei deren Verlängerung automatisch mit.</span><?php endif;?></div>
        <?php elseif($o['status']==='running'&&!empty($o['align_to_offer_end'])):?><div class="notice"><strong>Von der Plattform freigegeben.</strong><br>Der konkrete Durchführungstag wird automatisch festgelegt, sobald der Endtermin der mehrtägigen Basisposition berechenbar ist.</div><?php endif;?>

        <?php if($o['status']==='running' && $current):?>
            <div class="current-day">
                <span class="eyebrow"><?=((int)$current['is_extension'])?'Verlängerungstag':'Aktueller Tag'?></span>
                <h3>Tag <?=e($current['day_no'])?> · <?=e(date_de($currentDate))?></h3>

                <?php if(!empty($current['late_submission_allowed'])):?>
                    <div class="notice warning"><strong>Nachreichung freigegeben.</strong><br>Die Plattform hat die fehlenden Nachweise für diesen Tag erneut geöffnet. Die fehlenden Fotos können jetzt unabhängig vom ursprünglichen Zeitfenster nachgereicht werden.<?php if($current['late_submission_note']):?><br><span><?=nl2br(e($current['late_submission_note']))?></span><?php endif;?></div>
                <?php endif;?>
                <?php if($current['status']==='submitted'):?>
                    <div class="notice success">Alle Nachweisvorgänge dieses Tages wurden einzeln eingereicht. Der Tag wartet jetzt auf Prüfung.</div>
                <?php elseif($currentDate && $currentDate>$today):?>
                    <div class="notice"><strong>Noch nicht freigeschaltet.</strong><br>Dieser Durchführungstag beginnt am <?=e(date_de($currentDate))?>.</div>
                <?php endif;?>

                <?php
                $nextEventId=null;
                foreach($currentEvents as $ev){if($ev['status']==='planned'){$nextEventId=(int)$ev['id'];break;}}
                ?>
                <?php if($currentEvents):?><div class="evidence-events">
                <?php foreach($currentEvents as $ev):
                    $uq=db()->prepare('SELECT * FROM day_uploads WHERE event_id=? ORDER BY id LIMIT 1');$uq->execute([$ev['id']]);$upload=$uq->fetch();
                    $lateForEvent=event_late_submission_allowed($ev,$current,$currentDate);
                    $state=$lateForEvent?'open':event_window_state($ev,$currentDate);
                ?>
                    <article class="evidence-event <?=e($ev['status'])?> window-<?=e($state)?>">
                        <div class="evidence-event-head">
                            <div><span class="eyebrow">Vorgang <?=e($ev['event_no'])?></span><h4><?=e($ev['label'])?></h4><span class="window-time"><?=e(event_window_text($ev))?></span></div>
                            <span class="status status-<?=e($ev['status']==='submitted'?'fulfilled':'planned')?>"><?=e($ev['status']==='submitted'?'Eingereicht':'Offen')?></span>
                        </div>

                        <?php if($upload):?>
                            <a class="event-photo" href="<?=e(url('/file/day/'.$upload['id']))?>" target="_blank"><img src="<?=e(url('/file/day/'.$upload['id']))?>" alt="<?=e($ev['label'])?>"></a>
                            <?php if($ev['seller_note']):?><p class="muted">Kommentar: <?=e($ev['seller_note'])?></p><?php endif;?>
                        <?php elseif((int)$ev['id']!==$nextEventId):?>
                            <div class="event-waiting">Wird nach dem vorherigen Nachweisvorgang freigeschaltet.</div>
                        <?php elseif($state==='open'):?>
                            <form method="post" enctype="multipart/form-data" action="<?=e(url('/seller/event/'.$ev['id'].'/submit'))?>">
                                <label>1 Foto für „<?=e($ev['label'])?>“<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" capture="environment" required></label>
                                <label>Kommentar (optional)<textarea name="seller_note" rows="2"></textarea></label>
                                <button class="btn full"><?=e($lateForEvent?'Nachweis nachreichen':$ev['label'].' einreichen')?></button>
                            </form>
                        <?php elseif($state==='future'):?>
                            <div class="event-waiting">Noch geschlossen · <?=e(event_window_text($ev))?></div>
                        <?php elseif($state==='closed'):?>
                            <div class="notice warning"><strong>Zeitfenster abgelaufen.</strong><br>Dieser Pflichtvorgang kann nicht mehr nachgereicht werden. Der Tag wartet auf die Bewertung durch die Plattform.</div>
                        <?php endif;?>
                    </article>
                <?php endforeach;?>
                </div><?php endif;?>
            </div>
        <?php elseif($o['status']==='shipping'):?>
            <div class="notice success"><strong>Durchführung abgeschlossen.</strong><br>Alle benötigten erfolgreichen Tage wurden erreicht. Als Nächstes folgt der Versand.</div>
        <?php elseif($o['status']==='completed'):?>
            <div class="notice success">Durchführung und Versand vollständig abgeschlossen.</div>
        <?php endif;?>

        <div class="timeline">
        <?php foreach($days as $d):
            $scheduled=scheduled_order_day_date($o,(int)$d['day_no']);
            $events=day_events((int)$d['id']);
            $eventDone=0;foreach($events as $ev){if($ev['status']==='submitted')$eventDone++;}
            $eventTotal=$events?count($events):(int)$d['required_photo_count'];
            if(!$events && $d['status']==='submitted')$eventDone=$eventTotal;
        ?>
            <div class="timeline-row">
                <span class="dot dot-<?=e($d['status'])?>"></span>
                <div>
                    <div><strong>Tag <?=e($d['day_no'])?><?=((int)$d['is_extension'])?' · Verlängerung':''?></strong><span><?=e(date_de($scheduled))?></span></div>
                    <div class="timeline-meta"><span><?=e(day_status_label($d['status']))?></span><span><?=e($eventDone)?>/<?=e($eventTotal)?> Vorgänge eingereicht</span><?php if(!empty($d['fulfilled_by_override'])):?><span>Trotz fehlender Nachweise von der Plattform als erfüllt bestätigt</span><?php endif;?><?php if($d['admin_note']):?><span>Prüfung: <?=e($d['admin_note'])?></span><?php endif;?></div>
                </div>
            </div>
        <?php endforeach;?>
        </div>
    </section>
    <?php endif;?>

    <?php if(in_array($o['status'],['shipping','completed'],true) && $shipment):?>
    <section class="panel shipping-panel">
        <div class="section-head"><h2>3. Gemeinsamer Versand</h2><span class="status status-<?=e($shipment['status']==='confirmed'?'fulfilled':'shipping')?>"><?=e($shipment['status']==='confirmed'?'Versendet':'Versand offen')?></span></div>

        <?php if($o['status']==='shipping' && !$offerShippingReady):?>
            <div class="notice"><strong>Diese Position ist bereits fertig.</strong><br>Die Versandadresse wird erst freigeschaltet, wenn auch alle übrigen Positionen dieses Angebots abgeschlossen sind. Anschließend wird alles gemeinsam versendet.</div>
        <?php else:
            $displayDue=$offerShipDue??new DateTimeImmutable($shipment['due_date']);
        ?>
        <div class="notice success"><strong>Alle Positionen sind versandbereit.</strong><br>Die Sachen aus diesem Angebot werden gemeinsam in einer Sendung verschickt.</div>
        <div class="grid two">
            <div>
                <h3>Versandadresse</h3>
                <?php if($shipment['address_keyword']&&$shipment['address_name']&&$shipment['street']&&$shipment['postal_code']&&$shipment['city']):?>
                    <address class="shipping-address">
                        <?php if($shipment['address_keyword']):?><strong>Kennwort: <?=e($shipment['address_keyword'])?></strong><br><?php endif;?>
                        <?=e($shipment['address_name'])?><br>
                        <?php if($shipment['extra']):?><?=nl2br(e($shipment['extra']))?><br><?php endif;?>
                        <?=e($shipment['street'])?><br>
                        <?=e($shipment['postal_code'].' '.$shipment['city'])?>
                        <?php if($shipment['country'] && $shipment['country']!=='Deutschland'):?><br><?=e($shipment['country'])?><?php endif;?>
                    </address>
                <?php else:?><div class="notice warning">Die Versandadresse wird von der Plattform noch vervollständigt.</div><?php endif;?>
            </div>
            <div>
                <h3>Gemeinsamer Versandtermin</h3>
                <p><strong><?=e(date_de($displayDue))?></strong></p>
                <?php if($shipment['status']==='confirmed'):?>
                    <div class="notice success">Gemeinsamer Versand bestätigt am <?=e(date('d.m.Y H:i',strtotime($shipment['confirmed_at'])))?> Uhr.</div>
                    <?php if($shipment['tracking_number']):?><p>Sendungsnummer: <strong><?=e($shipment['tracking_number'])?></strong></p><?php endif;?>
                <?php elseif(new DateTimeImmutable('today')<$displayDue):?>
                    <div class="notice">Die gemeinsame Versandbestätigung wird am vorgesehenen Versandtag freigeschaltet.</div>
                <?php elseif($shipment['address_keyword']&&$shipment['address_name']&&$shipment['street']&&$shipment['postal_code']&&$shipment['city']):?>
                    <form method="post" action="<?=e(url('/seller/order/'.$id.'/confirm-shipment'))?>">
                        <label class="check"><input type="checkbox" name="confirm_shipped" value="1" required><span>Ich bestätige verbindlich, dass ich alle für dieses Angebot vorgesehenen Sachen gemeinsam an die angegebene Versandadresse versendet habe.</span></label>
                        <label>Sendungsnummer (optional)<input name="tracking_number" maxlength="190"></label>
                        <label>Versandhinweis (optional)<textarea name="seller_note" rows="3"></textarea></label>
                        <button class="btn full">Gemeinsamen Versand bestätigen</button>
                    </form>
                <?php endif;?>
            </div>
        </div>
        <?php endif;?>
    </section>
    <?php endif;?>

    <?php render('Auftrag '.$o['order_no'],ob_get_clean());exit;
}

if (preg_match('#^/seller/order/(\d+)/precheck$#',$path,$m) && $method==='POST') {
    $s=require_seller();$id=(int)$m[1];
    $q=db()->prepare('SELECT * FROM orders WHERE id=? AND seller_id=?');$q->execute([$id,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck'){flash('error','Vorabkontrolle ist geschlossen.');redirect('/seller/order/'.$id);}
    if((int)$o['precheck_photo_count']===0){flash('error','Für diese Position sind keine Vorabfotos erforderlich.');redirect('/seller/order/'.$id);}
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

if (preg_match('#^/seller/event/(\d+)/submit$#',$path,$m) && $method==='POST') {
    $s=require_seller();$eventId=(int)$m[1];
    $q=db()->prepare("SELECT e.*,d.order_id,d.day_no,d.status day_status,d.required_photo_count,d.late_submission_allowed,d.late_submission_note,d.late_submission_requested_at,
        o.offer_id,o.seller_id,o.status order_status,o.started_at,o.required_success_days,o.is_final_day_position,o.align_to_offer_end
        FROM order_day_events e
        JOIN order_days d ON d.id=e.day_id
        JOIN orders o ON o.id=d.order_id
        WHERE e.id=? AND o.seller_id=?");
    $q->execute([$eventId,$s['id']]);$ev=$q->fetch();if(!$ev)not_found();

    if($ev['order_status']!=='running'||$ev['day_status']!=='planned'||$ev['status']!=='planned'){
        flash('error','Dieser Nachweisvorgang kann nicht eingereicht werden.');redirect('/seller/order/'.$ev['order_id']);
    }

    $scheduled=scheduled_order_day_date($ev,(int)$ev['day_no']);
    $isLateSubmission=event_late_submission_allowed($ev,$ev,$scheduled);
    if(!$isLateSubmission && event_window_state($ev,$scheduled)!=='open'){
        flash('error','Dieser Nachweis kann nur innerhalb seines festgelegten Zeitfensters eingereicht werden.');redirect('/seller/order/'.$ev['order_id']);
    }

    $q=db()->prepare("SELECT id FROM order_days WHERE order_id=? AND status IN('planned','submitted') ORDER BY day_no LIMIT 1");
    $q->execute([$ev['order_id']]);
    if((int)$q->fetchColumn()!==(int)$ev['day_id']){
        flash('error','Bitte die Tage der Reihe nach bearbeiten.');redirect('/seller/order/'.$ev['order_id']);
    }

    $q=db()->prepare("SELECT id FROM order_day_events WHERE day_id=? AND status='planned' ORDER BY event_no LIMIT 1");
    $q->execute([$ev['day_id']]);
    if((int)$q->fetchColumn()!==$eventId){
        flash('error','Bitte die Nachweisvorgänge der Reihe nach bearbeiten.');redirect('/seller/order/'.$ev['order_id']);
    }

    $files=normalized_uploads('photo');
    if(count($files)!==1){
        flash('error','Für diesen Nachweisvorgang ist genau ein Foto erforderlich.');redirect('/seller/order/'.$ev['order_id']);
    }

    db()->beginTransaction();
    try{
        $up=save_image_upload($files[0],'order-'.$ev['order_id'].'/day-'.$ev['day_no'].'/event-'.$ev['event_no']);
        db()->prepare('INSERT INTO day_uploads(day_id,event_id,seller_id,file_path,mime_type,file_size,sha256) VALUES(?,?,?,?,?,?,?)')
            ->execute([$ev['day_id'],$eventId,$s['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);
        db()->prepare("UPDATE order_day_events SET status='submitted',seller_note=?,submitted_at=NOW(),updated_at=NOW() WHERE id=?")
            ->execute([post('seller_note')?:null,$eventId]);

        $q=db()->prepare("SELECT * FROM order_day_events WHERE day_id=? AND status='planned' ORDER BY event_no");
        $q->execute([$ev['day_id']]);$plannedEvents=$q->fetchAll();$remaining=count($plannedEvents);
        if($remaining===0){
            db()->prepare("UPDATE order_days SET status='submitted',submitted_at=NOW(),late_submission_allowed=0,updated_at=NOW() WHERE id=?")->execute([$ev['day_id']]);
        }elseif($isLateSubmission){
            $lateStillOpen=false;
            foreach($plannedEvents as $plannedEvent){
                if(event_late_submission_allowed($plannedEvent,$ev,$scheduled)){$lateStillOpen=true;break;}
            }
            if(!$lateStillOpen)db()->prepare("UPDATE order_days SET late_submission_allowed=0,updated_at=NOW() WHERE id=?")->execute([$ev['day_id']]);
        }
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());redirect('/seller/order/'.$ev['order_id']);
    }

    log_event((int)$ev['offer_id'],(int)$ev['order_id'],'evidence_event.submitted',[
        'day_no'=>(int)$ev['day_no'],'event_no'=>(int)$ev['event_no'],'label'=>$ev['label'],
        'scheduled_date'=>$scheduled?->format('Y-m-d'),'window'=>event_window_text($ev),
        'late_submission'=>$isLateSubmission
    ]);
    if($remaining===0){
        log_event((int)$ev['offer_id'],(int)$ev['order_id'],'day.submitted',['day_no'=>(int)$ev['day_no'],'scheduled_date'=>$scheduled?->format('Y-m-d')]);
        flash('success','Letzter Nachweisvorgang eingereicht. Der Tag wartet jetzt auf Prüfung.');
    }else{
        flash('success',$ev['label'].' wurde eingereicht. Der nächste Nachweisvorgang folgt in seinem eigenen Zeitfenster.');
    }
    redirect('/seller/order/'.$ev['order_id']);
}

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
        $hasAlignmentAnchor=$autoAligned?offer_has_end_alignment_anchor((int)$o['offer_id']):false;
        $autoDate=($autoAligned&&$hasAlignmentAnchor)?offer_aligned_start_date((int)$o['offer_id'],(int)$o['required_success_days']):null;
        $waitingForSchedule=$autoAligned&&$hasAlignmentAnchor&&!$autoDate;
        $syncedStart=(!$autoAligned&&!empty($o['sync_start_with_offer']))?offer_synced_start_date((int)$o['offer_id']):null;
    ?>
        <article class="panel review-card">
            <div class="card-top"><div><span class="eyebrow"><?=e($o['order_no'])?></span><h3><?=e($o['title_snapshot'])?></h3><p class="muted"><?=e($o['first_name'].' '.$o['last_name'])?></p></div><span class="status status-precheck">Vorabkontrolle</span></div>
            <?php if((int)$o['precheck_photo_count']>0):?><p><?=nl2br(e($o['precheck_instructions']))?></p><?php else:?><div class="notice success">Für diese Position sind keine Vorabfotos erforderlich.</div><?php endif;?>
            <div class="photo-grid"><?php foreach($ups as $u):?><a href="<?=e(url('/admin/evidence/precheck/'.$u['id']))?>"><img src="<?=e(url('/file/precheck/'.$u['id']))?>" alt="Vorabnachweis"></a><?php endforeach;?></div>
            <div class="review-split">
                <form method="post" action="<?=e(url('/admin/order/'.$o['id'].'/approve-precheck'))?>">
                    <input type="hidden" name="return_to" value="reviews">
                    <?php if($autoAligned&&$autoDate):?>
                        <div class="notice"><strong>Automatisch ans Angebotsende gekoppelt</strong><br><?=e(date_de($autoDate))?></div>
                    <?php elseif($waitingForSchedule):?>
                        <div class="notice"><strong>Freigabe sofort möglich.</strong><br>Der konkrete Durchführungstag wird automatisch gesetzt, sobald eine mehrtägige Basisposition gestartet wurde.</div>
                    <?php elseif($syncedStart):?>
                        <div class="notice"><strong>Gemeinsamer Hauptstart</strong><br><?=e(date_de($syncedStart))?> · wird automatisch von der bereits gestarteten Hauptposition übernommen.</div>
                    <?php else:?>
                        <label>Startdatum<input type="date" name="start_date" min="<?=e(date('Y-m-d'))?>" value="<?=e(date('Y-m-d'))?>" required></label>
                    <?php endif;?>
                    <button class="btn full">✓ <?=e($waitingForSchedule?'Freigeben · Termin folgt':'Freigeben & starten')?></button>
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
                        <?php if($u):?>
                            <div class="evidence-admin-tools">
                                <a href="<?=e(url('/admin/evidence/day/'.$u['id']))?>"><img src="<?=e(url('/file/day/'.$u['id']))?>" alt="<?=e($ev['label'])?>"></a>
                                <a class="text-link compact" href="<?=e(url('/file/day/'.$u['id']).'?download=1')?>">Herunterladen</a>
                                <details>
                                    <summary>Foto neu anfordern</summary>
                                    <form method="post" action="<?=e(url('/admin/event/'.$ev['id'].'/request-resubmission'))?>">
                                        <input type="hidden" name="return_to" value="reviews">
                                        <label>Grund<textarea name="reason" rows="2" required placeholder="Was soll neu fotografiert werden?"></textarea></label>
                                        <button class="btn ghost danger full">Dieses Foto verwerfen & neu anfordern</button>
                                    </form>
                                </details>
                            </div>
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
                <textarea name="admin_note" rows="3" placeholder="<?=!empty($d['_missed'])?'Notiz zur Entscheidung optional':'Notiz / Begründung optional'?>"></textarea>
                <div>
                    <?php if(empty($d['_missed'])):?><button class="btn" name="decision" value="fulfilled">✓ Erfüllt</button>
                    <?php else:?><button class="btn ghost" name="decision" value="fulfilled_override">✓ Trotz fehlender Nachweise erfüllt</button><?php endif;?>
                    <button class="btn danger" name="decision" value="not_fulfilled">✕ Nicht erfüllt · +1 Tag</button>
                </div>
            </form>
            <?php if(!empty($d['_missed'])):?>
            <form class="late-evidence-form" method="post" action="<?=e(url('/admin/day/'.$d['id'].'/request-evidence'))?>">
                <input type="hidden" name="return_to" value="reviews">
                <label>Hinweis zur Nachreichung <span class="muted">(optional)</span><textarea name="late_submission_note" rows="2" placeholder="z. B. Bitte die fehlenden Fotos für diesen Tag nachreichen."></textarea></label>
                <button class="btn ghost full">Fehlende Nachweise nachfordern</button>
            </form>
            <?php endif;?>
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
    mark_order_messages_read($id,'admin');$messages=order_messages($id);
    $windowTemplates=event_templates($o['daily_event_windows_json']??null,(int)$o['daily_photo_count']);

    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Auftrag <?=e($o['order_no'])?></span><h1><?=e($o['title_snapshot'])?></h1><p><?=e($o['first_name'].' '.$o['last_name'])?> · <?=money($o['compensation'])?></p></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></div>
    <div class="stats">
        <div class="stat"><span>Erfolgreich</span><strong><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?></strong></div>
        <div class="stat"><span>Verlängerung</span><strong>+<?=e($o['extension_days'])?></strong></div>
        <div class="stat"><span>Start</span><strong class="stat-date"><?=e($o['started_at']?date_de(scheduled_order_day_date($o,1)):'–')?></strong></div>
        <div class="stat"><span>Wallet</span><strong class="stat-date"><?=e($wallet?wallet_status_label($wallet['status']):'–')?></strong></div>
    </div>
    <?php if($o['status']==='running'&&!$o['started_at']&&!empty($o['align_to_offer_end'])):?>
        <div class="notice"><strong>Freigegeben – Termin folgt automatisch.</strong><br>Dieser Auftrag wartet auf den berechenbaren Endtermin der mehrtägigen Basisposition.</div>
    <?php endif;?>

    <section class="panel">
        <div class="section-head"><h2>Vorabkontrolle</h2><span class="muted"><?=count($pre)?>/<?=e($o['precheck_photo_count'])?> Fotos vorhanden</span></div>
        <p><?=nl2br(e($o['precheck_instructions']))?></p>
        <?php if($rejection && $o['status']==='precheck'):?><div class="notice warning"><strong>Letzte Rückmeldung:</strong> <?=e($rejection)?></div><?php endif;?>
        <?php if($pre):?><div class="photo-grid"><?php foreach($pre as $p):?><a href="<?=e(url('/admin/evidence/precheck/'.$p['id']))?>"><img src="<?=e(url('/file/precheck/'.$p['id']))?>" alt="Vorabnachweis"></a><?php endforeach;?></div><?php endif;?>
        <?php if($o['status']==='precheck'):?>
            <?php
                $autoAligned=!empty($o['align_to_offer_end']);
                $hasAlignmentAnchor=$autoAligned?offer_has_end_alignment_anchor((int)$o['offer_id']):false;
                $autoDate=($autoAligned&&$hasAlignmentAnchor)?offer_aligned_start_date((int)$o['offer_id'],(int)$o['required_success_days']):null;
                $waitingForSchedule=$autoAligned&&$hasAlignmentAnchor&&!$autoDate;
                $syncedStart=(!$autoAligned&&!empty($o['sync_start_with_offer']))?offer_synced_start_date((int)$o['offer_id']):null;
            ?>
            <div class="review-split">
                <form method="post" action="<?=e(url('/admin/order/'.$id.'/approve-precheck'))?>">
                    <?php if($autoAligned&&$autoDate):?>
                        <div class="notice"><strong>Synchronisierter Zeitraum</strong><br><?=e(date_de($autoDate))?></div>
                    <?php elseif($waitingForSchedule):?>
                        <div class="notice"><strong>Freigabe sofort möglich.</strong><br>Der Durchführungstag wird automatisch gesetzt, sobald eine mehrtägige Basisposition gestartet wurde.</div>
                    <?php elseif($syncedStart):?>
                        <div class="notice"><strong>Gemeinsamer Hauptstart</strong><br><?=e(date_de($syncedStart))?> · wird automatisch übernommen.</div>
                    <?php else:?>
                        <label>Geplanter Start<input type="date" name="start_date" min="<?=e(date('Y-m-d'))?>" value="<?=e(date('Y-m-d'))?>" required></label>
                    <?php endif;?>
                    <button class="btn full" <?=(count($pre)<(int)$o['precheck_photo_count'])?'disabled':''?>>✓ <?=e($waitingForSchedule?'Freigeben · Termin folgt':'Freigeben & starten')?></button>
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
                <?php if(!empty($d['fulfilled_by_override'])):?><div class="notice success"><strong>Trotz fehlender Nachweise als erfüllt markiert.</strong></div><?php endif;?>
                <?php if(!empty($d['late_submission_allowed'])):?><div class="notice warning"><strong>Nachreichung offen.</strong><br>Die fehlenden Nachweise wurden erneut freigeschaltet.<?php if($d['late_submission_note']):?><br><?=nl2br(e($d['late_submission_note']))?><?php endif;?></div><?php endif;?>
                <?php if($d['admin_note']):?><p class="muted">Admin-Notiz: <?=e($d['admin_note'])?></p><?php endif;?>
                <?php if($events):?><div class="event-mini-list"><?php foreach($events as $ev):
                    $state=event_window_state($ev,$scheduled);
                    $hasUpload=false;foreach($ups as $u){if((int)($u['event_id']??0)===(int)$ev['id']){$hasUpload=true;break;}}
                ?><div><span><strong><?=e($ev['label'])?></strong> · <?=e(event_window_text($ev))?></span><span class="status <?=e($hasUpload?'status-fulfilled':($state==='closed'?'status-not_fulfilled':'status-planned'))?>"><?=e($hasUpload?'Eingereicht':($state==='closed'?'Verpasst':'Offen'))?></span></div><?php endforeach;?></div><?php endif;?>
                <div class="photo-grid small"><?php foreach($ups as $u):?><figure class="evidence-photo"><figcaption><?=e($u['label']??'Nachweis')?></figcaption><a href="<?=e(url('/admin/evidence/day/'.$u['id']))?>"><img src="<?=e(url('/file/day/'.$u['id']))?>" alt="<?=e($u['label']??'Tagesnachweis')?>"></a><a class="text-link compact" href="<?=e(url('/file/day/'.$u['id']).'?download=1')?>">Download</a><?php if(!empty($u['event_id'])&&in_array($d['status'],['planned','submitted'],true)):?><details class="photo-action"><summary>Neu anfordern</summary><form method="post" action="<?=e(url('/admin/event/'.$u['event_id'].'/request-resubmission'))?>"><label>Grund<textarea name="reason" rows="2" required></textarea></label><button class="btn ghost danger full">Foto verwerfen</button></form></details><?php endif;?></figure><?php endforeach;?></div>
            </div>

            <?php if($d['status']==='submitted' || $missed):
                $finalLocked=!empty($o['align_to_offer_end'])&&(int)$o['required_success_days']===1&&!final_day_positions_ready_for_review((int)$o['offer_id']);
            ?>
                <?php if($finalLocked):?>
                    <div class="notice"><strong>Synchronisierte Ein-Tages-Position:</strong><br>Die Bewertung wird freigeschaltet, sobald alle mehrtägigen Positionen dieses Angebots endgültig abgeschlossen sind.</div>
                <?php else:?>
                <div>
                    <form class="review-actions" method="post" action="<?=e(url('/admin/day/'.$d['id'].'/review'))?>">
                        <textarea name="admin_note" rows="2" placeholder="Notiz optional"></textarea>
                        <div>
                            <?php if(!$missed):?><button class="btn" name="decision" value="fulfilled">✓ Erfüllt</button>
                            <?php else:?><button class="btn ghost" name="decision" value="fulfilled_override">✓ Trotz fehlender Nachweise erfüllt</button><?php endif;?>
                            <button class="btn danger" name="decision" value="not_fulfilled">✕ Nicht erfüllt · +1 Tag</button>
                        </div>
                    </form>
                    <?php if($missed):?>
                    <form class="late-evidence-form" method="post" action="<?=e(url('/admin/day/'.$d['id'].'/request-evidence'))?>">
                        <label>Hinweis zur Nachreichung <span class="muted">(optional)</span><textarea name="late_submission_note" rows="2" placeholder="Bitte die fehlenden Nachweise nachreichen."></textarea></label>
                        <button class="btn ghost full">Fehlende Nachweise nachfordern</button>
                    </form>
                    <?php endif;?>
                </div>
                <?php endif;?>
            <?php endif;?>
            <?php if(in_array($d['status'],['fulfilled','not_fulfilled'],true)&&$o['status']!=='completed'):?>
                <details class="correction-inline">
                    <summary>Tagesentscheidung korrigieren</summary>
                    <form method="post" action="<?=e(url('/admin/day/'.$d['id'].'/reset-review'))?>">
                        <label>Grund der Korrektur<textarea name="reason" rows="2" required></textarea></label>
                        <button class="btn ghost danger">Entscheidung zurücksetzen</button>
                    </form>
                </details>
            <?php endif;?>
        </div>
    <?php endforeach;?></div></section>
    <?php endif;?>

    <?php if(in_array($o['status'],['precheck','running'],true)):?>
    <section class="panel correction-panel">
        <div class="section-head"><h2>Admin-Korrekturen</h2><span class="muted">Jede Änderung wird protokolliert.</span></div>

        <details class="inline-editor window-editor">
            <summary>Nachweis-Zeitfenster anpassen</summary>
            <div class="notice"><strong>Auch nach Annahme möglich.</strong><br>Die neuen Zeiten gelten sofort für noch nicht eingereichte Nachweisvorgänge. Bereits eingereichte Nachweise behalten ihre bisherigen Zeitfenster als Historie.</div>
            <form method="post" action="<?=e(url('/admin/order/'.$id.'/update-windows'))?>">
                <div class="event-config">
                    <h4>Aktuell gültige Zeitfenster</h4>
                    <?php foreach($windowTemplates as $i=>$t):?>
                        <div class="event-config-row">
                            <label>Nachweis
                                <input value="<?=e($t['label'])?>" readonly>
                                <input type="hidden" name="event_label[]" value="<?=e($t['label'])?>">
                            </label>
                            <label>Von<input type="time" name="event_start[]" value="<?=e($t['start'])?>" required></label>
                            <label>Bis<input type="time" name="event_end[]" value="<?=e($t['end'])?>" required></label>
                            <label class="check event-all-day"><input type="checkbox" name="event_all_day[<?=$i?>]" value="1" <?=!empty($t['all_day'])?'checked':''?>><span>Ganztags</span></label>
                        </div>
                    <?php endforeach;?>
                </div>
                <label>Grund der Änderung<textarea name="reason" rows="3" required placeholder="Warum werden die Zeitfenster nachträglich angepasst?"></textarea></label>
                <button class="btn">Neue Zeitfenster übernehmen</button>
            </form>
        </details>
        <?php if($o['status']==='running'&&$o['started_at']&&empty($o['align_to_offer_end'])):?>
        <details class="inline-editor">
            <summary>Startdatum korrigieren</summary>
            <form method="post" action="<?=e(url('/admin/order/'.$id.'/change-start'))?>">
                <div class="form-grid"><label>Neues Startdatum<input type="date" name="start_date" value="<?=e(substr($o['started_at'],0,10))?>" required></label><label>Grund<textarea name="reason" rows="2" required></textarea></label></div>
                <button class="btn ghost">Startdatum übernehmen</button>
            </form>
        </details>
        <?php endif;?>
        <?php if($o['status']==='running'&&$o['started_at']):?>
        <div class="grid two correction-actions">
            <form method="post" action="<?=e(url('/admin/order/'.$id.'/add-manual-day'))?>">
                <label>Zusätzlicher Pflichttag · Grund<textarea name="reason" rows="2" required></textarea></label>
                <button class="btn ghost">+ Pflichttag anhängen</button>
            </form>
            <?php $manualOpen=array_values(array_filter($days,static fn($x)=>!empty($x['manual_extension'])&&$x['status']==='planned'));?>
            <?php if($manualOpen):?><form method="post" action="<?=e(url('/admin/order/'.$id.'/remove-manual-day'))?>">
                <label>Manuellen Tag entfernen · Grund<textarea name="reason" rows="2" required></textarea></label>
                <button class="btn ghost danger">Letzten manuellen Tag entfernen</button>
            </form><?php endif;?>
        </div>
        <?php endif;?>
    </section>
    <?php endif;?>

    <?php if($shipment):?>
    <section class="panel">
        <div class="section-head"><h2>Versand</h2><span class="status <?=e($shipment['status']==='confirmed'?'status-fulfilled':'status-shipping')?>"><?=e($shipment['status']==='confirmed'?'Bestätigt':'Offen')?></span></div>
        <p><strong>Fällig:</strong> <?=e(date_de(new DateTimeImmutable($shipment['due_date'])))?></p>
        <address class="shipping-address"><?php if($shipment['address_keyword']):?><strong>Kennwort: <?=e($shipment['address_keyword'])?></strong><br><?php endif;?><?=e($shipment['address_name']??'')?><?php if($shipment['extra']):?><br><?=nl2br(e($shipment['extra']))?><?php endif;?><?php if($shipment['street']):?><br><?=e($shipment['street'])?><?php endif;?><?php if($shipment['postal_code']||$shipment['city']):?><br><?=e(trim(($shipment['postal_code']??'').' '.($shipment['city']??'')))?><?php endif;?><?php if($shipment['country']&&$shipment['country']!=='Deutschland'):?><br><?=e($shipment['country'])?><?php endif;?></address>
        <?php if($shipment['confirmed_at']):?><p>Bestätigt: <?=e(date('d.m.Y H:i',strtotime($shipment['confirmed_at'])))?> Uhr<?php if($shipment['tracking_number']):?> · Sendungsnummer <?=e($shipment['tracking_number'])?><?php endif;?></p><?php endif;?>
    </section>
    <?php endif;?>

    <section class="panel message-panel">
        <div class="section-head"><h2>Nachrichten</h2><span class="muted">Kommunikation mit der Verkäuferin</span></div>
        <div class="message-thread">
            <?php foreach($messages as $msg):?><div class="message-bubble <?=e($msg['sender_role']==='admin'?'from-platform':'from-seller')?>"><div><strong><?=e($msg['sender_role']==='admin'?'Plattform':'Verkäuferin')?></strong><span><?=e(date('d.m.Y H:i',strtotime($msg['created_at'])))?> Uhr</span></div><p><?=nl2br(e($msg['body']))?></p></div><?php endforeach;?>
            <?php if(!$messages):?><div class="empty">Noch keine Nachrichten zu diesem Auftrag.</div><?php endif;?>
        </div>
        <form method="post" action="<?=e(url('/admin/order/'.$id.'/message'))?>">
            <label>Nachricht an Verkäuferin<textarea name="body" rows="4" maxlength="4000" required placeholder="Nachricht der Plattform"></textarea></label>
            <button class="btn">Nachricht senden</button>
        </form>
    </section>

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
    $syncedStart=!empty($o['sync_start_with_offer'])?offer_synced_start_date((int)$o['offer_id']):null;
    $hasAlignmentAnchor=!empty($o['align_to_offer_end'])?offer_has_end_alignment_anchor((int)$o['offer_id']):false;
    $pendingSchedule=false;$start=null;$startDate=null;

    if(!empty($o['align_to_offer_end'])&&$hasAlignmentAnchor){
        $start=offer_aligned_start_date((int)$o['offer_id'],(int)$o['required_success_days']);
        if($start){
            $startDate=$start->format('Y-m-d');
        }else{
            $pendingSchedule=true;
        }
    }elseif($syncedStart){
        $start=$syncedStart;
        $startDate=$start->format('Y-m-d');
    }else{
        $startDate=post('start_date');$start=DateTimeImmutable::createFromFormat('!Y-m-d',$startDate);
        if(!$start||$start->format('Y-m-d')!==$startDate||$start<$today){flash('error','Bitte ein gültiges Startdatum ab heute wählen.');redirect('/admin/order/'.$id);}
    }

    db()->beginTransaction();
    try{
        db()->prepare("UPDATE orders SET status='running',precheck_approved_at=NOW(),started_at=?,updated_at=NOW() WHERE id=?")
            ->execute([$startDate?$startDate.' 00:00:00':null,$id]);

        if(!$pendingSchedule){
            $ins=db()->prepare("INSERT INTO order_days(order_id,day_no,is_extension,required_photo_count,status) VALUES(?,?,0,?,'planned')");
            for($d=1;$d<=(int)$o['required_success_days'];$d++){
                $ins->execute([$id,$d,$o['daily_photo_count']]);
                ensure_day_events((int)db()->lastInsertId(),(int)$o['daily_photo_count']);
            }
        }
        db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}

    log_event((int)$o['offer_id'],$id,'precheck.approved',[
        'start_date'=>$startDate,
        'end_aligned'=>(bool)$o['align_to_offer_end'],
        'synced_start'=>(bool)$o['sync_start_with_offer'],
        'pending_schedule'=>$pendingSchedule
    ]);
    sync_end_aligned_positions((int)$o['offer_id']);
    sync_offer_status((int)$o['offer_id']);
    notify_seller((int)$o['seller_id'],'evidence','Auftrag freigegeben',
        $pendingSchedule
            ?$o['order_no'].' wurde freigegeben. Der konkrete Termin wird automatisch gesetzt.'
            :$o['order_no'].' wurde freigegeben. Start: '.$start->format('d.m.Y').'.',
        '/seller/order/'.$id,'precheck-approved:'.$id.':'.time());
    flash('success',$pendingSchedule
        ?'Auftrag wurde freigegeben. Der Durchführungstag wird automatisch gesetzt, sobald die mehrtägige Basisposition gestartet wurde.'
        :'Auftrag freigegeben. Start: '.date_de($start).'.');
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
    notify_seller((int)$o['seller_id'],'evidence','Vorabkontrolle erneut erforderlich',$o['order_no'].":
".$reason,'/seller/order/'.$id,'precheck-rejected:'.$id.':'.time());
    flash('success','Vorabkontrolle zurückgewiesen. Die Verkäuferin kann die Fotos neu einreichen.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$id);
}

// REQUEST MISSING EVIDENCE AFTER A MISSED WINDOW
if (preg_match('#^/admin/day/(\d+)/request-evidence$#',$path,$m) && $method==='POST') {
    $a=require_admin();$dayId=(int)$m[1];$note=post('late_submission_note');

    $q=db()->prepare("SELECT d.*,o.offer_id,o.id order_id,o.order_no,o.seller_id,o.status order_status,o.started_at,
        o.required_success_days,o.align_to_offer_end,o.is_final_day_position
        FROM order_days d JOIN orders o ON o.id=d.order_id WHERE d.id=?");
    $q->execute([$dayId]);$d=$q->fetch();if(!$d)not_found();

    if($d['order_status']!=='running'||$d['status']!=='planned'){
        flash('error','Für diesen Tag können aktuell keine Nachweise nachgefordert werden.');
        redirect('/admin/order/'.$d['order_id']);
    }
    if(!day_is_missed($d,$d)){
        flash('error','Nachreichungen können freigegeben werden, sobald mindestens ein Pflicht-Zeitfenster verpasst wurde.');
        redirect('/admin/order/'.$d['order_id']);
    }

    $q=db()->prepare("SELECT id,label,event_no FROM order_day_events WHERE day_id=? AND status='planned' ORDER BY event_no");
    $q->execute([$dayId]);$missing=$q->fetchAll();
    if(!$missing){
        flash('error','Für diesen Tag fehlen keine Nachweise mehr.');
        redirect('/admin/order/'.$d['order_id']);
    }

    db()->prepare("UPDATE order_days SET late_submission_allowed=1,late_submission_note=?,
        late_submission_requested_at=NOW(),late_submission_requested_by=?,updated_at=NOW() WHERE id=?")
        ->execute([$note?:null,$a['id'],$dayId]);

    log_event((int)$d['offer_id'],(int)$d['order_id'],'day.evidence_requested',[
        'day_id'=>$dayId,
        'day_no'=>(int)$d['day_no'],
        'missing_events'=>array_map(static fn(array $x)=>['id'=>(int)$x['id'],'event_no'=>(int)$x['event_no'],'label'=>$x['label']],$missing),
        'note'=>$note?:null
    ]);
    notify_seller((int)$d['seller_id'],'evidence','Fehlende Nachweise nachreichen',
        $d['order_no'].' · Tag '.$d['day_no'].($note!==''?":
".$note:' wurde zur Nachreichung freigegeben.'),
        '/seller/order/'.$d['order_id'],'day-evidence-requested:'.$dayId.':'.time());
    flash('success','Die fehlenden Nachweise wurden zur Nachreichung freigegeben.');
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$d['order_id']);
}

// DAY REVIEW: complete submissions or expired incomplete day
if (preg_match('#^/admin/day/(\d+)/review$#',$path,$m) && $method==='POST') {
    $a=require_admin();$dayId=(int)$m[1];$decision=post('decision');
    if(!in_array($decision,['fulfilled','fulfilled_override','not_fulfilled'],true)){flash('error','Ungültige Entscheidung.');redirect('/admin/orders');}

    $q=db()->prepare("SELECT d.*,o.offer_id,o.seller_id,o.order_no,o.daily_photo_count,o.required_success_days,o.started_at AS order_started_at,o.status AS order_status,o.is_final_day_position,o.align_to_offer_end
        FROM order_days d JOIN orders o ON o.id=d.order_id WHERE d.id=?");
    $q->execute([$dayId]);$d=$q->fetch();if(!$d)not_found();

    $orderCtx=['started_at'=>$d['order_started_at'],'offer_id'=>$d['offer_id'],'required_success_days'=>$d['required_success_days'],'is_final_day_position'=>$d['is_final_day_position'],'align_to_offer_end'=>$d['align_to_offer_end']];
    $missed=$d['status']==='planned'&&$d['order_status']==='running'&&day_is_missed($d,$orderCtx);
    if(!empty($d['align_to_offer_end'])&&(int)$d['required_success_days']===1&&!final_day_positions_ready_for_review((int)$d['offer_id'])){
        flash('error','Diese Ein-Tages-Position kann erst bewertet werden, wenn alle mehrtägigen Positionen endgültig abgeschlossen sind.');
        redirect('/admin/order/'.$d['order_id']);
    }
    if($d['status']!=='submitted'&&!$missed){flash('error','Dieser Tag ist noch nicht prüfbar.');redirect('/admin/order/'.$d['order_id']);}
    if($missed&&!in_array($decision,['not_fulfilled','fulfilled_override'],true)){flash('error','Für einen Tag mit verpasstem Pflichtfenster bitte Nachweise nachfordern, bewusst trotzdem als erfüllt markieren oder verlängern.');redirect('/admin/order/'.$d['order_id']);}
    if($decision==='fulfilled_override'&&!$missed){flash('error','Die Sonderfreigabe ist nur bei fehlenden/verpassten Nachweisen vorgesehen.');redirect('/admin/order/'.$d['order_id']);}
    $storedDecision=$decision==='fulfilled_override'?'fulfilled':$decision;
    $adminNote=post('admin_note')?:($decision==='fulfilled_override'?'Von der Plattform trotz fehlender Nachweise als erfüllt bestätigt.':null);

    db()->beginTransaction();
    try{
        db()->prepare('UPDATE order_days SET status=?,admin_note=?,fulfilled_by_override=?,late_submission_allowed=0,
            reviewed_at=NOW(),reviewed_by=?,updated_at=NOW() WHERE id=?')
            ->execute([$storedDecision,$adminNote,$decision==='fulfilled_override'?1:0,$a['id'],$dayId]);

        if($storedDecision==='not_fulfilled'){
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

    log_event((int)$d['offer_id'],(int)$d['order_id'],'day.reviewed',[
        'day_id'=>$dayId,
        'decision'=>$storedDecision,
        'override_missing_evidence'=>$decision==='fulfilled_override',
        'missed_window'=>$missed
    ]);
    if($storedDecision==='not_fulfilled' && empty($d['align_to_offer_end']))sync_end_aligned_positions((int)$d['offer_id']);
    sync_order_progress((int)$d['order_id']);
    $sellerReviewText=$decision==='fulfilled_override'
        ?$d['order_no'].' · Tag '.$d['day_no'].' wurde trotz fehlender Nachweise als erfüllt bestätigt.'
        :($storedDecision==='fulfilled'
            ?$d['order_no'].' · Tag '.$d['day_no'].' wurde als erfüllt bestätigt.'
            :$d['order_no'].' · Tag '.$d['day_no'].' wurde als nicht erfüllt bewertet; ein zusätzlicher Tag wurde angehängt.');
    notify_seller((int)$d['seller_id'],'evidence','Tagesprüfung abgeschlossen',$sellerReviewText,'/seller/order/'.$d['order_id'],'day-reviewed:'.$dayId.':'.time());
    flash('success',$decision==='fulfilled_override'
        ?'Tag wurde trotz fehlender Nachweise als erfüllt bestätigt.'
        :($storedDecision==='fulfilled'?'Tag als erfüllt bestätigt.':'Tag nicht erfüllt: ein zusätzlicher Tag wurde angehängt.'));
    redirect(post('return_to')==='reviews'?'/admin/reviews':'/admin/order/'.$d['order_id']);
}

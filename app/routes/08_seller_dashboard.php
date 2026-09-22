<?php
declare(strict_types=1);
// SELLER DASHBOARD/LISTS
if ($path==='/seller' && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT COUNT(*) FROM offers WHERE seller_id=? AND status='sent'");$q->execute([$s['id']]);$new=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='precheck'");$q->execute([$s['id']]);$pre=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='running'");$q->execute([$s['id']]);$running=(int)$q->fetchColumn();
    $shipping=0;
    $q=db()->prepare("SELECT COUNT(*) FROM scent_requests WHERE seller_id=? AND status='pending'");$q->execute([$s['id']]);$scentOpen=(int)$q->fetchColumn();

    $q=db()->prepare("SELECT d.*,o.title_snapshot,o.order_no,o.started_at,o.daily_photo_count,o.offer_id,o.required_success_days,o.is_final_day_position,o.align_to_offer_end
        FROM order_days d
        JOIN orders o ON o.id=d.order_id
        WHERE o.seller_id=? AND o.status='running' AND d.status IN('planned','submitted')
          AND d.id=(SELECT d2.id FROM order_days d2 WHERE d2.order_id=o.id AND d2.status IN('planned','submitted') ORDER BY d2.day_no LIMIT 1)
        ORDER BY o.id LIMIT 30");
    $q->execute([$s['id']]);$openSteps=$q->fetchAll();

    $now=new DateTimeImmutable('now');$todayDate=new DateTimeImmutable('today');
    $dueNow=[];$dueToday=[];$missedToday=[];$otherSteps=[];
    foreach($openSteps as $d){
        $scheduled=scheduled_order_day_date($d,(int)$d['day_no']);
        $d['_scheduled']=$scheduled;$d['_next_label']=null;$d['_window']=null;$d['_window_state']=null;$d['_late']=false;

        if($d['status']==='planned'&&$scheduled){
            $events=day_events((int)$d['id']);
            if(!$events)$events=ensure_day_events((int)$d['id'],(int)$d['required_photo_count']);
            foreach($events as $ev){
                if($ev['status']!=='planned')continue;
                $d['_next_label']=$ev['label'];
                $d['_window']=event_window_text($ev);
                $d['_late']=event_late_submission_allowed($ev,$d,$scheduled);
                $d['_window_state']=$d['_late']?'open':event_window_state($ev,$scheduled,$now);
                $d['_event_start']=!empty($ev['all_day'])
                    ?$scheduled->setTime(0,0)
                    :new DateTimeImmutable($scheduled->format('Y-m-d').' '.substr((string)$ev['window_start'],0,5).':00');
                break;
            }
        }

        $isToday=$scheduled&&$scheduled->format('Y-m-d')===$todayDate->format('Y-m-d');
        if($d['status']==='planned'&&$isToday&&$d['_next_label']){
            if($d['_window_state']==='open'){$dueNow[]=$d;continue;}
            if($d['_window_state']==='future'){$dueToday[]=$d;continue;}
            if($d['_window_state']==='closed'){$missedToday[]=$d;continue;}
        }
        $otherSteps[]=$d;
    }
    usort($dueToday,static fn($a,$b)=>($a['_event_start']?->getTimestamp()??PHP_INT_MAX)<=>($b['_event_start']?->getTimestamp()??PHP_INT_MAX));

    $q=db()->prepare("SELECT sh.*,o.id order_id,o.offer_id,ofr.offer_no,ofr.title offer_title
        FROM order_shipments sh
        JOIN orders o ON o.id=sh.order_id
        JOIN offers ofr ON ofr.id=o.offer_id
        WHERE o.seller_id=? AND o.status='shipping' AND sh.status='pending'
        ORDER BY sh.due_date,o.id");
    $q->execute([$s['id']]);
    $shipmentRows=$q->fetchAll();$shipments=[];
    foreach($shipmentRows as $sh){
        $offerId=(int)$sh['offer_id'];
        if(!offer_ready_for_shipping($offerId))continue;
        if(!isset($shipments[$offerId])){
            $shipments[$offerId]=$sh;
        }elseif($sh['due_date']>$shipments[$offerId]['due_date']){
            $shipments[$offerId]['due_date']=$sh['due_date'];
        }
    }
    $shipments=array_values($shipments);$shipping=count($shipments);

    $q=db()->prepare("SELECT id,subject,instructions,requested_at FROM scent_requests WHERE seller_id=? AND status='pending' ORDER BY requested_at ASC LIMIT 3");
    $q->execute([$s['id']]);$scentRequests=$q->fetchAll();

    $wallet=wallet_summary((int)$s['id']);

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Verkäuferin</span><h1>Hallo <?=e($s['first_name'])?></h1><p>Deine offenen Schritte, Versandaufgaben und Vergütung. Angebote und Abläufe werden zentral über die Vermittlungsplattform organisiert.</p></div>
        <a class="btn ghost" href="<?=e(url('/seller/wallet'))?>">Wallet · <?=money($wallet['available'])?> auszahlbar</a>
    </div>
    <div class="stats">
        <div class="stat"><span>Neue Angebote</span><strong><?=$new?></strong></div>
        <div class="stat"><span>Vorabkontrollen</span><strong><?=$pre?></strong></div>
        <div class="stat"><span>Laufende Aufträge</span><strong><?=$running?></strong></div>
        <div class="stat"><span>Versand offen</span><strong><?=$shipping?></strong></div>
        <a class="stat stat-link" href="<?=e(url('/seller/scent-requests'))?>"><span>Duftproben offen</span><strong><?=$scentOpen?></strong></a>
    </div>

    <div class="stats compact seller-due-stats">
        <div class="stat due-now-stat"><span>Jetzt fällig</span><strong><?=count($dueNow)?></strong></div>
        <div class="stat"><span>Heute noch fällig</span><strong><?=count($dueToday)?></strong></div>
        <div class="stat <?=count($missedToday)?'due-missed-stat':''?>"><span>Heute verpasst</span><strong><?=count($missedToday)?></strong></div>
    </div>

    <?php if($dueNow):?>
    <section class="panel seller-due-panel due-now-panel">
        <div class="section-head"><h2>Jetzt fällig</h2><span class="status status-submitted"><?=count($dueNow)?> offen</span></div>
        <div class="list">
        <?php foreach($dueNow as $d):?>
            <a class="list-row due-task-row" href="<?=e(url('/seller/order/'.$d['order_id']))?>">
                <div>
                    <strong><?=e($d['order_no'].' · '.$d['_next_label'])?></strong>
                    <span><?=e($d['title_snapshot'])?> · Tag <?=e($d['day_no'])?> · <?=e($d['_window'])?><?=!empty($d['_late'])?' · Nachreichung freigegeben':''?></span>
                </div>
                <span class="status status-submitted">Jetzt erledigen</span>
            </a>
        <?php endforeach;?>
        </div>
    </section>
    <?php endif;?>

    <?php if($dueToday):?>
    <section class="panel seller-due-panel">
        <div class="section-head"><h2>Heute fällig</h2><span class="muted">Später am heutigen Tag</span></div>
        <div class="list">
        <?php foreach($dueToday as $d):?>
            <a class="list-row" href="<?=e(url('/seller/order/'.$d['order_id']))?>">
                <div>
                    <strong><?=e($d['order_no'].' · '.$d['_next_label'])?></strong>
                    <span><?=e($d['title_snapshot'])?> · Tag <?=e($d['day_no'])?> · <?=e($d['_window'])?></span>
                </div>
                <span class="status status-planned"><?=e($d['_event_start']?->format('H:i')??'Heute')?> Uhr</span>
            </a>
        <?php endforeach;?>
        </div>
    </section>
    <?php endif;?>

    <?php if($missedToday):?>
    <section class="panel seller-due-panel due-missed-panel">
        <div class="section-head"><h2>Heute verpasst</h2><span class="status status-not_fulfilled"><?=count($missedToday)?> Frist(en)</span></div>
        <div class="list">
        <?php foreach($missedToday as $d):?>
            <a class="list-row" href="<?=e(url('/seller/order/'.$d['order_id']))?>">
                <div><strong><?=e($d['order_no'].' · '.$d['_next_label'])?></strong><span><?=e($d['title_snapshot'])?> · Tag <?=e($d['day_no'])?> · <?=e($d['_window'])?></span></div>
                <span class="status status-not_fulfilled">Frist verpasst</span>
            </a>
        <?php endforeach;?>
        </div>
    </section>
    <?php endif;?>

    <section class="panel"><h2>Als Nächstes</h2><div class="list">
    <?php foreach($scentRequests as $sr):?>
        <a class="list-row scent-next" href="<?=e(url('/seller/scent-requests'))?>">
            <div><strong>Duftprobe · <?=e($sr['subject'])?></strong><span>Bewertung von 1 bis 10 erforderlich · angefragt <?=e(date('d.m.Y H:i',strtotime($sr['requested_at'])))?> Uhr</span></div>
            <span class="status status-submitted">Offen</span>
        </a>
    <?php endforeach;?>

    <?php foreach($otherSteps as $d):
        $scheduled=$d['_scheduled'];
        $isFuture=$scheduled&&$scheduled>$todayDate;
    ?>
        <a class="list-row" href="<?=e(url('/seller/order/'.$d['order_id']))?>">
            <div>
                <strong><?=e($d['order_no'].' · '.$d['title_snapshot'])?></strong>
                <span>Tag <?=e($d['day_no'])?> · <?=e(date_de($scheduled))?> · <?=e($d['status']==='submitted'?'Wartet auf Prüfung':($isFuture?'Geplant':($d['_next_label']??'Offen')))?><?php if($d['_next_label']&&$d['_window']):?> · <?=e($d['_window'])?><?php endif;?></span>
            </div><span>→</span>
        </a>
    <?php endforeach;?>

    <?php foreach($shipments as $sh):?>
        <a class="list-row" href="<?=e(url('/seller/order/'.$sh['order_id']))?>">
            <div><strong><?=e($sh['offer_no'].' · '.$sh['offer_title'])?></strong><span>Gemeinsamer Versand · fällig <?=e(date_de(new DateTimeImmutable($sh['due_date'])))?></span></div>
            <span class="status status-shipping">Versand</span>
        </a>
    <?php endforeach;?>

    <?php if(!$scentRequests&&!$otherSteps&&!$shipments):?><div class="empty">Aktuell ist darüber hinaus nichts offen.</div><?php endif;?>
    </div></section>

    <?php render('Übersicht',ob_get_clean());exit;
}

if ($path==='/seller/offers' && $method==='GET') {
    $s=require_seller();$q=db()->prepare("SELECT o.*,(SELECT COUNT(*) FROM offer_positions p WHERE p.offer_id=o.id) positions FROM offers o WHERE seller_id=? AND status<>'draft' ORDER BY created_at DESC");$q->execute([$s['id']]);$offers=$q->fetchAll();
    ob_start();?><div class="page-head"><div><span class="eyebrow">Angebote</span><h1>Meine Angebote</h1></div></div><div class="cards"><?php foreach($offers as $o):?><a class="card link-card" href="<?=e(url('/seller/offer/'.$o['id']))?>"><div class="card-top"><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span><span class="muted"><?=e($o['offer_no'])?></span></div><h3><?=e($o['title'])?></h3><p class="muted"><?=e($o['positions'])?> Position(en)</p></a><?php endforeach;?><?php if(!$offers):?><div class="empty">Keine Angebote vorhanden.</div><?php endif;?></div><?php render('Meine Angebote',ob_get_clean());exit;
}

if ($path==='/seller/orders' && $method==='GET') {
    $s=require_seller();$q=db()->prepare("SELECT * FROM orders WHERE seller_id=? ORDER BY FIELD(status,'running','shipping','precheck','completed','cancelled'),created_at DESC");$q->execute([$s['id']]);$orders=$q->fetchAll();
    ob_start();?><div class="page-head"><div><span class="eyebrow">Aufträge</span><h1>Meine Aufträge</h1></div></div><div class="list"><?php foreach($orders as $o):?><a class="list-row" href="<?=e(url('/seller/order/'.$o['id']))?>"><div><strong><?=e($o['order_no'].' · '.$o['title_snapshot'])?></strong><span><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?> erfolgreich · +<?=e($o['extension_days'])?> Tag(e)<?php if($o['started_at']):?> · <?=!empty($o['align_to_offer_end'])?'Gekoppelt ab':'Start'?> <?=e(date_de(scheduled_order_day_date($o,1)))?><?php endif;?></span></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></a><?php endforeach;?><?php if(!$orders):?><div class="empty">Noch keine Aufträge vorhanden.</div><?php endif;?></div><?php render('Meine Aufträge',ob_get_clean());exit;
}

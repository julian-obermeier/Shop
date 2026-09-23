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

    $now=new DateTimeImmutable('now');$todayDate=new DateTimeImmutable('today');$tomorrowDate=$todayDate->modify('+1 day');
    $dueNow=[];$dueToday=[];$missedToday=[];$otherSteps=[];$todayTimeline=[];
    foreach($openSteps as $d){
        $scheduled=scheduled_order_day_date($d,(int)$d['day_no']);
        $d['_scheduled']=$scheduled;$d['_next_label']=null;$d['_window']=null;$d['_window_state']=null;$d['_late']=false;

        $events=[];
        if($scheduled){
            $events=day_events((int)$d['id']);
            if(!$events&&$d['status']==='planned')$events=ensure_day_events((int)$d['id'],(int)$d['required_photo_count']);

            foreach($events as $ev){
                $eventStart=!empty($ev['all_day'])
                    ?$scheduled->setTime(0,0)
                    :new DateTimeImmutable($scheduled->format('Y-m-d').' '.substr((string)$ev['window_start'],0,5).':00');
                $eventEnd=!empty($ev['all_day'])
                    ?$scheduled->setTime(23,59,59)
                    :new DateTimeImmutable($scheduled->format('Y-m-d').' '.substr((string)$ev['window_end'],0,5).':59');
                $late=$ev['status']==='planned'&&event_late_submission_allowed($ev,$d,$scheduled);
                $state=$ev['status']==='submitted'?'done':($late?'open':event_window_state($ev,$scheduled,$now));

                if($scheduled->format('Y-m-d')===$todayDate->format('Y-m-d')){
                    $todayTimeline[]=[
                        'order_id'=>(int)$d['order_id'],
                        'order_no'=>$d['order_no'],
                        'title'=>$d['title_snapshot'],
                        'day_no'=>(int)$d['day_no'],
                        'label'=>$ev['label'],
                        'window'=>event_window_text($ev),
                        'state'=>$state,
                        'late'=>$late,
                        'start'=>$eventStart,
                        'end'=>$eventEnd,
                        'event_no'=>(int)$ev['event_no'],
                    ];
                }

                if($d['status']==='planned'&&$ev['status']==='planned'&&$d['_next_label']===null){
                    $d['_next_label']=$ev['label'];
                    $d['_window']=event_window_text($ev);
                    $d['_late']=$late;
                    $d['_window_state']=$state;
                    $d['_event_start']=$eventStart;
                    $d['_event_end']=$eventEnd;
                }
            }
        }

        $isToday=$scheduled&&$scheduled->format('Y-m-d')===$todayDate->format('Y-m-d');
        $isTomorrow=$scheduled&&$scheduled->format('Y-m-d')===$tomorrowDate->format('Y-m-d');
        if($d['status']==='planned'&&$isToday&&$d['_next_label']){
            if($d['_window_state']==='open'){$dueNow[]=$d;continue;}
            if($d['_window_state']==='future'){$dueToday[]=$d;continue;}
            if($d['_window_state']==='closed'){$missedToday[]=$d;continue;}
        }
        if($isTomorrow&&$d['status']==='planned')continue;
        $otherSteps[]=$d;
    }
    usort($dueNow,static fn($a,$b)=>($a['_event_end']?->getTimestamp()??PHP_INT_MAX)<=>($b['_event_end']?->getTimestamp()??PHP_INT_MAX));
    usort($dueToday,static fn($a,$b)=>($a['_event_start']?->getTimestamp()??PHP_INT_MAX)<=>($b['_event_start']?->getTimestamp()??PHP_INT_MAX));
    usort($todayTimeline,static fn($a,$b)=>($a['start']->getTimestamp()<=>$b['start']->getTimestamp())?:($a['event_no']<=>$b['event_no']));

    $q=db()->prepare("SELECT d.*,o.title_snapshot,o.order_no,o.started_at,o.daily_photo_count,o.offer_id,o.required_success_days,o.is_final_day_position,o.align_to_offer_end
        FROM order_days d
        JOIN orders o ON o.id=d.order_id
        WHERE o.seller_id=? AND o.status='running' AND d.status='planned'
        ORDER BY o.id,d.day_no LIMIT 100");
    $q->execute([$s['id']]);$tomorrowTasks=[];
    foreach($q->fetchAll() as $d){
        $scheduled=scheduled_order_day_date($d,(int)$d['day_no']);
        if(!$scheduled||$scheduled->format('Y-m-d')!==$tomorrowDate->format('Y-m-d'))continue;
        $events=day_events((int)$d['id']);if(!$events)$events=ensure_day_events((int)$d['id'],(int)$d['required_photo_count']);
        $items=[];
        foreach($events as $ev){
            if($ev['status']==='submitted')continue;
            $start=!empty($ev['all_day'])?$scheduled->setTime(0,0):new DateTimeImmutable($scheduled->format('Y-m-d').' '.substr((string)$ev['window_start'],0,5).':00');
            $items[]=['label'=>$ev['label'],'window'=>event_window_text($ev),'start'=>$start];
        }
        if($items)$tomorrowTasks[]=['order_id'=>(int)$d['order_id'],'order_no'=>$d['order_no'],'title'=>$d['title_snapshot'],'day_no'=>(int)$d['day_no'],'events'=>$items,'first_start'=>$items[0]['start']];
    }
    usort($tomorrowTasks,static fn($a,$b)=>$a['first_start']->getTimestamp()<=>$b['first_start']->getTimestamp());

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
    $latestUpdate=seller_latest_undismissed_update((int)$s['id']);

    $heroMode='clear';$heroTask=null;$heroTarget=null;
    if($dueNow){
        $heroMode='now';$heroTask=$dueNow[0];$heroTarget=!empty($heroTask['_late'])?null:$heroTask['_event_end'];
    }elseif($dueToday){
        $heroMode='next';$heroTask=$dueToday[0];$heroTarget=$heroTask['_event_start'];
    }elseif($tomorrowTasks){
        $heroMode='tomorrow';$heroTask=$tomorrowTasks[0];$heroTarget=$heroTask['first_start'];
    }

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Verkäuferin</span><h1>Hallo <?=e($s['first_name'])?></h1><p>Deine offenen Schritte, Versandaufgaben und Vergütung. Angebote und Abläufe werden zentral über die Vermittlungsplattform organisiert.</p></div>
        <a class="btn ghost" href="<?=e(url('/seller/wallet'))?>">Wallet · <?=money($wallet['available'])?> auszahlbar</a>
    </div>

    <section class="seller-task-hero task-hero-<?=e($heroMode)?>">
        <?php if($heroMode==='now'):?>
            <div class="task-hero-copy">
                <span class="eyebrow">Jetzt erledigen</span>
                <h2><?=e($heroTask['order_no'].' · '.$heroTask['_next_label'])?></h2>
                <p><?=e($heroTask['title_snapshot'])?> · Tag <?=e($heroTask['day_no'])?> · <?=e($heroTask['_window'])?><?=!empty($heroTask['_late'])?' · Nachreichung':''?></p>
                <?php if($heroTarget):?><div class="task-countdown"><span>Noch Zeit</span><strong data-countdown-target="<?=e((string)($heroTarget->getTimestamp()*1000))?>" data-countdown-reload="1">–</strong></div>
                <?php elseif(!empty($heroTask['_late'])):?><div class="task-countdown"><span>Status</span><strong>Nachreichung offen</strong></div><?php endif;?>
            </div>
            <a class="btn task-hero-button" href="<?=e(url('/seller/order/'.$heroTask['order_id']))?>">Jetzt öffnen →</a>
        <?php elseif($heroMode==='next'):?>
            <div class="task-hero-copy">
                <span class="eyebrow">Nächster Nachweis</span>
                <h2><?=e($heroTask['order_no'].' · '.$heroTask['_next_label'])?></h2>
                <p>Heute ab <?=e($heroTask['_event_start']->format('H:i'))?> Uhr · <?=e($heroTask['_window'])?></p>
                <div class="task-countdown"><span>Startet in</span><strong data-countdown-target="<?=e((string)($heroTarget->getTimestamp()*1000))?>" data-countdown-reload="1">–</strong></div>
            </div>
            <a class="btn ghost task-hero-button" href="<?=e(url('/seller/order/'.$heroTask['order_id']))?>">Auftrag ansehen</a>
        <?php elseif($heroMode==='tomorrow'):?>
            <div class="task-hero-copy">
                <span class="eyebrow">Für heute nichts mehr fällig</span>
                <h2>Nächster Schritt morgen</h2>
                <p><?=e($heroTask['order_no'].' · '.$heroTask['events'][0]['label'])?> · <?=e($heroTask['events'][0]['window'])?></p>
                <div class="task-countdown"><span>Beginnt in</span><strong data-countdown-target="<?=e((string)($heroTarget->getTimestamp()*1000))?>" data-countdown-reload="1">–</strong></div>
            </div>
            <a class="btn ghost task-hero-button" href="<?=e(url('/seller/order/'.$heroTask['order_id']))?>">Morgen ansehen</a>
        <?php else:?>
            <div class="task-hero-copy">
                <span class="eyebrow">Heute</span>
                <h2>Aktuell nichts fällig</h2>
                <p>Es ist momentan kein Nachweis offen oder für heute geplant.</p>
            </div>
            <span class="task-done-mark">✓</span>
        <?php endif;?>
    </section>

    <?php if($latestUpdate):?>
    <section class="seller-update-banner">
        <div class="seller-update-icon">✦</div>
        <div class="seller-update-copy">
            <div class="seller-update-meta"><span>Neu</span><?=e(date('d.m.Y',strtotime($latestUpdate['published_at'])))?></div>
            <h2><?=e($latestUpdate['title'])?></h2>
            <?php if($latestUpdate['summary']):?><p><?=e($latestUpdate['summary'])?></p><?php endif;?>
        </div>
        <div class="seller-update-actions">
            <form method="post" action="<?=e(url('/seller/update/'.$latestUpdate['id'].'/open'))?>"><button class="btn">Ansehen</button></form>
            <?php if(!is_seller_impersonation()):?><form method="post" action="<?=e(url('/seller/update/'.$latestUpdate['id'].'/dismiss'))?>"><button class="btn ghost">Schließen</button></form><?php endif;?>
        </div>
    </section>
    <?php endif;?>

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

    <?php if($todayTimeline):?>
    <section class="panel seller-today-timeline">
        <div class="section-head"><h2>Heute</h2><span class="muted"><?=e(date_de($todayDate))?></span></div>
        <div class="task-timeline">
        <?php foreach($todayTimeline as $item):
            $label=match($item['state']){'done'=>'Erledigt','open'=>'Jetzt fällig','future'=>'Später','closed'=>'Verpasst',default=>'Offen'};
            $statusClass=match($item['state']){'done'=>'fulfilled','open'=>'submitted','closed'=>'not_fulfilled',default=>'planned'};
        ?>
            <a class="task-timeline-row timeline-<?=e($item['state'])?>" href="<?=e(url('/seller/order/'.$item['order_id']))?>">
                <div class="task-time"><?=e(!empty($item['start'])?$item['start']->format('H:i'):'–')?></div>
                <div class="task-timeline-dot"></div>
                <div class="task-timeline-copy"><strong><?=e($item['label'])?></strong><span><?=e($item['order_no'])?> · Tag <?=e($item['day_no'])?> · <?=e($item['window'])?><?=!empty($item['late'])?' · Nachreichung':''?></span></div>
                <span class="status status-<?=e($statusClass)?>"><?=e($label)?></span>
            </a>
        <?php endforeach;?>
        </div>
    </section>
    <?php endif;?>

    <?php if($missedToday):?>
    <div class="notice warning seller-missed-summary"><strong><?=count($missedToday)?> heutige Nachweisfrist(en) verpasst.</strong><br>Öffne den betreffenden Auftrag, um den aktuellen Status oder eine mögliche Nachforderung zu sehen.</div>
    <?php endif;?>

    <?php if($tomorrowTasks):?>
    <section class="panel seller-tomorrow">
        <div class="section-head"><h2>Morgen</h2><span class="muted"><?=e(date_de($tomorrowDate))?></span></div>
        <div class="list">
        <?php foreach($tomorrowTasks as $task):?>
            <a class="list-row" href="<?=e(url('/seller/order/'.$task['order_id']))?>">
                <div><strong><?=e($task['order_no'].' · '.$task['title'])?></strong><span>Tag <?=e($task['day_no'])?> · <?=e(implode(' · ',array_map(static fn($x)=>$x['label'].' '.$x['window'],$task['events'])))?></span></div>
                <span class="status status-planned"><?=e($task['first_start']->format('H:i'))?> Uhr</span>
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

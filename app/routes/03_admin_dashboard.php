<?php
declare(strict_types=1);

if ($path === '/admin' && $method === 'GET') {
    require_admin();
    $todayDate=new DateTimeImmutable('today');

    $preReady=db()->query("SELECT o.id,o.order_no,o.title_snapshot,s.first_name,s.last_name,o.precheck_photo_count,
        (SELECT COUNT(*) FROM precheck_uploads p WHERE p.order_id=o.id) photo_count
        FROM orders o JOIN sellers s ON s.id=o.seller_id
        WHERE o.status='precheck'
          AND (SELECT COUNT(*) FROM precheck_uploads p2 WHERE p2.order_id=o.id)>=o.precheck_photo_count
        ORDER BY o.created_at LIMIT 10")->fetchAll();

    $submitted=db()->query("SELECT d.id day_id,d.day_no,o.id order_id,o.order_no,o.title_snapshot,s.first_name,s.last_name
        FROM order_days d JOIN orders o ON o.id=d.order_id JOIN sellers s ON s.id=o.seller_id
        WHERE d.status='submitted' ORDER BY d.submitted_at,d.id LIMIT 12")->fetchAll();

    $candidates=db()->query("SELECT d.*,o.id order_id,o.order_no,o.title_snapshot,o.started_at,o.offer_id,o.required_success_days,o.align_to_offer_end,
        s.first_name,s.last_name
        FROM order_days d JOIN orders o ON o.id=d.order_id JOIN sellers s ON s.id=o.seller_id
        WHERE o.status='running' AND d.status='planned'
          AND d.id=(SELECT d2.id FROM order_days d2 WHERE d2.order_id=o.id AND d2.status IN('planned','submitted') ORDER BY d2.day_no LIMIT 1)
        ORDER BY d.day_no")->fetchAll();
    $missed=[];foreach($candidates as $d)if(day_is_missed($d,$d))$missed[]=$d;

    $late=db()->query("SELECT d.id day_id,d.day_no,d.late_submission_requested_at,o.id order_id,o.order_no,o.title_snapshot,s.first_name,s.last_name
        FROM order_days d JOIN orders o ON o.id=d.order_id JOIN sellers s ON s.id=o.seller_id
        WHERE o.status='running' AND d.status='planned' AND d.late_submission_allowed=1
        ORDER BY d.late_submission_requested_at")->fetchAll();

    $scents=db()->query("SELECT r.id,r.subject,r.requested_at,s.id seller_id,s.first_name,s.last_name
        FROM scent_requests r JOIN sellers s ON s.id=r.seller_id WHERE r.status='pending'
        ORDER BY r.requested_at LIMIT 10")->fetchAll();

    $shipping=db()->query("SELECT o.offer_id,MIN(o.id) order_id,MIN(sh.due_date) due_date,MAX(ofr.offer_no) offer_no,MAX(ofr.title) offer_title,
        MAX(s.first_name) first_name,MAX(s.last_name) last_name
        FROM order_shipments sh JOIN orders o ON o.id=sh.order_id JOIN offers ofr ON ofr.id=o.offer_id JOIN sellers s ON s.id=o.seller_id
        WHERE sh.status='pending' AND sh.due_date<=DATE_ADD(CURDATE(),INTERVAL 1 DAY)
        GROUP BY o.offer_id ORDER BY due_date")->fetchAll();

    $available=(float)db()->query("SELECT COALESCE(SUM(amount),0) FROM seller_wallet_entries WHERE status='available'")->fetchColumn();
    $walletSellers=db()->query("SELECT s.id,s.first_name,s.last_name,SUM(w.amount) amount
        FROM seller_wallet_entries w JOIN sellers s ON s.id=w.seller_id WHERE w.status='available'
        GROUP BY s.id,s.first_name,s.last_name ORDER BY amount DESC LIMIT 8")->fetchAll();

    $unreadMessages=(int)db()->query("SELECT COUNT(*) FROM order_messages WHERE sender_role='seller' AND read_by_admin_at IS NULL")->fetchColumn();
    $messageRows=db()->query("SELECT m.id,m.body,m.created_at,o.id order_id,o.order_no,s.first_name,s.last_name
        FROM order_messages m JOIN orders o ON o.id=m.order_id JOIN sellers s ON s.id=o.seller_id
        WHERE m.sender_role='seller' AND m.read_by_admin_at IS NULL ORDER BY m.created_at LIMIT 8")->fetchAll();

    $q=db()->query("SELECT e.id event_id,e.label,e.window_start,e.window_end,e.all_day,d.day_no,d.id day_id,
        o.id order_id,o.order_no,o.title_snapshot,o.started_at,o.offer_id,o.required_success_days,o.align_to_offer_end,
        s.first_name,s.last_name
        FROM order_day_events e JOIN order_days d ON d.id=e.day_id JOIN orders o ON o.id=d.order_id JOIN sellers s ON s.id=o.seller_id
        WHERE o.status='running' AND d.status='planned' AND e.status='planned'
        ORDER BY d.day_no,e.event_no LIMIT 150");
    $todayEvents=[];
    foreach($q->fetchAll() as $ev){
        $date=scheduled_order_day_date($ev,(int)$ev['day_no']);
        if(!$date||$date->format('Y-m-d')!==$todayDate->format('Y-m-d'))continue;
        $ev['_state']=event_window_state($ev,$date);
        $ev['_date']=$date;
        $todayEvents[]=$ev;
    }

    $attentionCount=count($preReady)+count($submitted)+count($missed)+count($late)+count($scents)+count($shipping)+$unreadMessages;

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Arbeitszentrale</span><h1>Heute</h1><p><?=e(date_de($todayDate))?> · offene Prüfungen, Fristen, Kommunikation und Auszahlungen.</p></div>
        <div class="head-actions"><a class="btn ghost" href="<?=e(url('/admin/notifications'))?>">Mitteilungen</a><a class="btn" href="<?=e(url('/admin/offers/new'))?>">+ Angebot</a></div>
    </div>

    <div class="stats">
        <a class="stat stat-link" href="<?=e(url('/admin/reviews'))?>"><span>Prüfungen bereit</span><strong><?=count($preReady)+count($submitted)+count($missed)?></strong></a>
        <div class="stat"><span>Nachreichungen offen</span><strong><?=count($late)?></strong></div>
        <a class="stat stat-link" href="<?=e(url('/admin/scent-requests'))?>"><span>Duftproben offen</span><strong><?=count($scents)?></strong></a>
        <a class="stat stat-link" href="<?=e(url('/admin/wallets'))?>"><span>Auszahlbar</span><strong><?=money($available)?></strong></a>
    </div>
    <div class="stats compact">
        <div class="stat"><span>Heutige Nachweise</span><strong><?=count($todayEvents)?></strong></div>
        <div class="stat"><span>Versand heute/überfällig</span><strong><?=count($shipping)?></strong></div>
        <div class="stat"><span>Ungelesene Nachrichten</span><strong><?=$unreadMessages?></strong></div>
    </div>

    <?php if($attentionCount===0&&!$todayEvents&&!$walletSellers):?><div class="notice success"><strong>Aktuell ist nichts Dringendes offen.</strong></div><?php endif;?>

    <div class="grid two today-grid">
        <section class="panel">
            <div class="section-head"><h2>Heutige Nachweise</h2><span class="muted"><?=count($todayEvents)?></span></div>
            <div class="list"><?php foreach($todayEvents as $ev):?>
                <a class="list-row" href="<?=e(url('/admin/order/'.$ev['order_id']))?>">
                    <div><strong><?=e($ev['order_no'].' · '.$ev['label'])?></strong><span><?=e($ev['first_name'].' '.$ev['last_name'])?> · Tag <?=e($ev['day_no'])?> · <?=e(event_window_text($ev))?></span></div>
                    <span class="status <?=e($ev['_state']==='closed'?'status-not_fulfilled':($ev['_state']==='open'?'status-submitted':'status-planned'))?>"><?=e($ev['_state']==='closed'?'Verpasst':($ev['_state']==='open'?'Jetzt offen':'Später'))?></span>
                </a>
            <?php endforeach;?><?php if(!$todayEvents):?><div class="empty">Heute keine noch offenen Nachweisvorgänge.</div><?php endif;?></div>
        </section>

        <section class="panel">
            <div class="section-head"><h2>Prüfcenter</h2><a href="<?=e(url('/admin/reviews'))?>">Öffnen</a></div>
            <div class="attention-summary">
                <a href="<?=e(url('/admin/reviews'))?>"><strong><?=count($preReady)?></strong><span>Vorabkontrollen bereit</span></a>
                <a href="<?=e(url('/admin/reviews'))?>"><strong><?=count($submitted)?></strong><span>Tage eingereicht</span></a>
                <a href="<?=e(url('/admin/reviews'))?>"><strong><?=count($missed)?></strong><span>Fristen verpasst</span></a>
            </div>
        </section>

        <section class="panel">
            <div class="section-head"><h2>Nachreichungen</h2><span class="muted"><?=count($late)?></span></div>
            <div class="list"><?php foreach($late as $d):?><a class="list-row" href="<?=e(url('/admin/order/'.$d['order_id']))?>"><div><strong><?=e($d['order_no'].' · Tag '.$d['day_no'])?></strong><span><?=e($d['first_name'].' '.$d['last_name'])?> · angefordert <?=e(date('d.m.Y H:i',strtotime($d['late_submission_requested_at'])))?> Uhr</span></div><span>→</span></a><?php endforeach;?><?php if(!$late):?><div class="empty">Keine Nachreichung offen.</div><?php endif;?></div>
        </section>

        <section class="panel">
            <div class="section-head"><h2>Versand</h2><span class="muted">Heute & überfällig</span></div>
            <div class="list"><?php foreach($shipping as $sh):$over=$sh['due_date']<$todayDate->format('Y-m-d');?>
                <a class="list-row" href="<?=e(url('/admin/order/'.$sh['order_id']))?>"><div><strong><?=e($sh['offer_no'].' · '.$sh['offer_title'])?></strong><span><?=e($sh['first_name'].' '.$sh['last_name'])?> · <?=e(date('d.m.Y',strtotime($sh['due_date'])))?></span></div><span class="status <?=e($over?'status-not_fulfilled':'status-shipping')?>"><?=e($over?'Überfällig':'Fällig')?></span></a>
            <?php endforeach;?><?php if(!$shipping):?><div class="empty">Kein Versand heute oder überfällig.</div><?php endif;?></div>
        </section>

        <section class="panel">
            <div class="section-head"><h2>Offene Duftproben</h2><a href="<?=e(url('/admin/scent-requests'))?>">Alle</a></div>
            <div class="list"><?php foreach($scents as $r):?><a class="list-row" href="<?=e(url('/admin/scent-requests'))?>"><div><strong><?=e($r['subject'])?></strong><span><?=e($r['first_name'].' '.$r['last_name'])?> · seit <?=e(date('d.m.Y H:i',strtotime($r['requested_at'])))?></span></div><span class="status status-submitted">Offen</span></a><?php endforeach;?><?php if(!$scents):?><div class="empty">Keine offene Duftprobe.</div><?php endif;?></div>
        </section>

        <section class="panel">
            <div class="section-head"><h2>Neue Nachrichten</h2><span class="muted"><?=$unreadMessages?></span></div>
            <div class="list"><?php foreach($messageRows as $msg):?><a class="list-row" href="<?=e(url('/admin/order/'.$msg['order_id']))?>"><div><strong><?=e($msg['order_no'].' · '.$msg['first_name'].' '.$msg['last_name'])?></strong><span><?=e(mb_strimwidth($msg['body'],0,100,'…'))?></span></div><span><?=e(date('H:i',strtotime($msg['created_at'])))?></span></a><?php endforeach;?><?php if(!$messageRows):?><div class="empty">Keine ungelesenen Nachrichten.</div><?php endif;?></div>
        </section>
    </div>

    <?php if($walletSellers):?><section class="panel">
        <div class="section-head"><h2>Auszahlungen bereit</h2><a href="<?=e(url('/admin/wallets'))?>">Wallets</a></div>
        <div class="list"><?php foreach($walletSellers as $w):?><a class="list-row" href="<?=e(url('/admin/wallets/'.$w['id']))?>"><div><strong><?=e($w['first_name'].' '.$w['last_name'])?></strong><span>Auszahlbare Buchungen auswählen und dokumentieren</span></div><strong><?=money($w['amount'])?></strong></a><?php endforeach;?></div>
    </section><?php endif;?>

    <?php render('Heute',ob_get_clean());exit;
}

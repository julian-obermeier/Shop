<?php
declare(strict_types=1);
// SELLER DASHBOARD/LISTS
if ($path==='/seller' && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT COUNT(*) FROM offers WHERE seller_id=? AND status='sent'");$q->execute([$s['id']]);$new=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='precheck'");$q->execute([$s['id']]);$pre=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='running'");$q->execute([$s['id']]);$running=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='shipping'");$q->execute([$s['id']]);$shipping=(int)$q->fetchColumn();

    $q=db()->prepare("SELECT d.*,o.title_snapshot,o.order_no,o.started_at,o.daily_photo_count,o.offer_id,o.is_final_day_position
        FROM order_days d
        JOIN orders o ON o.id=d.order_id
        WHERE o.seller_id=? AND o.status='running' AND d.status IN('planned','submitted')
          AND d.id=(SELECT d2.id FROM order_days d2 WHERE d2.order_id=o.id AND d2.status IN('planned','submitted') ORDER BY d2.day_no LIMIT 1)
        ORDER BY o.id LIMIT 8");
    $q->execute([$s['id']]);$today=$q->fetchAll();

    $q=db()->prepare("SELECT sh.*,o.id order_id,o.order_no,o.title_snapshot
        FROM order_shipments sh JOIN orders o ON o.id=sh.order_id
        WHERE o.seller_id=? AND o.status='shipping' AND sh.status='pending'
        ORDER BY sh.due_date,o.id");
    $q->execute([$s['id']]);$shipments=$q->fetchAll();

    $wallet=wallet_summary((int)$s['id']);

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Verkäuferin</span><h1>Hallo <?=e($s['first_name'])?></h1><p>Deine offenen Schritte, Versandaufgaben und Vergütung.</p></div>
        <a class="btn ghost" href="<?=e(url('/seller/wallet'))?>">Wallet · <?=money($wallet['available'])?> auszahlbar</a>
    </div>
    <div class="stats">
        <div class="stat"><span>Neue Angebote</span><strong><?=$new?></strong></div>
        <div class="stat"><span>Vorabkontrollen</span><strong><?=$pre?></strong></div>
        <div class="stat"><span>Laufende Aufträge</span><strong><?=$running?></strong></div>
        <div class="stat"><span>Versand offen</span><strong><?=$shipping?></strong></div>
    </div>

    <section class="panel"><h2>Als Nächstes</h2><div class="list">
    <?php foreach($today as $d):
        $scheduled=scheduled_order_day_date($d,(int)$d['day_no']);
        $isFuture=$scheduled && $scheduled>new DateTimeImmutable('today');
        $nextLabel=null;$window=null;$windowState=null;
        if($d['status']==='planned'){
            $events=day_events((int)$d['id']);
            if(!$events)$events=ensure_day_events((int)$d['id'],(int)$d['required_photo_count']);
            foreach($events as $ev){
                if($ev['status']==='planned'){
                    $nextLabel=$ev['label'];$window=event_window_text($ev);$windowState=event_window_state($ev,$scheduled);break;
                }
            }
        }
    ?>
        <a class="list-row" href="<?=e(url('/seller/order/'.$d['order_id']))?>">
            <div>
                <strong><?=e($d['order_no'].' · '.$d['title_snapshot'])?></strong>
                <span>Tag <?=e($d['day_no'])?> · <?=e(date_de($scheduled))?> ·
                    <?=e($isFuture?'Geplant':($d['status']==='submitted'?'Wartet auf Prüfung':($nextLabel??'Offen')))?>
                    <?php if(!$isFuture&&$nextLabel):?> · <?=e($window)?> · <?=e($windowState==='open'?'jetzt offen':($windowState==='closed'?'Frist verpasst':'noch geschlossen'))?><?php endif;?>
                </span>
            </div><span>→</span>
        </a>
    <?php endforeach;?>

    <?php foreach($shipments as $sh):?>
        <a class="list-row" href="<?=e(url('/seller/order/'.$sh['order_id']))?>">
            <div><strong><?=e($sh['order_no'].' · '.$sh['title_snapshot'])?></strong><span>Versand · fällig <?=e(date_de(new DateTimeImmutable($sh['due_date'])))?></span></div>
            <span class="status status-shipping">Versand</span>
        </a>
    <?php endforeach;?>

    <?php if(!$today&&!$shipments):?><div class="empty">Aktuell ist nichts offen.</div><?php endif;?>
    </div></section>

    <?php render('Übersicht',ob_get_clean());exit;
}

if ($path==='/seller/offers' && $method==='GET') {
    $s=require_seller();$q=db()->prepare("SELECT o.*,(SELECT COUNT(*) FROM offer_positions p WHERE p.offer_id=o.id) positions FROM offers o WHERE seller_id=? AND status<>'draft' ORDER BY created_at DESC");$q->execute([$s['id']]);$offers=$q->fetchAll();
    ob_start();?><div class="page-head"><div><span class="eyebrow">Angebote</span><h1>Meine Angebote</h1></div></div><div class="cards"><?php foreach($offers as $o):?><a class="card link-card" href="<?=e(url('/seller/offer/'.$o['id']))?>"><div class="card-top"><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span><span class="muted"><?=e($o['offer_no'])?></span></div><h3><?=e($o['title'])?></h3><p class="muted"><?=e($o['positions'])?> Position(en)</p></a><?php endforeach;?><?php if(!$offers):?><div class="empty">Keine Angebote vorhanden.</div><?php endif;?></div><?php render('Meine Angebote',ob_get_clean());exit;
}

if ($path==='/seller/orders' && $method==='GET') {
    $s=require_seller();$q=db()->prepare("SELECT * FROM orders WHERE seller_id=? ORDER BY FIELD(status,'running','shipping','precheck','completed','cancelled'),created_at DESC");$q->execute([$s['id']]);$orders=$q->fetchAll();
    ob_start();?><div class="page-head"><div><span class="eyebrow">Aufträge</span><h1>Meine Aufträge</h1></div></div><div class="list"><?php foreach($orders as $o):?><a class="list-row" href="<?=e(url('/seller/order/'.$o['id']))?>"><div><strong><?=e($o['order_no'].' · '.$o['title_snapshot'])?></strong><span><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?> erfolgreich · +<?=e($o['extension_days'])?> Tag(e)<?php if($o['started_at']):?> · <?=!empty($o['is_final_day_position'])?'Termin':'Start'?> <?=e(date_de(scheduled_order_day_date($o,1)))?><?php endif;?></span></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></a><?php endforeach;?><?php if(!$orders):?><div class="empty">Noch keine Aufträge vorhanden.</div><?php endif;?></div><?php render('Meine Aufträge',ob_get_clean());exit;
}

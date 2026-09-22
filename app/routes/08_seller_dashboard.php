<?php
declare(strict_types=1);
// SELLER DASHBOARD/LISTS
if ($path==='/seller' && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT COUNT(*) FROM offers WHERE seller_id=? AND status='sent'");$q->execute([$s['id']]);$new=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='precheck'");$q->execute([$s['id']]);$pre=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status='running'");$q->execute([$s['id']]);$running=(int)$q->fetchColumn();
    $q=db()->prepare("SELECT d.*,o.title_snapshot,o.order_no,o.started_at
        FROM order_days d
        JOIN orders o ON o.id=d.order_id
        WHERE o.seller_id=? AND o.status='running' AND d.status IN('planned','submitted')
          AND d.id=(SELECT d2.id FROM order_days d2 WHERE d2.order_id=o.id AND d2.status IN('planned','submitted') ORDER BY d2.day_no LIMIT 1)
        ORDER BY o.id LIMIT 8");
    $q->execute([$s['id']]);$today=$q->fetchAll();

    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Verkäuferin</span><h1>Hallo <?=e($s['first_name'])?></h1><p>Deine offenen Schritte und Aufträge.</p></div></div>
    <div class="stats">
        <div class="stat"><span>Neue Angebote</span><strong><?=$new?></strong></div>
        <div class="stat"><span>Vorabkontrollen</span><strong><?=$pre?></strong></div>
        <div class="stat"><span>Laufende Aufträge</span><strong><?=$running?></strong></div>
    </div>
    <section class="panel"><h2>Als Nächstes</h2><div class="list">
    <?php foreach($today as $d):
        $scheduled=order_day_date($d['started_at'],$d['day_no']);
        $isFuture=$scheduled && $scheduled>new DateTimeImmutable('today');
    ?>
        <a class="list-row" href="<?=e(url('/seller/order/'.$d['order_id']))?>">
            <div><strong><?=e($d['order_no'].' · '.$d['title_snapshot'])?></strong><span>Tag <?=e($d['day_no'])?> · <?=e(date_de($scheduled))?> · <?=e($isFuture?'Geplant':day_status_label($d['status']))?></span></div><span>→</span>
        </a>
    <?php endforeach;?>
    <?php if(!$today):?><div class="empty">Aktuell ist nichts offen.</div><?php endif;?>
    </div></section>
    <?php render('Übersicht',ob_get_clean());exit;
}

if ($path==='/seller/offers' && $method==='GET') {
    $s=require_seller();$q=db()->prepare("SELECT o.*,(SELECT COUNT(*) FROM offer_positions p WHERE p.offer_id=o.id) positions FROM offers o WHERE seller_id=? AND status<>'draft' ORDER BY created_at DESC");$q->execute([$s['id']]);$offers=$q->fetchAll();
    ob_start();?><div class="page-head"><div><span class="eyebrow">Angebote</span><h1>Meine Angebote</h1></div></div><div class="cards"><?php foreach($offers as $o):?><a class="card link-card" href="<?=e(url('/seller/offer/'.$o['id']))?>"><div class="card-top"><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span><span class="muted"><?=e($o['offer_no'])?></span></div><h3><?=e($o['title'])?></h3><p class="muted"><?=e($o['positions'])?> Position(en)</p></a><?php endforeach;?><?php if(!$offers):?><div class="empty">Keine Angebote vorhanden.</div><?php endif;?></div><?php render('Meine Angebote',ob_get_clean());exit;
}

if ($path==='/seller/orders' && $method==='GET') {
    $s=require_seller();$q=db()->prepare('SELECT * FROM orders WHERE seller_id=? ORDER BY FIELD(status,\'running\',\'precheck\',\'completed\',\'cancelled\'),created_at DESC');$q->execute([$s['id']]);$orders=$q->fetchAll();
    ob_start();?><div class="page-head"><div><span class="eyebrow">Aufträge</span><h1>Meine Aufträge</h1></div></div><div class="list"><?php foreach($orders as $o):?><a class="list-row" href="<?=e(url('/seller/order/'.$o['id']))?>"><div><strong><?=e($o['order_no'].' · '.$o['title_snapshot'])?></strong><span><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?> erfolgreich · +<?=e($o['extension_days'])?> Tag(e)<?php if($o['started_at']):?> · Start <?=e(date_de(order_day_date($o['started_at'],1)))?><?php endif;?></span></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></a><?php endforeach;?><?php if(!$orders):?><div class="empty">Noch keine Aufträge vorhanden.</div><?php endif;?></div><?php render('Meine Aufträge',ob_get_clean());exit;
}

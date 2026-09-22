<?php
declare(strict_types=1);

if (preg_match('#^/admin/seller/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();$sellerId=(int)$m[1];
    $q=db()->prepare('SELECT * FROM sellers WHERE id=?');$q->execute([$sellerId]);$seller=$q->fetch();if(!$seller)not_found();

    $wallet=wallet_summary($sellerId);$profile=payout_profile($sellerId);
    $q=db()->prepare("SELECT o.*,(SELECT COUNT(*) FROM offer_positions p WHERE p.offer_id=o.id) positions FROM offers o WHERE o.seller_id=? ORDER BY o.created_at DESC LIMIT 12");
    $q->execute([$sellerId]);$offers=$q->fetchAll();
    $q=db()->prepare("SELECT * FROM orders WHERE seller_id=? ORDER BY FIELD(status,'running','precheck','shipping','completed','cancelled'),created_at DESC LIMIT 30");
    $q->execute([$sellerId]);$orders=$q->fetchAll();
    $q=db()->prepare("SELECT * FROM scent_requests WHERE seller_id=? ORDER BY FIELD(status,'pending','answered','cancelled'),requested_at DESC LIMIT 10");
    $q->execute([$sellerId]);$scents=$q->fetchAll();
    $q=db()->prepare("SELECT d.*,o.order_no,o.id order_id FROM order_days d JOIN orders o ON o.id=d.order_id
        WHERE o.seller_id=? AND d.status='planned' AND d.late_submission_allowed=1 ORDER BY d.late_submission_requested_at DESC");
    $q->execute([$sellerId]);$late=$q->fetchAll();
    $q=db()->prepare("SELECT * FROM payout_batches WHERE seller_id=? ORDER BY paid_at DESC LIMIT 10");
    $q->execute([$sellerId]);$payouts=$q->fetchAll();

    $openOffers=0;$activeOrders=0;foreach($offers as $x)if(in_array($x['status'],['draft','sent','accepted','active'],true))$openOffers++;
    foreach($orders as $x)if(in_array($x['status'],['precheck','running','shipping'],true))$activeOrders++;

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Verkäuferinnen-Akte</span><h1><?=e($seller['first_name'].' '.$seller['last_name'])?></h1><p><?=e($seller['email'])?> · <?=((int)$seller['active'])?'Aktiv':'Inaktiv'?></p></div>
        <div class="head-actions">
            <?php if((int)$seller['active']):?>
                <a class="btn" href="<?=e(url('/admin/offers/new').'?seller_id='.$sellerId)?>">+ Angebot erstellen</a>
                <a class="btn ghost" href="<?=e(url('/admin/scent-requests').'?seller_id='.$sellerId)?>">Duftprobe anfragen</a>
                <form method="post" action="<?=e(url('/admin/sellers/'.$sellerId.'/impersonate'))?>"><button class="btn ghost">Als Verkäuferin anmelden</button></form>
            <?php endif;?>
        </div>
    </div>

    <div class="stats">
        <div class="stat"><span>Offene Angebote</span><strong><?=$openOffers?></strong></div>
        <div class="stat"><span>Aktive Aufträge</span><strong><?=$activeOrders?></strong></div>
        <div class="stat"><span>Auszahlbar</span><strong><?=money($wallet['available'])?></strong></div>
        <div class="stat"><span>Offene Nachforderungen</span><strong><?=count($late)?></strong></div>
    </div>

    <div class="grid two">
        <section class="panel">
            <div class="section-head"><h2>Wallet & Auszahlung</h2><a href="<?=e(url('/admin/wallets/'.$sellerId))?>">Wallet öffnen</a></div>
            <div class="mini-grid">
                <span><b><?=money($wallet['reserved'])?></b> Vorgemerkt</span>
                <span><b><?=money($wallet['available'])?></b> Auszahlbar</span>
                <span><b><?=money($wallet['paid'])?></b> Ausgezahlt</span>
            </div>
            <?php if($profile['payout_method']==='paypal'):?>
                <p><strong>PayPal</strong><br><?=e(mask_email($profile['paypal_email']))?></p>
            <?php elseif($profile['payout_method']==='bank'):?>
                <p><strong>Banküberweisung</strong><br><?=e($profile['bank_holder']?:'–')?><br>IBAN <?=e(mask_iban($profile['bank_iban']))?></p>
            <?php else:?><div class="notice warning">Noch kein Auszahlungsweg hinterlegt.</div><?php endif;?>
            <?php if($payouts):?><div class="compact-history"><?php foreach($payouts as $p):?><div><span><?=e(date('d.m.Y',strtotime($p['paid_at'])))?></span><strong><?=money($p['amount'])?></strong></div><?php endforeach;?></div><?php endif;?>
        </section>

        <section class="panel">
            <h2>Aktuell offen</h2>
            <?php foreach($late as $d):?><a class="attention-row" href="<?=e(url('/admin/order/'.$d['order_id']))?>"><strong>Nachweise nachgefordert</strong><span><?=e($d['order_no'])?> · Tag <?=e($d['day_no'])?></span></a><?php endforeach;?>
            <?php foreach($scents as $scent): if($scent['status']!=='pending')continue;?><a class="attention-row" href="<?=e(url('/admin/scent-requests'))?>"><strong>Duftprobe offen</strong><span><?=e($scent['subject'])?> · seit <?=e(date('d.m.Y H:i',strtotime($scent['requested_at'])))?></span></a><?php endforeach;?>
            <?php if(!$late&&!array_filter($scents,static fn($x)=>$x['status']==='pending')):?><div class="empty">Keine besonderen offenen Vorgänge.</div><?php endif;?>
        </section>
    </div>

    <section class="panel">
        <div class="section-head"><h2>Angebote</h2><a href="<?=e(url('/admin/offers/new').'?seller_id='.$sellerId)?>">Neues Angebot</a></div>
        <div class="list"><?php foreach($offers as $o):?><a class="list-row" href="<?=e(url('/admin/offer/'.$o['id']))?>"><div><strong><?=e($o['offer_no'].' · '.$o['title'])?></strong><span><?=e($o['positions'])?> Position(en) · <?=e(date('d.m.Y',strtotime($o['created_at'])))?></span></div><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span></a><?php endforeach;?><?php if(!$offers):?><div class="empty">Noch keine Angebote.</div><?php endif;?></div>
    </section>

    <section class="panel">
        <div class="section-head"><h2>Aufträge</h2><a href="<?=e(url('/admin/orders'))?>">Alle Aufträge</a></div>
        <div class="list"><?php foreach($orders as $o):?><a class="list-row" href="<?=e(url('/admin/order/'.$o['id']))?>"><div><strong><?=e($o['order_no'].' · '.$o['title_snapshot'])?></strong><span><?=e($o['successful_days'])?>/<?=e($o['required_success_days'])?> erfolgreiche Tage</span></div><span class="status status-<?=e($o['status'])?>"><?=e(order_status_label($o['status']))?></span></a><?php endforeach;?><?php if(!$orders):?><div class="empty">Noch keine Aufträge.</div><?php endif;?></div>
    </section>

    <section class="panel">
        <div class="section-head"><h2>Duftproben</h2><a href="<?=e(url('/admin/scent-requests').'?seller_id='.$sellerId)?>">Neue Anfrage</a></div>
        <div class="list"><?php foreach($scents as $r):?><div class="list-row static"><div><strong><?=e($r['subject'])?></strong><span><?=e(date('d.m.Y H:i',strtotime($r['requested_at'])))?><?php if($r['rating']!==null):?> · <?=e($r['rating'])?>/10<?php endif;?></span></div><span class="status status-<?=e($r['status']==='answered'?'fulfilled':($r['status']==='cancelled'?'not_fulfilled':'submitted'))?>"><?=e($r['status']==='answered'?'Beantwortet':($r['status']==='cancelled'?'Widerrufen':'Offen'))?></span></div><?php endforeach;?><?php if(!$scents):?><div class="empty">Noch keine Duftproben.</div><?php endif;?></div>
    </section>

    <?php render('Verkäuferinnen-Akte',ob_get_clean());exit;
}

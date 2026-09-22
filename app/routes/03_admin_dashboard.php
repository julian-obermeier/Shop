<?php
declare(strict_types=1);
// ADMIN DASHBOARD
if ($path === '/admin' && $method === 'GET') {
    require_admin();

    $stats=[
        'offers'=>(int)db()->query("SELECT COUNT(*) FROM offers WHERE status IN('draft','sent','accepted','active')")->fetchColumn(),
        'precheck'=>(int)db()->query("SELECT COUNT(*) FROM orders o WHERE o.status='precheck' AND (SELECT COUNT(*) FROM precheck_uploads p WHERE p.order_id=o.id)>=o.precheck_photo_count")->fetchColumn(),
        'review'=>(int)db()->query("SELECT COUNT(*) FROM order_days WHERE status='submitted'")->fetchColumn(),
        'shipping'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE status='shipping'")->fetchColumn(),
        'available'=>(float)db()->query("SELECT COALESCE(SUM(amount),0) FROM seller_wallet_entries WHERE status='available'")->fetchColumn(),
        'scent'=>(int)db()->query("SELECT COUNT(*) FROM scent_requests WHERE status='pending'")->fetchColumn(),
    ];

    // Count only the first unresolved planned day of each running order when a required window has expired.
    $candidates=db()->query("SELECT d.*,o.started_at,o.daily_photo_count,o.offer_id,o.is_final_day_position
        FROM order_days d JOIN orders o ON o.id=d.order_id
        WHERE o.status='running' AND d.status='planned'
          AND d.id=(SELECT d2.id FROM order_days d2 WHERE d2.order_id=o.id AND d2.status IN('planned','submitted') ORDER BY d2.day_no LIMIT 1)")->fetchAll();
    $missed=0;foreach($candidates as $d)if(day_is_missed($d,$d))$missed++;

    $q=db()->query("SELECT o.*,s.first_name,s.last_name FROM offers o JOIN sellers s ON s.id=o.seller_id ORDER BY o.created_at DESC LIMIT 6");
    $recent=$q->fetchAll();

    ob_start(); ?>
    <div class="page-head">
        <div><span class="eyebrow">Admin</span><h1>Übersicht</h1><p>Prüfungen, Versand und Auszahlungen auf einen Blick.</p></div>
        <div class="head-actions"><a class="btn ghost" href="<?=e(url('/admin/reviews'))?>">Prüfcenter</a><a class="btn ghost" href="<?=e(url('/admin/wallets'))?>">Wallets</a><a class="btn" href="<?=e(url('/admin/offers/new'))?>">+ Angebot erstellen</a></div>
    </div>

    <div class="stats">
        <div class="stat"><span>Offene Angebote</span><strong><?=$stats['offers']?></strong></div>
        <a class="stat stat-link" href="<?=e(url('/admin/reviews'))?>"><span>Vorabkontrollen bereit</span><strong><?=$stats['precheck']?></strong></a>
        <a class="stat stat-link" href="<?=e(url('/admin/reviews'))?>"><span>Tage zu prüfen / verpasst</span><strong><?=$stats['review']+$missed?></strong></a>
        <a class="stat stat-link" href="<?=e(url('/admin/orders'))?>"><span>Versand offen</span><strong><?=$stats['shipping']?></strong></a>
    </div>

    <div class="stats compact">
        <a class="stat stat-link" href="<?=e(url('/admin/wallets'))?>"><span>Aktuell auszahlbar</span><strong><?=money($stats['available'])?></strong></a>
        <a class="stat stat-link" href="<?=e(url('/admin/scent-requests'))?>"><span>Duftproben offen</span><strong><?=$stats['scent']?></strong></a>
        <a class="stat stat-link" href="<?=e(url('/admin/settings'))?>"><span>Versandadresse</span><strong class="stat-date"><?=shipping_address_complete(shipping_address())?'Hinterlegt':'Fehlt'?></strong></a>
    </div>

    <section class="panel">
        <div class="section-head"><h2>Letzte Angebote</h2><a href="<?=e(url('/admin/offers'))?>">Alle anzeigen</a></div>
        <?php if($recent):?><div class="list"><?php foreach($recent as $o):?><a class="list-row" href="<?=e(url('/admin/offer/'.$o['id']))?>"><div><strong><?=e($o['offer_no'].' · '.$o['title'])?></strong><span><?=e($o['first_name'].' '.$o['last_name'])?></span></div><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span></a><?php endforeach;?></div>
        <?php else:?><div class="empty">Noch keine Angebote vorhanden.</div><?php endif;?>
    </section>

    <?php render('Admin',ob_get_clean());exit;
}

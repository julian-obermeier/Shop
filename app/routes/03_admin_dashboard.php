<?php
declare(strict_types=1);
// ADMIN DASHBOARD
if ($path === '/admin' && $method === 'GET') {
    require_admin();
    $stats=[
        'offers'=>(int)db()->query("SELECT COUNT(*) FROM offers WHERE status IN('draft','sent','accepted','active')")->fetchColumn(),
        'precheck'=>(int)db()->query("SELECT COUNT(*) FROM orders o WHERE o.status='precheck' AND (SELECT COUNT(*) FROM precheck_uploads p WHERE p.order_id=o.id)>=o.precheck_photo_count")->fetchColumn(),
        'review'=>(int)db()->query("SELECT COUNT(*) FROM order_days WHERE status='submitted'")->fetchColumn(),
        'running'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE status='running'")->fetchColumn(),
    ];
    $q=db()->query("SELECT o.*,s.first_name,s.last_name FROM offers o JOIN sellers s ON s.id=o.seller_id ORDER BY o.created_at DESC LIMIT 6"); $recent=$q->fetchAll();
    ob_start(); ?>
    <div class="page-head">
        <div><span class="eyebrow">Admin</span><h1>Übersicht</h1><p>Alles Wichtige auf einen Blick.</p></div>
        <div class="head-actions"><a class="btn ghost" href="<?=e(url('/admin/reviews'))?>">Prüfcenter</a><a class="btn" href="<?=e(url('/admin/offers/new'))?>">+ Angebot erstellen</a></div>
    </div>
    <div class="stats">
        <div class="stat"><span>Offene Angebote</span><strong><?=$stats['offers']?></strong></div>
        <a class="stat stat-link" href="<?=e(url('/admin/reviews'))?>"><span>Vorabkontrollen bereit</span><strong><?=$stats['precheck']?></strong></a>
        <a class="stat stat-link" href="<?=e(url('/admin/reviews'))?>"><span>Zu prüfende Tage</span><strong><?=$stats['review']?></strong></a>
        <div class="stat"><span>Laufende Aufträge</span><strong><?=$stats['running']?></strong></div>
    </div>
    <section class="panel"><div class="section-head"><h2>Letzte Angebote</h2><a href="<?=e(url('/admin/offers'))?>">Alle anzeigen</a></div><?php if($recent):?><div class="list"><?php foreach($recent as $o):?><a class="list-row" href="<?=e(url('/admin/offer/'.$o['id']))?>"><div><strong><?=e($o['offer_no'].' · '.$o['title'])?></strong><span><?=e($o['first_name'].' '.$o['last_name'])?></span></div><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span></a><?php endforeach;?></div><?php else:?><div class="empty">Noch keine Angebote vorhanden.</div><?php endif;?></section>
    <?php render('Admin',ob_get_clean());exit;
}

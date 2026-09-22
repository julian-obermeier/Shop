<?php
declare(strict_types=1);
// ADMIN OFFERS LIST/CREATE
if ($path === '/admin/offers' && $method === 'GET') {
    require_admin(); $offers=db()->query("SELECT o.*,s.first_name,s.last_name,(SELECT COUNT(*) FROM offer_positions p WHERE p.offer_id=o.id) positions FROM offers o JOIN sellers s ON s.id=o.seller_id ORDER BY o.created_at DESC")->fetchAll();
    ob_start(); ?><div class="page-head"><div><span class="eyebrow">Angebote</span><h1>Alle Angebote</h1></div><a class="btn" href="<?=e(url('/admin/offers/new'))?>">+ Neues Angebot</a></div><div class="cards"><?php foreach($offers as $o):?><a class="card link-card" href="<?=e(url('/admin/offer/'.$o['id']))?>"><div class="card-top"><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span><span class="muted"><?=e($o['offer_no'])?></span></div><h3><?=e($o['title'])?></h3><p><?=e($o['first_name'].' '.$o['last_name'])?></p><p class="muted"><?=e($o['positions'])?> Position(en)</p></a><?php endforeach;?><?php if(!$offers):?><div class="empty">Noch keine Angebote vorhanden.</div><?php endif;?></div><?php render('Angebote',ob_get_clean());exit;
}
if ($path === '/admin/offers/new' && $method === 'GET') {
    require_admin(); $sellers=db()->query('SELECT id,first_name,last_name,email FROM sellers WHERE active=1 ORDER BY first_name,last_name')->fetchAll();
    ob_start(); ?><div class="page-head"><div><span class="eyebrow">Neues Angebot</span><h1>Grunddaten festlegen</h1></div></div><form class="panel narrow" method="post"><label>Verkäuferin<select name="seller_id" required><option value="">Bitte auswählen</option><?php foreach($sellers as $s):?><option value="<?=e($s['id'])?>"><?=e($s['first_name'].' '.$s['last_name'].' · '.$s['email'])?></option><?php endforeach;?></select></label><label>Titel<input name="title" maxlength="190" required placeholder="z. B. Socken & Schuhe – September"></label><label>Hinweis zum Angebot<textarea name="intro" rows="4" placeholder="Kurze Einleitung für die Verkäuferin"></textarea></label><label>Verbindliche Regeln<textarea name="rules_text" rows="10" required placeholder="Regeln, die vor Annahme bestätigt werden müssen"></textarea></label><button class="btn">Entwurf erstellen</button></form><?php render('Neues Angebot',ob_get_clean());exit;
}
if ($path === '/admin/offers/new' && $method === 'POST') {
    $a=require_admin();$sellerId=(int)post('seller_id');$title=post('title');$rules=post('rules_text');
    $q=db()->prepare('SELECT COUNT(*) FROM sellers WHERE id=? AND active=1');$q->execute([$sellerId]);
    if(!$sellerId||!$title||!$rules||(int)$q->fetchColumn()!==1){flash('error','Verkäuferin, Titel und Regeln sind erforderlich.');redirect('/admin/offers/new');}
    $no=offer_number();db()->prepare("INSERT INTO offers(offer_no,seller_id,admin_id,title,intro,rules_text,status) VALUES(?,?,?,?,?,?,'draft')")->execute([$no,$sellerId,$a['id'],$title,post('intro')?:null,$rules]);
    $id=(int)db()->lastInsertId();log_event($id,null,'offer.created');redirect('/admin/offer/'.$id);
}


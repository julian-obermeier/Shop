<?php
declare(strict_types=1);
// ADMIN OFFERS LIST/CREATE
if ($path === '/admin/offers' && $method === 'GET') {
    require_admin();
    $offers=db()->query("SELECT o.*,s.first_name,s.last_name,(SELECT COUNT(*) FROM offer_positions p WHERE p.offer_id=o.id) positions FROM offers o JOIN sellers s ON s.id=o.seller_id ORDER BY o.created_at DESC")->fetchAll();
    ob_start(); ?>
    <div class="page-head"><div><span class="eyebrow">Angebote</span><h1>Alle Angebote</h1></div><a class="btn" href="<?=e(url('/admin/offers/new'))?>">+ Neues Angebot</a></div>
    <div class="cards"><?php foreach($offers as $o):?><a class="card link-card" href="<?=e(url('/admin/offer/'.$o['id']))?>"><div class="card-top"><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span><span class="muted"><?=e($o['offer_no'])?></span></div><h3><?=e($o['title'])?></h3><p><?=e($o['first_name'].' '.$o['last_name'])?></p><p class="muted"><?=e($o['positions'])?> Position(en)</p></a><?php endforeach;?><?php if(!$offers):?><div class="empty">Noch keine Angebote vorhanden.</div><?php endif;?></div>
    <?php render('Angebote',ob_get_clean());exit;
}

if ($path === '/admin/offers/new' && $method === 'GET') {
    require_admin();
    $sellers=db()->query('SELECT id,first_name,last_name,email FROM sellers WHERE active=1 ORDER BY first_name,last_name')->fetchAll();
    $templates=offer_template_catalog();
    ob_start(); ?>
    <div class="page-head"><div><span class="eyebrow">Neues Angebot</span><h1>Vorlage verwenden oder frei erstellen</h1></div></div>

    <div class="section-head"><h2>Vorlagen</h2></div>
    <div class="cards template-cards">
    <?php foreach($templates as $key=>$tpl):
        $sum=array_sum(array_map(static fn(array $p)=>(float)$p['compensation'],$tpl['positions']));
    ?>
        <article class="card">
            <span class="badge">Vorlage</span>
            <h3><?=e($tpl['name'])?></h3>
            <p><?=e($tpl['intro'])?></p>
            <div class="mini-grid">
                <span><b><?=count($tpl['positions'])?></b> Positionen</span>
                <span><b><?=money($sum)?></b> Gesamt</span>
                <span><b>14</b> Haupttage</span>
            </div>
            <form method="post" action="<?=e(url('/admin/offers/from-template/'.$key))?>">
                <label>Verkäuferin
                    <select name="seller_id" required>
                        <option value="">Bitte auswählen</option>
                        <?php foreach($sellers as $s):?><option value="<?=e($s['id'])?>"><?=e($s['first_name'].' '.$s['last_name'].' · '.$s['email'])?></option><?php endforeach;?>
                    </select>
                </label>
                <button class="btn full">Vorlage zuweisen</button>
            </form>
        </article>
    <?php endforeach;?>
    </div>

    <div class="section-head"><h2>Freies Angebot</h2></div>
    <form class="panel narrow" method="post">
        <label>Verkäuferin<select name="seller_id" required><option value="">Bitte auswählen</option><?php foreach($sellers as $s):?><option value="<?=e($s['id'])?>"><?=e($s['first_name'].' '.$s['last_name'].' · '.$s['email'])?></option><?php endforeach;?></select></label>
        <label>Titel<input name="title" maxlength="190" required placeholder="z. B. Socken & Schuhe – September"></label>
        <label>Hinweis zum Angebot<textarea name="intro" rows="4" placeholder="Kurze Einleitung für die Verkäuferin"></textarea></label>
        <label>Verbindliche Regeln<textarea name="rules_text" rows="10" required placeholder="Regeln, die vor Annahme bestätigt werden müssen"></textarea></label>
        <button class="btn">Entwurf erstellen</button>
    </form>
    <?php render('Neues Angebot',ob_get_clean());exit;
}

if ($path === '/admin/offers/new' && $method === 'POST') {
    $a=require_admin();$sellerId=(int)post('seller_id');$title=post('title');$rules=post('rules_text');
    $q=db()->prepare('SELECT COUNT(*) FROM sellers WHERE id=? AND active=1');$q->execute([$sellerId]);
    if(!$sellerId||!$title||!$rules||(int)$q->fetchColumn()!==1){flash('error','Verkäuferin, Titel und Regeln sind erforderlich.');redirect('/admin/offers/new');}
    $no=offer_number();
    db()->prepare("INSERT INTO offers(offer_no,seller_id,admin_id,title,intro,rules_text,status) VALUES(?,?,?,?,?,?,'draft')")
        ->execute([$no,$sellerId,$a['id'],$title,post('intro')?:null,$rules]);
    $id=(int)db()->lastInsertId();
    log_event($id,null,'offer.created');
    redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offers/from-template/([a-z0-9_-]+)$#',$path,$m) && $method==='POST') {
    $a=require_admin();$key=$m[1];$tpl=offer_template($key);$sellerId=(int)post('seller_id');
    if(!$tpl){flash('error','Vorlage nicht gefunden.');redirect('/admin/offers/new');}
    $q=db()->prepare('SELECT COUNT(*) FROM sellers WHERE id=? AND active=1');$q->execute([$sellerId]);
    if(!$sellerId||(int)$q->fetchColumn()!==1){flash('error','Bitte eine aktive Verkäuferin auswählen.');redirect('/admin/offers/new');}

    $no=offer_number();
    db()->beginTransaction();
    try{
        db()->prepare("INSERT INTO offers(offer_no,seller_id,admin_id,title,intro,rules_text,status) VALUES(?,?,?,?,?,?,'draft')")
            ->execute([$no,$sellerId,$a['id'],$tpl['title'],$tpl['intro'],$tpl['rules_text']]);
        $offerId=(int)db()->lastInsertId();

        $ins=db()->prepare('INSERT INTO offer_positions(
            offer_id,position_no,title,description,compensation,required_success_days,align_to_offer_end,sync_start_with_offer,
            precheck_photo_count,precheck_instructions,daily_photo_count,daily_instructions,daily_event_windows_json
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');

        foreach(array_values($tpl['positions']) as $i=>$p){
            $ins->execute([
                $offerId,$i+1,$p['title'],$p['description']??null,$p['compensation']??0,
                $p['required_success_days']??1,$p['align_to_offer_end']??0,$p['sync_start_with_offer']??0,
                $p['precheck_photo_count']??0,$p['precheck_instructions']??'',
                $p['daily_photo_count']??1,$p['daily_instructions']??'',
                $p['daily_event_windows_json']??event_templates_json(default_event_templates((int)($p['daily_photo_count']??1)))
            ]);
        }
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error','Vorlage konnte nicht erstellt werden: '.$e->getMessage());
        redirect('/admin/offers/new');
    }

    log_event($offerId,null,'offer.created_from_template',['template'=>$key]);
    flash('success','Vorlage wurde als Entwurf zugewiesen. Bitte vor dem Versand noch einmal prüfen.');
    redirect('/admin/offer/'.$offerId);
}

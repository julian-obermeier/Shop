<?php
declare(strict_types=1);
// ADMIN OFFER DETAIL
if (preg_match('#^/admin/offer/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare("SELECT o.*,s.first_name,s.last_name,s.email FROM offers o JOIN sellers s ON s.id=o.seller_id WHERE o.id=?");$q->execute([$id]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT * FROM offer_positions WHERE offer_id=? ORDER BY position_no');$q->execute([$id]);$positions=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM orders WHERE offer_id=? ORDER BY id');$q->execute([$id]);$orders=$q->fetchAll();
    $totalComp=array_sum(array_map(static fn(array $p)=>(float)$p['compensation'],$positions));
    ob_start(); ?>
    <div class="page-head"><div><span class="eyebrow">Angebot <?=e($o['offer_no'])?></span><h1><?=e($o['title'])?></h1><p><?=e($o['first_name'].' '.$o['last_name'].' · '.$o['email'])?></p></div><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span></div>

    <?php if($o['status']==='draft'):?>
    <details class="panel editor-panel">
        <summary><strong>Grunddaten bearbeiten</strong><span>Titel, Hinweis und Regeln ändern</span></summary>
        <form method="post" action="<?=e(url('/admin/offer/'.$id.'/update'))?>">
            <label>Titel<input name="title" maxlength="190" value="<?=e($o['title'])?>" required></label>
            <label>Hinweis zum Angebot<textarea name="intro" rows="4"><?=e($o['intro']??'')?></textarea></label>
            <label>Verbindliche Regeln<textarea name="rules_text" rows="10" required><?=e($o['rules_text'])?></textarea></label>
            <button class="btn">Grunddaten speichern</button>
        </form>
    </details>
    <?php endif;?>

    <?php if($o['intro']):?><section class="panel"><h2>Hinweis</h2><p><?=nl2br(e($o['intro']))?></p></section><?php endif;?>
    <section class="panel"><h2>Regeln</h2><p><?=nl2br(e($o['rules_text']))?></p></section>

    <div class="section-head">
        <div><h2>Positionen</h2><span class="muted"><?=count($positions)?> Position(en)</span></div>
        <?php if($positions):?><strong class="offer-total">Gesamt: <?=money($totalComp)?></strong><?php endif;?>
    </div>
    <div class="cards">
    <?php foreach($positions as $p):?>
        <article class="card">
            <div class="card-top"><span class="badge">Position <?=e($p['position_no'])?></span><strong><?=money($p['compensation'])?></strong></div>
            <h3><?=e($p['title'])?></h3>
            <p><?=nl2br(e($p['description']??''))?></p>
            <div class="mini-grid">
                <span><b><?=e($p['required_success_days'])?></b> erfolgreiche Tage</span>
                <span><b><?=e($p['precheck_photo_count'])?></b> Vorabfotos</span>
                <span><b><?=e($p['daily_photo_count'])?></b> Fotos / Tag</span>
            </div>
            <p class="muted"><strong>Vorab:</strong> <?=e($p['precheck_instructions'])?></p>
            <p class="muted"><strong>Täglich:</strong> <?=e($p['daily_instructions'])?></p>
            <?php if($o['status']==='draft'):?>
                <details class="inline-editor">
                    <summary>Position bearbeiten</summary>
                    <form method="post" action="<?=e(url('/admin/offer/'.$id.'/position/'.$p['id'].'/update'))?>">
                        <label>Titel<input name="title" value="<?=e($p['title'])?>" required></label>
                        <div class="form-grid">
                            <label>Vergütung (€)<input type="number" step="0.01" min="0" name="compensation" value="<?=e($p['compensation'])?>" required></label>
                            <label>Erfolgreiche Tage<input type="number" min="1" max="365" name="required_success_days" value="<?=e($p['required_success_days'])?>" required></label>
                            <label>Vorabfotos<input type="number" min="1" max="20" name="precheck_photo_count" value="<?=e($p['precheck_photo_count'])?>" required></label>
                            <label>Fotos je Tag<input type="number" min="1" max="20" name="daily_photo_count" value="<?=e($p['daily_photo_count'])?>" required></label>
                        </div>
                        <label>Beschreibung<textarea name="description" rows="3"><?=e($p['description']??'')?></textarea></label>
                        <label>Anforderung Vorabkontrolle<textarea name="precheck_instructions" rows="3" required><?=e($p['precheck_instructions'])?></textarea></label>
                        <label>Anforderung je Tag<textarea name="daily_instructions" rows="3" required><?=e($p['daily_instructions'])?></textarea></label>
                        <button class="btn">Änderungen speichern</button>
                    </form>
                </details>
                <form method="post" action="<?=e(url('/admin/offer/'.$id.'/position/'.$p['id'].'/delete'))?>"><button class="btn ghost danger">Position löschen</button></form>
            <?php endif;?>
        </article>
    <?php endforeach;?>
    <?php if(!$positions):?><div class="empty">Noch keine Position hinzugefügt.</div><?php endif;?>
    </div>

    <?php if($o['status']==='draft'):?>
    <section class="panel" style="margin-top:20px">
        <h2>Position hinzufügen</h2>
        <form method="post" action="<?=e(url('/admin/offer/'.$id.'/position'))?>">
            <div class="form-grid">
                <label>Titel<input name="title" required></label>
                <label>Vergütung (€)<input type="number" step="0.01" min="0" name="compensation" value="0.00" required></label>
                <label>Erfolgreiche Durchführungstage<input type="number" min="1" max="365" name="required_success_days" value="1" required></label>
                <label>Vorabfotos<input type="number" min="1" max="20" name="precheck_photo_count" value="1" required></label>
                <label>Fotos je Tag<input type="number" min="1" max="20" name="daily_photo_count" value="3" required></label>
            </div>
            <label>Beschreibung<textarea name="description" rows="3"></textarea></label>
            <label>Anforderung Vorabkontrolle<textarea name="precheck_instructions" rows="3" required></textarea></label>
            <label>Anforderung je Tag<textarea name="daily_instructions" rows="3" required></textarea></label>
            <button class="btn">Position hinzufügen</button>
        </form>
    </section>
    <section class="panel action-panel">
        <div><h2>Angebot versenden</h2><p>Nach dem Versenden sind Grunddaten und Positionen festgeschrieben.</p></div>
        <form method="post" action="<?=e(url('/admin/offer/'.$id.'/send'))?>"><button class="btn" <?=!$positions?'disabled':''?>>An Verkäuferin senden</button></form>
    </section>
    <?php endif;?>

    <?php if($orders):?>
    <div class="section-head"><h2>Aufträge aus diesem Angebot</h2></div>
    <div class="list"><?php foreach($orders as $ord):?><a class="list-row" href="<?=e(url('/admin/order/'.$ord['id']))?>"><div><strong><?=e($ord['order_no'].' · '.$ord['title_snapshot'])?></strong><span><?=e($ord['successful_days'])?>/<?=e($ord['required_success_days'])?> erfolgreiche Tage · +<?=e($ord['extension_days'])?> Verlängerung<?php if($ord['started_at']):?> · Start <?=e(date_de(order_day_date($ord['started_at'],1)))?><?php endif;?></span></div><span class="status status-<?=e($ord['status'])?>"><?=e(order_status_label($ord['status']))?></span></a><?php endforeach;?></div>
    <?php endif;?>

    <?php render('Angebot '.$o['offer_no'],ob_get_clean());exit;
}

if (preg_match('#^/admin/offer/(\d+)/update$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare('SELECT status FROM offers WHERE id=?');$q->execute([$id]);
    if($q->fetchColumn()!=='draft'){flash('error','Nur Entwürfe können geändert werden.');redirect('/admin/offer/'.$id);}
    if(!post('title')||!post('rules_text')){flash('error','Titel und Regeln sind erforderlich.');redirect('/admin/offer/'.$id);}
    db()->prepare('UPDATE offers SET title=?,intro=?,rules_text=?,updated_at=NOW() WHERE id=?')->execute([post('title'),post('intro')?:null,post('rules_text'),$id]);
    log_event($id,null,'offer.updated');flash('success','Grunddaten wurden gespeichert.');redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offer/(\d+)/position$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$q=db()->prepare('SELECT status FROM offers WHERE id=?');$q->execute([$id]);if($q->fetchColumn()!=='draft'){flash('error','Positionen können nur im Entwurf geändert werden.');redirect('/admin/offer/'.$id);}
    $days=max(1,min(365,(int)post('required_success_days','1')));$pre=max(1,min(20,(int)post('precheck_photo_count','1')));$daily=max(1,min(20,(int)post('daily_photo_count','1')));
    if(!post('title')||!post('precheck_instructions')||!post('daily_instructions')){flash('error','Titel und Nachweisanforderungen sind Pflicht.');redirect('/admin/offer/'.$id);}
    $q=db()->prepare('SELECT COALESCE(MAX(position_no),0)+1 FROM offer_positions WHERE offer_id=?');$q->execute([$id]);$pos=(int)$q->fetchColumn();
    db()->prepare('INSERT INTO offer_positions(offer_id,position_no,title,description,compensation,required_success_days,precheck_photo_count,precheck_instructions,daily_photo_count,daily_instructions) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$id,$pos,post('title'),post('description')?:null,max(0,(float)post('compensation','0')),$days,$pre,post('precheck_instructions'),$daily,post('daily_instructions')]);
    log_event($id,null,'position.created',['position_no'=>$pos]);flash('success','Position wurde hinzugefügt.');redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offer/(\d+)/position/(\d+)/update$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$pid=(int)$m[2];
    $q=db()->prepare('SELECT status FROM offers WHERE id=?');$q->execute([$id]);if($q->fetchColumn()!=='draft'){flash('error','Nur Entwürfe können geändert werden.');redirect('/admin/offer/'.$id);}
    $days=max(1,min(365,(int)post('required_success_days','1')));$pre=max(1,min(20,(int)post('precheck_photo_count','1')));$daily=max(1,min(20,(int)post('daily_photo_count','1')));
    if(!post('title')||!post('precheck_instructions')||!post('daily_instructions')){flash('error','Titel und Nachweisanforderungen sind Pflicht.');redirect('/admin/offer/'.$id);}
    db()->prepare('UPDATE offer_positions SET title=?,description=?,compensation=?,required_success_days=?,precheck_photo_count=?,precheck_instructions=?,daily_photo_count=?,daily_instructions=? WHERE id=? AND offer_id=?')
        ->execute([post('title'),post('description')?:null,max(0,(float)post('compensation','0')),$days,$pre,post('precheck_instructions'),$daily,post('daily_instructions'),$pid,$id]);
    log_event($id,null,'position.updated',['position_id'=>$pid]);flash('success','Position wurde aktualisiert.');redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offer/(\d+)/position/(\d+)/delete$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$pid=(int)$m[2];$q=db()->prepare('SELECT status FROM offers WHERE id=?');$q->execute([$id]);if($q->fetchColumn()!=='draft'){flash('error','Nur Entwürfe können geändert werden.');redirect('/admin/offer/'.$id);}
    db()->prepare('DELETE FROM offer_positions WHERE id=? AND offer_id=?')->execute([$pid,$id]);$q=db()->prepare('SELECT id FROM offer_positions WHERE offer_id=? ORDER BY position_no,id');$q->execute([$id]);$n=1;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $x)db()->prepare('UPDATE offer_positions SET position_no=? WHERE id=?')->execute([$n++,$x]);
    log_event($id,null,'position.deleted',['position_id'=>$pid]);flash('success','Position gelöscht.');redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offer/(\d+)/send$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$q=db()->prepare('SELECT status FROM offers WHERE id=?');$q->execute([$id]);$status=$q->fetchColumn();$c=db()->prepare('SELECT COUNT(*) FROM offer_positions WHERE offer_id=?');$c->execute([$id]);
    if($status!=='draft'||(int)$c->fetchColumn()<1){flash('error','Das Angebot kann so nicht gesendet werden.');redirect('/admin/offer/'.$id);}
    db()->prepare("UPDATE offers SET status='sent',sent_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);log_event($id,null,'offer.sent');flash('success','Angebot wurde gesendet.');redirect('/admin/offer/'.$id);
}

<?php
declare(strict_types=1);
// ADMIN OFFER DETAIL
if (preg_match('#^/admin/offer/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare("SELECT o.*,s.first_name,s.last_name,s.email FROM offers o JOIN sellers s ON s.id=o.seller_id WHERE o.id=?");$q->execute([$id]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT * FROM offer_positions WHERE offer_id=? ORDER BY position_no');$q->execute([$id]);$positions=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM orders WHERE offer_id=? ORDER BY id');$q->execute([$id]);$orders=$q->fetchAll();
    $editable=in_array($o['status'],['draft','sent'],true);
    $sellers=$editable?db()->query('SELECT id,first_name,last_name,email FROM sellers WHERE active=1 ORDER BY first_name,last_name')->fetchAll():[];
    $totalComp=array_sum(array_map(static fn(array $p)=>(float)$p['compensation'],$positions));
    $maxDays=0;foreach($positions as $x)$maxDays=max($maxDays,(int)$x['required_success_days']);
    ob_start(); ?>
    <div class="page-head"><div><span class="eyebrow">Angebot <?=e($o['offer_no'])?></span><h1><?=e($o['title'])?></h1><p><?=e($o['first_name'].' '.$o['last_name'].' · '.$o['email'])?></p></div><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span></div>

    <?php if($editable):?>
    <section class="notice <?=e($o['status']==='sent'?'warning':'')?>"><strong>Bis zur Annahme vollständig bearbeitbar.</strong><br><?php if($o['status']==='sent'):?>Das Angebot wurde bereits übermittelt. Gespeicherte Änderungen sind für die Verkäuferin sofort sichtbar. Erst mit ihrer Annahme wird der aktuelle Stand endgültig eingefroren.<?php else:?>Titel, Texte, Verkäuferin und Positionen können bis zur Annahme geändert werden.<?php endif;?></section>
    <details class="panel editor-panel">
        <summary><strong>Grunddaten bearbeiten</strong><span>Verkäuferin, Titel, Hinweis und Regeln ändern</span></summary>
        <form method="post" action="<?=e(url('/admin/offer/'.$id.'/update'))?>">
            <label>Verkäuferin<select name="seller_id" required><?php foreach($sellers as $s):?><option value="<?=e($s['id'])?>" <?=(int)$s['id']===(int)$o['seller_id']?'selected':''?>><?=e($s['first_name'].' '.$s['last_name'].' · '.$s['email'])?></option><?php endforeach;?></select></label>
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
    <?php foreach($positions as $p):
        $templates=event_templates($p['daily_event_windows_json']??null,(int)$p['daily_photo_count']);
    ?>
        <article class="card">
            <div class="card-top"><span class="badge">Position <?=e($p['position_no'])?></span><strong><?=money($p['compensation'])?></strong></div>
            <h3><?=e($p['title'])?></h3>
            <p><?=nl2br(e($p['description']??''))?></p>
            <div class="mini-grid">
                <span><b><?=e($p['required_success_days'])?></b> erfolgreiche Tage</span>
                <span><b><?=e($p['precheck_photo_count'])?></b> Vorabfotos</span>
                <span><b><?=e($p['daily_photo_count'])?></b> Vorgänge / Tag</span>
            </div>
            <?php if((int)$p['precheck_photo_count']>0):?>
                <p class="muted"><strong>Vorab:</strong> <?=e($p['precheck_instructions']?:'Vorabkontrolle erforderlich')?></p>
            <?php else:?><p class="muted"><strong>Vorab:</strong> Keine Vorabfotos erforderlich.</p><?php endif;?>
            <?php if(!empty($p['align_to_offer_end'])):?>
                <div class="notice"><strong>Am Angebotsende gekoppelt:</strong> Diese Position läuft auf den letzten <?=e($p['required_success_days'])?> Gesamttag(en) und verschiebt sich bei Verlängerungen der längsten Position automatisch mit.</div>
            <?php endif;?>
            <p class="muted"><strong>Je Vorgang:</strong> <?=e($p['daily_instructions'])?></p>
            <div class="window-list">
                <?php foreach($templates as $t):?><div><strong><?=e($t['label'])?></strong><span><?=e(!empty($t['all_day'])?'Ganztags · 00:00–24:00':$t['start'].'–'.$t['end'].' Uhr')?></span></div><?php endforeach;?>
            </div>

            <?php if($editable):?>
                <details class="inline-editor">
                    <summary>Position bearbeiten</summary>
                    <form method="post" action="<?=e(url('/admin/offer/'.$id.'/position/'.$p['id'].'/update'))?>">
                        <label>Titel<input name="title" value="<?=e($p['title'])?>" required></label>
                        <div class="form-grid">
                            <label>Vergütung (€)<input type="number" step="0.01" min="0" name="compensation" value="<?=e($p['compensation'])?>" required></label>
                            <label>Erfolgreiche Tage<input type="number" min="1" max="365" name="required_success_days" value="<?=e($p['required_success_days'])?>" required></label>
                            <label>Vorabfotos<input type="number" min="0" max="20" name="precheck_photo_count" value="<?=e($p['precheck_photo_count'])?>" required></label>
                            <label>Nachweisvorgänge je Tag<input type="number" min="1" max="20" name="daily_photo_count" value="<?=e($p['daily_photo_count'])?>" required><span class="field-hint">Wenn du die Anzahl änderst, werden beim Speichern passende Standardfenster erzeugt.</span></label>
                        </div>
                        <label class="check"><input type="checkbox" name="align_to_offer_end" value="1" <?=!empty($p['align_to_offer_end'])?'checked':''?>><span>An die letzten Gesamttage koppeln. Bei 1 Tag wird diese Kopplung automatisch aktiviert.</span></label>
                        <label>Beschreibung<textarea name="description" rows="3"><?=e($p['description']??'')?></textarea></label>
                        <label>Anforderung Vorabkontrolle <span class="muted">(optional)</span><textarea name="precheck_instructions" rows="3"><?=e($p['precheck_instructions'])?></textarea><span class="field-hint">Kann leer bleiben – auch wenn Vorabfotos verlangt werden.</span></label>
                        <label>Anforderung je Nachweisvorgang <span class="muted">(optional)</span><textarea name="daily_instructions" rows="3"><?=e($p['daily_instructions'])?></textarea><span class="field-hint">Kann leer bleiben.</span></label>

                        <div class="event-config"><h4>Zeitfenster</h4>
                        <?php foreach($templates as $i=>$t):?>
                            <div class="event-config-row">
                                <label>Bezeichnung<input name="event_label[]" value="<?=e($t['label'])?>" required></label>
                                <label>Von<input type="time" name="event_start[]" value="<?=e($t['start'])?>" required></label>
                                <label>Bis<input type="time" name="event_end[]" value="<?=e($t['end'])?>" required></label>
                                <label class="check event-all-day"><input type="checkbox" name="event_all_day[<?=$i?>]" value="1" <?=!empty($t['all_day'])?'checked':''?>><span>Ganztags</span></label>
                            </div>
                        <?php endforeach;?>
                        </div>

                        <button class="btn">Änderungen speichern</button>
                    </form>
                </details>
                <form method="post" action="<?=e(url('/admin/offer/'.$id.'/position/'.$p['id'].'/delete'))?>"><button class="btn ghost danger">Position löschen</button></form>
            <?php endif;?>
        </article>
    <?php endforeach;?>
    <?php if(!$positions):?><div class="empty">Noch keine Position hinzugefügt.</div><?php endif;?>
    </div>

    <?php if($editable):
        $newTemplates=default_event_templates(3);
    ?>
    <section class="panel" style="margin-top:20px">
        <h2>Position hinzufügen</h2>
        <form method="post" action="<?=e(url('/admin/offer/'.$id.'/position'))?>">
            <div class="form-grid">
                <label>Titel<input name="title" required></label>
                <label>Vergütung (€)<input type="number" step="0.01" min="0" name="compensation" value="0.00" required></label>
                <label>Erfolgreiche Durchführungstage<input type="number" min="1" max="365" name="required_success_days" value="1" required></label>
                <label>Vorabfotos<input type="number" min="0" max="20" name="precheck_photo_count" value="1" required></label>
                <label>Nachweisvorgänge je Tag<input type="number" min="1" max="20" name="daily_photo_count" value="3" required><span class="field-hint">Standard bei 3: Morgens, Mittags, Abends.</span></label>
            </div>
            <label class="check"><input type="checkbox" name="align_to_offer_end" value="1"><span>An die letzten Gesamttage koppeln. Bei 1 Tag wird diese Kopplung automatisch aktiviert.</span></label>
            <label>Beschreibung<textarea name="description" rows="3"></textarea></label>
            <label>Anforderung Vorabkontrolle <span class="muted">(optional)</span><textarea name="precheck_instructions" rows="3"></textarea><span class="field-hint">Kann leer bleiben – unabhängig von der Anzahl der Vorabfotos.</span></label>
            <label>Anforderung je Nachweisvorgang <span class="muted">(optional)</span><textarea name="daily_instructions" rows="3"></textarea><span class="field-hint">Kann leer bleiben.</span></label>

            <div class="event-config"><h4>Zeitfenster für die 3 Standardvorgänge</h4>
            <?php foreach($newTemplates as $i=>$t):?>
                <div class="event-config-row">
                    <label>Bezeichnung<input name="event_label[]" value="<?=e($t['label'])?>" required></label>
                    <label>Von<input type="time" name="event_start[]" value="<?=e($t['start'])?>" required></label>
                    <label>Bis<input type="time" name="event_end[]" value="<?=e($t['end'])?>" required></label>
                    <label class="check event-all-day"><input type="checkbox" name="event_all_day[<?=$i?>]" value="1"><span>Ganztags</span></label>
                </div>
            <?php endforeach;?>
            </div>

            <button class="btn">Position hinzufügen</button>
        </form>
    </section>

    <?php if($o['status']==='draft'):?>
    <section class="panel action-panel">
        <div><h2>Angebot versenden</h2><p>Nach dem Versenden bleibt das Angebot bis zur Annahme weiterhin bearbeitbar.</p></div>
        <div class="head-actions">
            <form method="post" action="<?=e(url('/admin/offer/'.$id.'/delete'))?>">
                <button class="btn ghost danger">Entwurf löschen</button>
            </form>
            <form method="post" action="<?=e(url('/admin/offer/'.$id.'/send'))?>">
                <button class="btn" <?=!$positions?'disabled':''?>>An Verkäuferin senden</button>
            </form>
        </div>
    </section>
    <div class="notice warning"><strong>Entwurf löschen:</strong> Das Angebot und alle dazugehörigen Positionen werden dauerhaft gelöscht. Diese Aktion ist nur möglich, solange das Angebot noch Entwurf ist.</div>
    <?php else:?>
    <section class="panel action-panel">
        <div>
            <h2>Angebot bereits übermittelt</h2>
            <p>Du kannst es weiterhin vollständig bearbeiten. Mit der Annahme durch die Verkäuferin wird der dann aktuelle Stand endgültig gesperrt.</p>
        </div>
        <form method="post" action="<?=e(url('/admin/offer/'.$id.'/withdraw'))?>">
            <button class="btn ghost danger">Angebot zurückziehen</button>
        </form>
    </section>
    <div class="notice warning"><strong>Zurückziehen:</strong> Das Angebot verschwindet sofort bei der Verkäuferin und wird wieder als Entwurf gespeichert. Du kannst es anschließend weiter bearbeiten und später erneut senden.</div>
    <?php endif;?>
    <?php endif;?>

    <?php if($orders):?>
    <?php if(in_array($o['status'],['accepted','active'],true)):?>
        <div class="notice"><strong>Zeitfenster bleiben anpassbar.</strong><br>Auch nach der Annahme kannst du die Nachweis-Zeitfenster weiterhin ändern. Öffne dafür den jeweiligen Auftrag und wähle unter <strong>Admin-Korrekturen → Nachweis-Zeitfenster anpassen</strong>.</div>
    <?php endif;?>
    <div class="section-head"><h2>Aufträge aus diesem Angebot</h2></div>
    <div class="list"><?php foreach($orders as $ord):?><a class="list-row" href="<?=e(url('/admin/order/'.$ord['id']))?>"><div><strong><?=e($ord['order_no'].' · '.$ord['title_snapshot'])?></strong><span><?=e($ord['successful_days'])?>/<?=e($ord['required_success_days'])?> erfolgreiche Tage · +<?=e($ord['extension_days'])?> Verlängerung<?php if($ord['started_at']):?> · Termin <?=e(date_de(scheduled_order_day_date($ord,1)))?><?php endif;?></span></div><span class="status status-<?=e($ord['status'])?>"><?=e(order_status_label($ord['status']))?></span></a><?php endforeach;?></div>
    <?php endif;?>

    <?php render('Angebot '.$o['offer_no'],ob_get_clean());exit;
}

if (preg_match('#^/admin/offer/(\d+)/update$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$sellerId=(int)post('seller_id');
    if(!post('title')||!post('rules_text')||!$sellerId){flash('error','Verkäuferin, Titel und Regeln sind erforderlich.');redirect('/admin/offer/'.$id);}
    $sq=db()->prepare('SELECT COUNT(*) FROM sellers WHERE id=? AND active=1');$sq->execute([$sellerId]);
    if((int)$sq->fetchColumn()!==1){flash('error','Bitte eine aktive Verkäuferin auswählen.');redirect('/admin/offer/'.$id);}

    db()->beginTransaction();
    try{
        $q=db()->prepare('SELECT status,seller_id FROM offers WHERE id=? FOR UPDATE');$q->execute([$id]);$offer=$q->fetch();
        if(!$offer||!in_array($offer['status'],['draft','sent'],true))throw new RuntimeException('Das Angebot wurde bereits angenommen und kann nicht mehr geändert werden.');
        db()->prepare('UPDATE offers SET seller_id=?,title=?,intro=?,rules_text=?,updated_at=NOW() WHERE id=?')
            ->execute([$sellerId,post('title'),post('intro')?:null,post('rules_text'),$id]);
        db()->commit();
        log_event($id,null,'offer.updated',['seller_changed'=>(int)$offer['seller_id']!==$sellerId]);
        if($offer['status']==='sent'){
            notify_seller($sellerId,'offer','Angebot wurde aktualisiert','Ein bereits übermitteltes Angebot wurde von der Plattform geändert. Bitte prüfe vor der Annahme den aktuellen Stand.','/seller/offer/'.$id,'offer-updated:'.$id.':'.time(),'offers');
            if((int)$offer['seller_id']!==$sellerId)notify_seller((int)$offer['seller_id'],'offer','Angebot nicht mehr verfügbar','Das zuvor sichtbare Angebot wurde von der Plattform neu zugeordnet.','/seller/offers','offer-reassigned:'.$id.':'.time(),'offers');
        }
        flash('success','Angebot wurde gespeichert. Die Änderungen gelten bis zur Annahme sofort.');
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());
    }
    redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offer/(\d+)/position$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];
    $days=max(1,min(365,(int)post('required_success_days','1')));
    $pre=max(0,min(20,(int)post('precheck_photo_count','1')));
    $daily=max(1,min(20,(int)post('daily_photo_count','1')));
    $align=$days===1?1:(post('align_to_offer_end')==='1'?1:0);
    if(!post('title')){flash('error','Der Positionstitel ist Pflicht.');redirect('/admin/offer/'.$id);}
    try{$templates=posted_event_templates($daily);}catch(Throwable $e){flash('error',$e->getMessage());redirect('/admin/offer/'.$id);}
    db()->beginTransaction();
    try{
        $lock=db()->prepare('SELECT status FROM offers WHERE id=? FOR UPDATE');$lock->execute([$id]);$status=$lock->fetchColumn();
        if(!in_array($status,['draft','sent'],true))throw new RuntimeException('Das Angebot wurde bereits angenommen und kann nicht mehr geändert werden.');
        $q=db()->prepare('SELECT COALESCE(MAX(position_no),0)+1 FROM offer_positions WHERE offer_id=?');$q->execute([$id]);$pos=(int)$q->fetchColumn();
        db()->prepare('INSERT INTO offer_positions(offer_id,position_no,title,description,compensation,required_success_days,align_to_offer_end,precheck_photo_count,precheck_instructions,daily_photo_count,daily_instructions,daily_event_windows_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$pos,post('title'),post('description')?:null,max(0,(float)post('compensation','0')),$days,$align,$pre,post('precheck_instructions'),$daily,post('daily_instructions'),event_templates_json($templates)]);
        db()->commit();
        log_event($id,null,'position.created',['position_no'=>$pos]);notify_sent_offer_change($id,'Eine Position wurde hinzugefügt.');flash('success','Position wurde hinzugefügt.');
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();flash('error',$e->getMessage());
    }
    redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offer/(\d+)/position/(\d+)/update$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$pid=(int)$m[2];
    $days=max(1,min(365,(int)post('required_success_days','1')));
    $pre=max(0,min(20,(int)post('precheck_photo_count','1')));
    $daily=max(1,min(20,(int)post('daily_photo_count','1')));
    $align=$days===1?1:(post('align_to_offer_end')==='1'?1:0);
    if(!post('title')){flash('error','Der Positionstitel ist Pflicht.');redirect('/admin/offer/'.$id);}
    try{$templates=posted_event_templates($daily);}catch(Throwable $e){flash('error',$e->getMessage());redirect('/admin/offer/'.$id);}
    db()->beginTransaction();
    try{
        $lock=db()->prepare('SELECT status FROM offers WHERE id=? FOR UPDATE');$lock->execute([$id]);$status=$lock->fetchColumn();
        if(!in_array($status,['draft','sent'],true))throw new RuntimeException('Das Angebot wurde bereits angenommen und kann nicht mehr geändert werden.');
        db()->prepare('UPDATE offer_positions SET title=?,description=?,compensation=?,required_success_days=?,align_to_offer_end=?,precheck_photo_count=?,precheck_instructions=?,daily_photo_count=?,daily_instructions=?,daily_event_windows_json=? WHERE id=? AND offer_id=?')
            ->execute([post('title'),post('description')?:null,max(0,(float)post('compensation','0')),$days,$align,$pre,post('precheck_instructions'),$daily,post('daily_instructions'),event_templates_json($templates),$pid,$id]);
        db()->commit();
        log_event($id,null,'position.updated',['position_id'=>$pid]);notify_sent_offer_change($id,'Eine Position wurde geändert.');flash('success','Position wurde aktualisiert.');
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();flash('error',$e->getMessage());
    }
    redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offer/(\d+)/position/(\d+)/delete$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];$pid=(int)$m[2];
    db()->beginTransaction();
    try{
        $lock=db()->prepare('SELECT status FROM offers WHERE id=? FOR UPDATE');$lock->execute([$id]);$status=$lock->fetchColumn();
        if(!in_array($status,['draft','sent'],true))throw new RuntimeException('Das Angebot wurde bereits angenommen und kann nicht mehr geändert werden.');
        db()->prepare('DELETE FROM offer_positions WHERE id=? AND offer_id=?')->execute([$pid,$id]);
        $q=db()->prepare('SELECT id FROM offer_positions WHERE offer_id=? ORDER BY position_no,id');$q->execute([$id]);$n=1;
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $x)db()->prepare('UPDATE offer_positions SET position_no=? WHERE id=?')->execute([$n++,$x]);
        db()->commit();
        log_event($id,null,'position.deleted',['position_id'=>$pid]);notify_sent_offer_change($id,'Eine Position wurde entfernt.');flash('success','Position gelöscht.');
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();flash('error',$e->getMessage());
    }
    redirect('/admin/offer/'.$id);
}





if (preg_match('#^/admin/offer/(\d+)/delete$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];

    db()->beginTransaction();
    try{
        $q=db()->prepare('SELECT id,offer_no,title,status FROM offers WHERE id=? FOR UPDATE');
        $q->execute([$id]);$offer=$q->fetch();
        if(!$offer)throw new RuntimeException('Angebot wurde nicht gefunden.');
        if($offer['status']!=='draft')throw new RuntimeException('Nur Entwürfe können gelöscht werden.');

        $q=db()->prepare('SELECT COUNT(*) FROM offer_acceptances WHERE offer_id=?');
        $q->execute([$id]);$acceptances=(int)$q->fetchColumn();
        $q=db()->prepare('SELECT COUNT(*) FROM orders WHERE offer_id=?');
        $q->execute([$id]);$orders=(int)$q->fetchColumn();
        if($acceptances>0||$orders>0)throw new RuntimeException('Dieser Entwurf besitzt bereits verknüpfte Auftragsdaten und kann nicht gelöscht werden.');

        $q=db()->prepare('SELECT COUNT(*) FROM offer_positions WHERE offer_id=?');
        $q->execute([$id]);$positions=(int)$q->fetchColumn();

        log_event($id,null,'offer.deleted',[
            'offer_no'=>$offer['offer_no'],
            'title'=>$offer['title'],
            'positions'=>$positions
        ]);

        db()->prepare('DELETE FROM offers WHERE id=? AND status=\'draft\'')->execute([$id]);
        db()->commit();

        flash('success','Entwurf '.$offer['offer_no'].' wurde dauerhaft gelöscht.');
        redirect('/admin/offers');
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());
        redirect('/admin/offer/'.$id);
    }
}

if (preg_match('#^/admin/offer/(\d+)/withdraw$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];

    db()->beginTransaction();
    try{
        $q=db()->prepare('SELECT status,seller_id,title FROM offers WHERE id=? FOR UPDATE');
        $q->execute([$id]);$offer=$q->fetch();$status=$offer['status']??null;

        if($status!=='sent'){
            throw new RuntimeException(
                $status==='accepted'||$status==='active'||$status==='completed'
                    ? 'Das Angebot wurde bereits angenommen und kann nicht mehr zurückgezogen werden.'
                    : 'Dieses Angebot ist aktuell nicht versendet.'
            );
        }

        db()->prepare("UPDATE offers SET status='draft',sent_at=NULL,updated_at=NOW() WHERE id=?")
            ->execute([$id]);

        db()->commit();
        log_event($id,null,'offer.withdrawn');
        notify_seller((int)$offer['seller_id'],'offer','Angebot wurde zurückgezogen','Das Angebot „'.$offer['title'].'“ wurde von der Plattform zurückgezogen.','/seller/offers','offer-withdrawn:'.$id.':'.time(),'offers');
        flash('success','Angebot wurde zurückgezogen. Es ist für die Verkäuferin nicht mehr sichtbar und liegt wieder als Entwurf vor.');
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());
    }

    redirect('/admin/offer/'.$id);
}

if (preg_match('#^/admin/offer/(\d+)/send$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare('SELECT status,seller_id,title FROM offers WHERE id=?');$q->execute([$id]);$offer=$q->fetch();$status=$offer['status']??null;
    $c=db()->prepare('SELECT COUNT(*) FROM offer_positions WHERE offer_id=?');$c->execute([$id]);
    if($status!=='draft'||(int)$c->fetchColumn()<1){flash('error','Das Angebot kann so nicht gesendet werden.');redirect('/admin/offer/'.$id);}
    db()->prepare("UPDATE offers SET status='sent',sent_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);
    log_event($id,null,'offer.sent');
    notify_seller((int)$offer['seller_id'],'offer','Neues Angebot verfügbar','Die Plattform hat dir das Angebot „'.$offer['title'].'“ übermittelt.','/seller/offer/'.$id,'offer-sent:'.$id.':'.time(),'offers');
    flash('success','Angebot wurde gesendet.');redirect('/admin/offer/'.$id);
}

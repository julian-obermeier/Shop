<?php
declare(strict_types=1);

// Seller archive: all published update notes remain available here.
if($path==='/seller/whats-new' && $method==='GET'){
    $s=require_seller();
    $q=db()->prepare("SELECT u.*,st.seen_at,st.dismissed_at
        FROM platform_updates u
        LEFT JOIN seller_update_states st ON st.update_id=u.id AND st.seller_id=?
        WHERE u.active=1 AND u.published_at<=NOW()
        ORDER BY u.published_at DESC,u.id DESC");
    $q->execute([$s['id']]);$updates=$q->fetchAll();

    $mark=db()->prepare("INSERT INTO seller_update_states(seller_id,update_id,seen_at)
        VALUES(?,?,NOW())
        ON DUPLICATE KEY UPDATE seen_at=COALESCE(seen_at,NOW())");
    foreach($updates as $update){
        if(empty($update['seen_at']))$mark->execute([$s['id'],$update['id']]);
    }

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Portal-Updates</span><h1>Was ist neu?</h1><p>Neue Funktionen und Änderungen der Vermittlungsplattform gesammelt an einem Ort.</p></div>
        <a class="btn ghost" href="<?=e(url('/seller'))?>">Zur Übersicht</a>
    </div>

    <div class="update-archive">
    <?php foreach($updates as $update):?>
        <article class="panel update-archive-card" id="update-<?=e($update['id'])?>">
            <div class="update-card-head">
                <div class="update-card-icon">✦</div>
                <div>
                    <div class="update-meta"><?=e(date('d.m.Y',strtotime($update['published_at'])))?><?php if(empty($update['seen_at'])):?> · <span>Neu</span><?php endif;?></div>
                    <h2><?=e($update['title'])?></h2>
                    <?php if($update['summary']):?><p class="update-summary"><?=e($update['summary'])?></p><?php endif;?>
                </div>
            </div>
            <div class="update-body"><?=nl2br(e($update['body']))?></div>
        </article>
    <?php endforeach;?>
    <?php if(!$updates):?><div class="empty">Aktuell gibt es noch keine veröffentlichten Update-Hinweise.</div><?php endif;?>
    </div>
    <?php render('Was ist neu?',ob_get_clean());exit;
}

// Close the dashboard card without deleting the update from the archive.
if(preg_match('#^/seller/update/(\d+)/dismiss$#',$path,$m) && $method==='POST'){
    $s=require_seller();$id=(int)$m[1];
    if(is_seller_impersonation()){flash('error','In der Verkäuferinnen-Vorschau werden Update-Hinweise nicht geschlossen.');redirect('/seller');}
    $q=db()->prepare("SELECT id FROM platform_updates WHERE id=? AND active=1 AND published_at<=NOW()");
    $q->execute([$id]);if(!$q->fetchColumn())not_found();
    seller_mark_update_seen((int)$s['id'],$id,true);
    redirect('/seller');
}

// Opening a dashboard update counts as seen and removes the one-time dashboard card.
if(preg_match('#^/seller/update/(\d+)/open$#',$path,$m) && $method==='POST'){
    $s=require_seller();$id=(int)$m[1];
    if(!is_seller_impersonation())seller_mark_update_seen((int)$s['id'],$id,true);
    redirect('/seller/whats-new#update-'.$id);
}

// Admin update management.
if($path==='/admin/updates' && $method==='GET'){
    require_admin();
    $rows=db()->query("SELECT u.*,
        (SELECT COUNT(*) FROM seller_update_states s WHERE s.update_id=u.id AND s.seen_at IS NOT NULL) seen_count,
        (SELECT COUNT(*) FROM seller_update_states s WHERE s.update_id=u.id AND s.dismissed_at IS NOT NULL) dismissed_count
        FROM platform_updates u ORDER BY u.published_at DESC,u.id DESC")->fetchAll();

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Kommunikation</span><h1>Was ist neu?</h1><p>Veröffentliche kompakte Update-Hinweise für Verkäuferinnen. Deaktivierte Hinweise verschwinden aus deren Archiv, bleiben intern aber erhalten.</p></div>
    </div>

    <div class="grid two">
        <section class="panel update-editor">
            <div class="section-head"><h2>Neuen Hinweis veröffentlichen</h2><span class="badge">sofort sichtbar</span></div>
            <form method="post" action="<?=e(url('/admin/updates'))?>">
                <label>Titel<input name="title" maxlength="190" required placeholder="z. B. Neue Aufgabenübersicht"></label>
                <label>Kurzbeschreibung<input name="summary" maxlength="500" placeholder="Ein Satz für die Startseiten-Karte"></label>
                <label>Details<textarea name="body" rows="9" maxlength="6000" required placeholder="Was ist neu oder hat sich geändert?"></textarea></label>
                <button class="btn">Update veröffentlichen</button>
            </form>
        </section>

        <section class="panel">
            <h2>So erscheint der Hinweis</h2>
            <div class="update-preview">
                <span class="update-preview-icon">✦</span>
                <div><strong>Einmalig auf der Startseite</strong><p>Der neueste nicht geschlossene Hinweis erscheint unter der aktuellen Aufgabe.</p></div>
            </div>
            <div class="update-preview">
                <span class="update-preview-icon">✓</span>
                <div><strong>Archiv bleibt erhalten</strong><p>Geschlossene Hinweise sind weiter unter „Was ist neu?“ nachlesbar.</p></div>
            </div>
            <div class="update-preview">
                <span class="update-preview-icon">•</span>
                <div><strong>Keine Admin-Identität</strong><p>Verkäuferinnen sehen ausschließlich den Inhalt der Plattform-Mitteilung.</p></div>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="section-head"><h2>Veröffentlichte Hinweise</h2><span class="muted"><?=count($rows)?> insgesamt</span></div>
        <div class="list">
        <?php foreach($rows as $row):?>
            <div class="list-row static update-admin-row">
                <div>
                    <strong><?=e($row['title'])?></strong>
                    <span><?=e(date('d.m.Y H:i',strtotime($row['published_at'])))?> Uhr · <?=e($row['seen_count'])?> gesehen · <?=e($row['dismissed_count'])?> geschlossen</span>
                    <?php if($row['summary']):?><small><?=e($row['summary'])?></small><?php endif;?>
                </div>
                <div class="update-admin-actions">
                    <span class="status <?=$row['active']?'status-fulfilled':'status-planned'?>"><?=$row['active']?'Aktiv':'Deaktiviert'?></span>
                    <form method="post" action="<?=e(url('/admin/update/'.$row['id'].'/toggle'))?>">
                        <button class="btn ghost"><?=$row['active']?'Deaktivieren':'Aktivieren'?></button>
                    </form>
                </div>
            </div>
        <?php endforeach;?>
        <?php if(!$rows):?><div class="empty">Noch keine Update-Hinweise veröffentlicht.</div><?php endif;?>
        </div>
    </section>
    <?php render('Was ist neu?',ob_get_clean());exit;
}

if($path==='/admin/updates' && $method==='POST'){
    $a=require_admin();
    $title=trim(post('title'));$summary=trim(post('summary'));$body=trim(post('body'));
    if($title===''||mb_strlen($title)>190){flash('error','Bitte einen gültigen Titel angeben.');redirect('/admin/updates');}
    if(mb_strlen($summary)>500){flash('error','Die Kurzbeschreibung darf maximal 500 Zeichen lang sein.');redirect('/admin/updates');}
    if($body===''||mb_strlen($body)>6000){flash('error','Bitte Details mit maximal 6000 Zeichen angeben.');redirect('/admin/updates');}

    db()->prepare("INSERT INTO platform_updates(title,summary,body,active,published_at,created_by_admin_id)
        VALUES(?,?,?,1,NOW(),?)")->execute([$title,$summary?:null,$body,$a['id']]);
    $id=(int)db()->lastInsertId();
    log_event(null,null,'platform_update.published',['update_id'=>$id,'title'=>$title]);
    flash('success','Update-Hinweis wurde veröffentlicht.');
    redirect('/admin/updates');
}

if(preg_match('#^/admin/update/(\d+)/toggle$#',$path,$m) && $method==='POST'){
    require_admin();$id=(int)$m[1];
    $q=db()->prepare('SELECT id,active,title FROM platform_updates WHERE id=?');$q->execute([$id]);$update=$q->fetch();if(!$update)not_found();
    $active=(int)$update['active']===1?0:1;
    db()->prepare('UPDATE platform_updates SET active=?,updated_at=NOW() WHERE id=?')->execute([$active,$id]);
    log_event(null,null,'platform_update.toggled',['update_id'=>$id,'active'=>(bool)$active]);
    flash('success',$active?'Update-Hinweis wurde wieder aktiviert.':'Update-Hinweis wurde deaktiviert.');
    redirect('/admin/updates');
}

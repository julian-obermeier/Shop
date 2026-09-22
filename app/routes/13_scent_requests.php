<?php
declare(strict_types=1);

// ADMIN SCENT REQUESTS
if ($path==='/admin/scent-requests' && $method==='GET') {
    require_admin();
    $sellers=db()->query("SELECT id,first_name,last_name,email FROM sellers WHERE active=1 ORDER BY first_name,last_name")->fetchAll();
    $rows=db()->query("SELECT r.*,s.first_name,s.last_name,s.email
        FROM scent_requests r
        JOIN sellers s ON s.id=r.seller_id
        ORDER BY FIELD(r.status,'pending','answered','cancelled'),r.requested_at DESC
        LIMIT 100")->fetchAll();

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Duftproben</span><h1>Duftbewertung anfragen</h1><p>Unabhängig von Angeboten kann jederzeit eine Bewertung von 1 bis 10 angefordert werden.</p></div>
    </div>

    <section class="panel">
        <h2>Neue Anfrage</h2>
        <form method="post" action="<?=e(url('/admin/scent-requests'))?>">
            <div class="form-grid">
                <label>Verkäuferin
                    <select name="seller_id" required>
                        <option value="">Bitte auswählen</option>
                        <?php foreach($sellers as $s):?><option value="<?=e($s['id'])?>"><?=e($s['first_name'].' '.$s['last_name'].' · '.$s['email'])?></option><?php endforeach;?>
                    </select>
                </label>
                <label>Gegenstand / Probe
                    <input name="subject" maxlength="190" required placeholder="z. B. Socken, Schuhe, Nylonstrumpfhose">
                </label>
            </div>
            <label>Hinweis / genaue Frage <span class="muted">(optional)</span>
                <textarea name="instructions" rows="4" placeholder="z. B. Bitte bewerte die aktuelle Duftintensität. 1 = kaum wahrnehmbar, 10 = sehr intensiv."></textarea>
            </label>
            <button class="btn">Duftprobe anfragen</button>
        </form>
    </section>

    <section class="panel">
        <div class="section-head"><h2>Anfragen</h2><span class="muted">Letzte 100</span></div>
        <div class="list">
        <?php foreach($rows as $r):?>
            <div class="list-row static scent-request-row">
                <div>
                    <strong><?=e($r['subject'])?> · <?=e($r['first_name'].' '.$r['last_name'])?></strong>
                    <span>Angefragt <?=e(date('d.m.Y H:i',strtotime($r['requested_at'])))?> Uhr</span>
                    <?php if($r['instructions']):?><span><?=e($r['instructions'])?></span><?php endif;?>
                    <?php if($r['status']==='answered'):?>
                        <div class="scent-result"><strong><?=e($r['rating'])?> / 10</strong><?php if($r['seller_note']):?><span><?=e($r['seller_note'])?></span><?php endif;?></div>
                    <?php elseif($r['status']==='cancelled'):?>
                        <span>Widerrufen <?=e($r['cancelled_at']?date('d.m.Y H:i',strtotime($r['cancelled_at'])).' Uhr':'')?></span>
                    <?php endif;?>
                </div>
                <div class="scent-row-actions">
                    <?php if($r['status']==='pending'):?>
                        <span class="status status-submitted">Offen</span>
                        <form method="post" action="<?=e(url('/admin/scent-request/'.$r['id'].'/cancel'))?>">
                            <button class="btn ghost danger">Widerrufen</button>
                        </form>
                    <?php elseif($r['status']==='answered'):?>
                        <span class="status status-fulfilled">Beantwortet</span>
                    <?php else:?>
                        <span class="status status-not_fulfilled">Widerrufen</span>
                    <?php endif;?>
                </div>
            </div>
        <?php endforeach;?>
        <?php if(!$rows):?><div class="empty">Noch keine Duftproben angefragt.</div><?php endif;?>
        </div>
    </section>
    <?php render('Duftproben',ob_get_clean());exit;
}

if ($path==='/admin/scent-requests' && $method==='POST') {
    $a=require_admin();$sellerId=(int)post('seller_id');$subject=post('subject');$instructions=post('instructions');
    $q=db()->prepare("SELECT COUNT(*) FROM sellers WHERE id=? AND active=1");$q->execute([$sellerId]);
    if(!$sellerId||(int)$q->fetchColumn()!==1){flash('error','Bitte eine aktive Verkäuferin auswählen.');redirect('/admin/scent-requests');}
    if($subject===''){flash('error','Bitte angeben, wovon die Duftprobe bewertet werden soll.');redirect('/admin/scent-requests');}

    db()->prepare("INSERT INTO scent_requests(seller_id,admin_id,subject,instructions,status) VALUES(?,?,?,?,'pending')")
        ->execute([$sellerId,$a['id'],$subject,$instructions?:null]);
    $requestId=(int)db()->lastInsertId();
    log_event(null,null,'scent.requested',['scent_request_id'=>$requestId,'seller_id'=>$sellerId,'subject'=>$subject]);
    flash('success','Duftprobe wurde angefragt. Die Verkäuferin muss sie mit 1 bis 10 beantworten.');
    redirect('/admin/scent-requests');
}

if (preg_match('#^/admin/scent-request/(\d+)/cancel$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare("UPDATE scent_requests SET status='cancelled',cancelled_at=NOW(),updated_at=NOW()
        WHERE id=? AND status='pending'");
    $q->execute([$id]);
    if(!$q->rowCount()){flash('error','Diese Duftprobe ist nicht mehr offen.');redirect('/admin/scent-requests');}
    log_event(null,null,'scent.cancelled',['scent_request_id'=>$id]);
    flash('success','Duftprobe wurde widerrufen.');
    redirect('/admin/scent-requests');
}

// SELLER SCENT REQUESTS
if ($path==='/seller/scent-requests' && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT * FROM scent_requests WHERE seller_id=?
        ORDER BY FIELD(status,'pending','answered','cancelled'),requested_at DESC");
    $q->execute([$s['id']]);$rows=$q->fetchAll();

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Duftproben</span><h1>Meine Duftbewertungen</h1><p>Offene Anfragen müssen mit einem Wert von 1 bis 10 beantwortet werden.</p></div>
    </div>

    <?php foreach($rows as $r):?>
        <section class="panel scent-request-card">
            <div class="section-head">
                <div><h2><?=e($r['subject'])?></h2><span class="muted">Angefragt <?=e(date('d.m.Y H:i',strtotime($r['requested_at'])))?> Uhr</span></div>
                <span class="status status-<?=e($r['status']==='answered'?'fulfilled':($r['status']==='cancelled'?'not_fulfilled':'submitted'))?>"><?=e($r['status']==='answered'?'Beantwortet':($r['status']==='cancelled'?'Widerrufen':'Offen'))?></span>
            </div>
            <?php if($r['instructions']):?><div class="notice"><?=nl2br(e($r['instructions']))?></div><?php endif;?>

            <?php if($r['status']==='pending'):?>
                <form method="post" action="<?=e(url('/seller/scent-request/'.$r['id'].'/answer'))?>">
                    <fieldset class="rating-fieldset">
                        <legend>Duftbewertung <strong>1 bis 10</strong></legend>
                        <div class="rating-scale">
                            <?php for($n=1;$n<=10;$n++):?>
                                <label class="rating-choice"><input type="radio" name="rating" value="<?=$n?>" required><span><?=$n?></span></label>
                            <?php endfor;?>
                        </div>
                        <div class="rating-ends"><span>1 · sehr gering</span><span>10 · sehr stark</span></div>
                    </fieldset>
                    <label>Kommentar <span class="muted">(optional)</span><textarea name="seller_note" rows="3" maxlength="1000" placeholder="Optionaler Hinweis zur Bewertung"></textarea></label>
                    <button class="btn">Bewertung verbindlich absenden</button>
                </form>
            <?php elseif($r['status']==='answered'):?>
                <div class="scent-answer"><span>Deine Bewertung</span><strong><?=e($r['rating'])?> / 10</strong></div>
                <?php if($r['seller_note']):?><p><?=nl2br(e($r['seller_note']))?></p><?php endif;?>
                <p class="muted">Beantwortet <?=e(date('d.m.Y H:i',strtotime($r['answered_at'])))?> Uhr</p>
            <?php else:?>
                <div class="notice warning">Diese Anfrage wurde von der Plattform zurückgezogen und muss nicht mehr beantwortet werden.</div>
            <?php endif;?>
        </section>
    <?php endforeach;?>
    <?php if(!$rows):?><div class="empty">Aktuell liegen keine Duftproben vor.</div><?php endif;?>

    <?php render('Duftproben',ob_get_clean());exit;
}

if (preg_match('#^/seller/scent-request/(\d+)/answer$#',$path,$m) && $method==='POST') {
    $s=require_seller();$id=(int)$m[1];$rating=(int)post('rating');$note=post('seller_note');
    if($rating<1||$rating>10){flash('error','Bitte eine Bewertung von 1 bis 10 auswählen.');redirect('/seller/scent-requests');}

    db()->beginTransaction();
    try{
        $q=db()->prepare("SELECT * FROM scent_requests WHERE id=? AND seller_id=? FOR UPDATE");
        $q->execute([$id,$s['id']]);$r=$q->fetch();
        if(!$r)throw new RuntimeException('Duftprobe wurde nicht gefunden.');
        if($r['status']!=='pending')throw new RuntimeException('Diese Duftprobe ist nicht mehr offen.');

        db()->prepare("UPDATE scent_requests SET status='answered',rating=?,seller_note=?,answered_at=NOW(),updated_at=NOW()
            WHERE id=?")->execute([$rating,$note?:null,$id]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());redirect('/seller/scent-requests');
    }

    log_event(null,null,'scent.answered',['scent_request_id'=>$id,'rating'=>$rating]);
    flash('success','Duftbewertung wurde mit '.$rating.' von 10 übermittelt.');
    redirect('/seller/scent-requests');
}

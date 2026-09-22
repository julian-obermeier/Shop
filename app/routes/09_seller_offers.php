<?php
declare(strict_types=1);
// SELLER OFFER ACCEPT
if (preg_match('#^/seller/offer/(\d+)$#',$path,$m) && $method==='GET') {
    $s=require_seller();$id=(int)$m[1];
    $q=db()->prepare("SELECT * FROM offers WHERE id=? AND seller_id=? AND status<>'draft'");$q->execute([$id,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT * FROM offer_positions WHERE offer_id=? ORDER BY position_no');$q->execute([$id]);$positions=$q->fetchAll();
    $q=db()->prepare('SELECT * FROM orders WHERE offer_id=? AND seller_id=? ORDER BY id');$q->execute([$id,$s['id']]);$orders=$q->fetchAll();
    $maxDays=0;$totalComp=0.0;foreach($positions as $x){$maxDays=max($maxDays,(int)$x['required_success_days']);$totalComp+=(float)$x['compensation'];}

    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Angebot <?=e($o['offer_no'])?></span><h1><?=e($o['title'])?></h1><p>Gesamtvergütung: <strong><?=money($totalComp)?></strong></p></div><span class="status status-<?=e($o['status'])?>"><?=e(offer_status_label($o['status']))?></span></div>
    <?php if($o['intro']):?><section class="panel"><p><?=nl2br(e($o['intro']))?></p></section><?php endif;?>
    <section class="panel"><h2>Regeln</h2><p><?=nl2br(e($o['rules_text']))?></p></section>

    <div class="section-head"><h2>Positionen</h2></div>
    <div class="cards">
    <?php foreach($positions as $p):
        $templates=event_templates($p['daily_event_windows_json']??null,(int)$p['daily_photo_count']);
    ?>
        <article class="card">
            <span class="badge">Position <?=e($p['position_no'])?></span>
            <h3><?=e($p['title'])?></h3>
            <p><?=nl2br(e($p['description']??''))?></p>
            <strong class="price"><?=money($p['compensation'])?></strong>
            <div class="mini-grid">
                <span><b><?=e($p['required_success_days'])?></b> erfolgreiche Tage</span>
                <span><b><?=e($p['precheck_photo_count'])?></b> Vorabfotos</span>
                <span><b><?=e($p['daily_photo_count'])?></b> Vorgänge / Tag</span>
            </div>
            <?php if((int)$p['precheck_photo_count']>0):?>
                <p class="muted"><strong>Vorab:</strong> <?=e($p['precheck_instructions']?:'Vorabfotos gemäß Vorgabe')?></p>
            <?php else:?><p class="muted"><strong>Vorab:</strong> Keine Vorabfotos erforderlich.</p><?php endif;?>
            <?php if(!empty($p['align_to_offer_end'])):?>
                <div class="notice"><strong>Ans Angebotsende gekoppelt:</strong> Diese Position läuft auf den letzten <?=e($p['required_success_days'])?> Gesamttag(en) und verschiebt sich mit, wenn sich das Gesamtende nach hinten verschiebt.</div>
            <?php endif;?>
            <p class="muted"><strong>Je Vorgang:</strong> <?=e($p['daily_instructions'])?></p>
            <div class="window-list">
                <?php foreach($templates as $t):?>
                    <div><strong><?=e($t['label'])?></strong><span><?=e(!empty($t['all_day'])?'Ganztags · 00:00–24:00':$t['start'].'–'.$t['end'].' Uhr')?></span></div>
                <?php endforeach;?>
            </div>
        </article>
    <?php endforeach;?>
    </div>

    <?php if($o['status']==='sent'):?>
        <section class="panel action-panel">
            <div><h2>Verbindlich annehmen</h2><p>Danach entsteht aus jeder Position ein eigener Auftrag. Die jeweilige Vergütung wird sofort in deiner Wallet vorgemerkt.</p></div>
            <form method="post" action="<?=e(url('/seller/offer/'.$id.'/accept'))?>">
                <label class="check"><input type="checkbox" name="accept_rules" value="1" required><span>Ich habe alle Regeln und Anforderungen gelesen und akzeptiere sie. Mir ist bekannt: Jeder nicht erfüllte Durchführungstag verlängert den jeweiligen Auftrag automatisch um einen Tag.</span></label>
                <button class="btn full">Angebot annehmen</button>
            </form>
        </section>
    <?php endif;?>

    <?php if($orders):?>
        <div class="section-head"><h2>Entstandene Aufträge</h2></div>
        <div class="list"><?php foreach($orders as $ord):?><a class="list-row" href="<?=e(url('/seller/order/'.$ord['id']))?>"><div><strong><?=e($ord['order_no'].' · '.$ord['title_snapshot'])?></strong><span><?=e($ord['successful_days'])?>/<?=e($ord['required_success_days'])?> erfolgreich</span></div><span class="status status-<?=e($ord['status'])?>"><?=e(order_status_label($ord['status']))?></span></a><?php endforeach;?></div>
    <?php endif;?>

    <?php render('Angebot '.$o['offer_no'],ob_get_clean());exit;
}

if (preg_match('#^/seller/offer/(\d+)/accept$#',$path,$m) && $method==='POST') {
    $s=require_seller();$id=(int)$m[1];
    if(post('accept_rules')!=='1'){flash('error','Die Regeln müssen akzeptiert werden.');redirect('/seller/offer/'.$id);}

    db()->beginTransaction();
    try{
        $q=db()->prepare('SELECT * FROM offers WHERE id=? AND seller_id=? FOR UPDATE');
        $q->execute([$id,$s['id']]);$o=$q->fetch();
        if(!$o||$o['status']!=='sent')throw new RuntimeException('Dieses Angebot kann nicht mehr angenommen werden.');

        $q=db()->prepare('SELECT * FROM offer_positions WHERE offer_id=? ORDER BY position_no');
        $q->execute([$id]);$positions=$q->fetchAll();
        if(!$positions)throw new RuntimeException('Angebot enthält keine Positionen.');

        $maxDays=0;foreach($positions as $p)$maxDays=max($maxDays,(int)$p['required_success_days']);
        $snap=json_encode($positions,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        db()->prepare('INSERT INTO offer_acceptances(offer_id,seller_id,rules_snapshot,positions_snapshot) VALUES(?,?,?,?)')
            ->execute([$id,$s['id'],$o['rules_text'],$snap]);
        db()->prepare("UPDATE offers SET status='accepted',accepted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);

        $ins=db()->prepare("INSERT INTO orders(
            order_no,offer_id,position_id,seller_id,title_snapshot,description_snapshot,compensation,
            required_success_days,precheck_photo_count,precheck_instructions,daily_photo_count,daily_instructions,
            daily_event_windows_json,is_final_day_position,align_to_offer_end,sync_start_with_offer,status
        ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'precheck')");

        foreach($positions as $p){
            $windows=$p['daily_event_windows_json']??event_templates_json(default_event_templates((int)$p['daily_photo_count']));
            $ins->execute([
                $o['offer_no'].'-'.str_pad((string)$p['position_no'],2,'0',STR_PAD_LEFT),
                $id,$p['id'],$s['id'],$p['title'],$p['description'],$p['compensation'],
                $p['required_success_days'],$p['precheck_photo_count'],$p['precheck_instructions'],
                $p['daily_photo_count'],$p['daily_instructions'],$windows,
                (!empty($p['align_to_offer_end']) && (int)$p['required_success_days']===1)?1:0,
                !empty($p['align_to_offer_end'])?1:0,
                !empty($p['sync_start_with_offer'])?1:0
            ]);
            $orderId=(int)db()->lastInsertId();
            create_wallet_entry((int)$s['id'],$orderId,(float)$p['compensation']);
        }

        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());
        redirect('/seller/offer/'.$id);
    }

    log_event($id,null,'offer.accepted');
    notify_admins('offer','Angebot angenommen',$o['offer_no'].' · '.$o['title'].' wurde von der Verkäuferin angenommen.','/admin/offer/'.$id,'offer-accepted:'.$id);
    flash('success','Angebot angenommen. Die Vergütung wurde vorgemerkt. Gekoppelte Zusatzpositionen werden automatisch mit dem Ende der längsten Position synchronisiert.');
    redirect('/seller/offer/'.$id);
}

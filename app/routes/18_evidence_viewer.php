<?php
declare(strict_types=1);

if (preg_match('#^/admin/evidence/(day|precheck)/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();$kind=$m[1];$uploadId=(int)$m[2];
    if($kind==='day'){
        $q=db()->prepare("SELECT u.*,e.label,e.event_no,d.id day_id,d.day_no,d.status day_status,d.order_id,
            o.offer_id,o.order_no,o.title_snapshot,o.status order_status,o.started_at,o.required_success_days,o.align_to_offer_end,
            s.first_name,s.last_name
            FROM day_uploads u
            JOIN order_days d ON d.id=u.day_id
            JOIN orders o ON o.id=d.order_id
            JOIN sellers s ON s.id=o.seller_id
            LEFT JOIN order_day_events e ON e.id=u.event_id
            WHERE u.id=?");
        $q->execute([$uploadId]);$u=$q->fetch();if(!$u)not_found();
        $q=db()->prepare("SELECT u.id FROM day_uploads u LEFT JOIN order_day_events e ON e.id=u.event_id WHERE u.day_id=? ORDER BY COALESCE(e.event_no,999),u.id");
        $q->execute([$u['day_id']]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
        $pos=array_search($uploadId,$ids,true);$prev=$pos!==false&&$pos>0?$ids[$pos-1]:null;$next=$pos!==false&&$pos<count($ids)-1?$ids[$pos+1]:null;
        $orderCtx=['started_at'=>$u['started_at'],'offer_id'=>$u['offer_id'],'required_success_days'=>$u['required_success_days'],'align_to_offer_end'=>$u['align_to_offer_end']];
        $scheduled=scheduled_order_day_date($orderCtx,(int)$u['day_no']);
        ob_start();?>
        <div class="page-head">
            <div><span class="eyebrow"><?=e($u['order_no'])?> · Tag <?=e($u['day_no'])?></span><h1><?=e($u['label']?:'Nachweis')?></h1><p><?=e($u['first_name'].' '.$u['last_name'])?> · <?=e(date_de($scheduled))?></p></div>
            <div class="head-actions"><a class="btn ghost" href="<?=e(url('/admin/order/'.$u['order_id']))?>">Zum Auftrag</a><a class="btn ghost" href="<?=e(url('/file/day/'.$uploadId).'?download=1')?>">Download</a></div>
        </div>
        <div class="evidence-viewer">
            <section class="panel evidence-viewer-image"><img src="<?=e(url('/file/day/'.$uploadId))?>" alt="<?=e($u['label']?:'Nachweis')?>"></section>
            <aside class="panel evidence-viewer-side">
                <h2>Nachweisdetails</h2>
                <p><strong><?=e($u['title_snapshot'])?></strong></p>
                <div class="meta-list"><span>Vorgang <?=e($u['event_no']??'–')?></span><span>Upload <?=e(date('d.m.Y H:i',strtotime($u['created_at'])))?> Uhr</span><span><?=e(round(((int)$u['file_size'])/1024))?> KB</span><span>SHA-256: <?=e(substr($u['sha256'],0,16))?>…</span></div>
                <?php if($u['order_status']==='running'&&in_array($u['day_status'],['planned','submitted'],true)&&$u['event_id']):?>
                <details class="inline-editor">
                    <summary>Dieses Foto neu anfordern</summary>
                    <form method="post" action="<?=e(url('/admin/event/'.$u['event_id'].'/request-resubmission'))?>">
                        <label>Grund<textarea name="reason" rows="3" required></textarea></label>
                        <button class="btn danger full">Foto verwerfen & neu anfordern</button>
                    </form>
                </details>
                <?php endif;?>
                <?php if($u['day_status']==='submitted'):?>
                <form class="review-actions" method="post" action="<?=e(url('/admin/day/'.$u['day_id'].'/review'))?>">
                    <label>Prüfnotiz <span class="muted">(optional)</span><textarea name="admin_note" rows="3"></textarea></label>
                    <div><button class="btn" name="decision" value="fulfilled">✓ Tag erfüllt</button><button class="btn danger" name="decision" value="not_fulfilled">✕ Nicht erfüllt · +1 Tag</button></div>
                </form>
                <?php endif;?>
            </aside>
        </div>
        <div class="viewer-nav"><?php if($prev):?><a class="btn ghost" href="<?=e(url('/admin/evidence/day/'.$prev))?>">← Vorheriges Foto</a><?php else:?><span></span><?php endif;?><?php if($next):?><a class="btn ghost" href="<?=e(url('/admin/evidence/day/'.$next))?>">Nächstes Foto →</a><?php endif;?></div>
        <?php render('Nachweis '.$u['order_no'],ob_get_clean());exit;
    }

    $q=db()->prepare("SELECT p.*,o.id order_id,o.offer_id,o.order_no,o.title_snapshot,o.status order_status,
        s.first_name,s.last_name FROM precheck_uploads p JOIN orders o ON o.id=p.order_id JOIN sellers s ON s.id=o.seller_id WHERE p.id=?");
    $q->execute([$uploadId]);$u=$q->fetch();if(!$u)not_found();
    $q=db()->prepare("SELECT id FROM precheck_uploads WHERE order_id=? ORDER BY id");$q->execute([$u['order_id']]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    $pos=array_search($uploadId,$ids,true);$prev=$pos!==false&&$pos>0?$ids[$pos-1]:null;$next=$pos!==false&&$pos<count($ids)-1?$ids[$pos+1]:null;
    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow"><?=e($u['order_no'])?> · Vorabkontrolle</span><h1>Vorabfoto</h1><p><?=e($u['first_name'].' '.$u['last_name'])?></p></div>
        <div class="head-actions"><a class="btn ghost" href="<?=e(url('/admin/order/'.$u['order_id']))?>">Zum Auftrag</a><a class="btn ghost" href="<?=e(url('/file/precheck/'.$uploadId).'?download=1')?>">Download</a></div>
    </div>
    <div class="evidence-viewer">
        <section class="panel evidence-viewer-image"><img src="<?=e(url('/file/precheck/'.$uploadId))?>" alt="Vorabfoto"></section>
        <aside class="panel evidence-viewer-side">
            <h2>Vorabfoto</h2><p><strong><?=e($u['title_snapshot'])?></strong></p>
            <div class="meta-list"><span>Upload <?=e(date('d.m.Y H:i',strtotime($u['created_at'])))?> Uhr</span><span><?=e(round(((int)$u['file_size'])/1024))?> KB</span><span>SHA-256: <?=e(substr($u['sha256'],0,16))?>…</span></div>
            <?php if($u['order_status']==='precheck'):?>
            <form method="post" action="<?=e(url('/admin/precheck-upload/'.$uploadId.'/request-resubmission'))?>">
                <label>Grund für erneute Anforderung<textarea name="reason" rows="3" required></textarea></label>
                <button class="btn danger full">Dieses Foto verwerfen & neu anfordern</button>
            </form>
            <?php endif;?>
        </aside>
    </div>
    <div class="viewer-nav"><?php if($prev):?><a class="btn ghost" href="<?=e(url('/admin/evidence/precheck/'.$prev))?>">← Vorheriges Foto</a><?php else:?><span></span><?php endif;?><?php if($next):?><a class="btn ghost" href="<?=e(url('/admin/evidence/precheck/'.$next))?>">Nächstes Foto →</a><?php endif;?></div>
    <?php render('Vorabfoto '.$u['order_no'],ob_get_clean());exit;
}

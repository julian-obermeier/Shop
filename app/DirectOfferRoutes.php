<?php
declare(strict_types=1);

/**
 * Schlanker Direktangebote-MVP.
 * Admin = Käufer, Seller = Verkäuferin.
 */

function direct_offer_number(): string {
    $year=date('Y');
    $key='direct_offer_'.$year;
    $pdo=db();
    $owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT next_value FROM number_sequences WHERE sequence_key=? FOR UPDATE');
        $q->execute([$key]);
        $row=$q->fetch();
        if(!$row){
            $n=1;
            $pdo->prepare('INSERT INTO number_sequences(sequence_key,next_value) VALUES(?,2)')->execute([$key]);
        }else{
            $n=(int)$row['next_value'];
            $pdo->prepare('UPDATE number_sequences SET next_value=? WHERE sequence_key=?')->execute([$n+1,$key]);
        }
        if($owns)$pdo->commit();
        return 'A'.$year.str_pad((string)$n,4,'0',STR_PAD_LEFT);
    }catch(Throwable $e){
        if($owns&&$pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function direct_order_number(string $offerNo,int $positionNo): string {
    return $offerNo.'-'.str_pad((string)$positionNo,2,'0',STR_PAD_LEFT);
}

function direct_upload_files(string $key): array {
    $raw=$_FILES[$key]??null;
    if(!$raw)return [];
    if(!is_array($raw['name']??null))return [$raw];
    $out=[];
    foreach($raw['name'] as $i=>$name){
        if(($raw['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
        $out[]=[
            'name'=>$name,
            'full_path'=>$raw['full_path'][$i]??$name,
            'type'=>$raw['type'][$i]??'',
            'tmp_name'=>$raw['tmp_name'][$i]??'',
            'error'=>$raw['error'][$i]??UPLOAD_ERR_NO_FILE,
            'size'=>$raw['size'][$i]??0,
        ];
    }
    return $out;
}

function direct_sync_offer_status(int $offerId): void {
    $q=db()->prepare('SELECT status FROM direct_offers WHERE id=?');
    $q->execute([$offerId]);
    $status=(string)$q->fetchColumn();
    if(in_array($status,['draft','sent','declined','cancelled'],true))return;

    $q=db()->prepare("SELECT
      SUM(status='running') running_count,
      SUM(status='precheck') precheck_count,
      SUM(status='completed') completed_count,
      COUNT(*) total_count
      FROM direct_orders WHERE offer_id=?");
    $q->execute([$offerId]);
    $r=$q->fetch()?:[];
    $total=(int)($r['total_count']??0);
    if($total>0 && (int)($r['completed_count']??0)===$total){
        db()->prepare("UPDATE direct_offers SET status='completed',completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$offerId]);
    }elseif((int)($r['running_count']??0)>0){
        db()->prepare("UPDATE direct_offers SET status='active',activated_at=COALESCE(activated_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$offerId]);
    }elseif((int)($r['precheck_count']??0)>0){
        db()->prepare("UPDATE direct_offers SET status='precheck',updated_at=NOW() WHERE id=?")->execute([$offerId]);
    }
}

function direct_stream_upload(string $table,int $id): never {
    $allowed=[
        'direct_precheck_uploads'=>['join'=>'direct_orders o ON o.id=u.order_id','seller'=>'o.seller_id'],
        'direct_day_uploads'=>['join'=>'direct_order_days d ON d.id=u.day_id JOIN direct_orders o ON o.id=d.order_id','seller'=>'o.seller_id'],
    ];
    if(!isset($allowed[$table]))not_found();
    $meta=$allowed[$table];
    $q=db()->prepare("SELECT u.*,{$meta['seller']} owner_seller_id FROM {$table} u JOIN {$meta['join']} WHERE u.id=?");
    $q->execute([$id]);
    $u=$q->fetch();
    if(!$u)not_found();
    $can=admin()!==null;
    if(!$can && ($s=seller()))$can=(int)$s['id']===(int)$u['owner_seller_id'];
    if(!$can){http_response_code(403);exit('Zugriff verweigert.');}
    $relative=ltrim((string)$u['file_path'],'/');
    if($relative===''||str_contains($relative,'..')||str_contains($relative,"\0"))not_found();
    $real=__DIR__.'/../storage/private/'.$relative;
    if(!is_file($real))not_found();
    $mime=(string)($u['mime_type']?:'application/octet-stream');
    if(!str_starts_with($mime,'image/')){http_response_code(415);exit('Nicht unterstütztes Dateiformat.');}
    send_security_headers();
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($real));
    header('Content-Disposition: inline; filename="nachweis-'.$id.'"');
    readfile($real);
    exit;
}

function direct_status_label(string $status): string {
    return match($status){
        'draft'=>'Entwurf','sent'=>'Gesendet','accepted'=>'Angenommen','precheck'=>'Vorabkontrolle',
        'active'=>'Aktiv','completed'=>'Abgeschlossen','declined'=>'Abgelehnt','cancelled'=>'Storniert',
        'running'=>'Läuft','planned'=>'Geplant','submitted'=>'Eingereicht','fulfilled'=>'Erfüllt','not_fulfilled'=>'Nicht erfüllt',default=>$status,
    };
}

if(preg_match('#^/direktdatei/(precheck|tag)/(\d+)$#',$path,$m)&&$method==='GET'){
    direct_stream_upload($m[1]==='precheck'?'direct_precheck_uploads':'direct_day_uploads',(int)$m[2]);
}

// ADMIN: Übersicht
if($path==='/admin/direktangebote'&&$method==='GET'){
    require_admin();
    $offers=db()->query("SELECT d.*,CONCAT(s.first_name,' ',s.last_name) seller_name,
      (SELECT COUNT(*) FROM direct_offer_items i WHERE i.offer_id=d.id) item_count,
      (SELECT COUNT(*) FROM direct_orders o WHERE o.offer_id=d.id AND o.status='completed') completed_orders,
      (SELECT COUNT(*) FROM direct_orders o WHERE o.offer_id=d.id) order_count
      FROM direct_offers d JOIN sellers s ON s.id=d.seller_id ORDER BY d.created_at DESC")->fetchAll();
    ob_start(); ?>
    <div class="dashboard-head"><div><div class="eyebrow">Direktangebote</div><h1>Angebote an Verkäuferinnen</h1><p class="meta">Ein Angebot kann mehrere Positionen enthalten. Jede Position wird nach Annahme zu einem eigenen Auftrag.</p></div><a class="btn" href="<?=e(url('/admin/direktangebote/neu'))?>">+ Neues Angebot</a></div>
    <div class="grid"><?php foreach($offers as $o): ?><article class="card"><span class="badge"><?=e(direct_status_label((string)$o['status']))?></span><h3><?=e($o['offer_no'])?> · <?=e($o['title'])?></h3><p><?=e($o['seller_name'])?></p><p class="meta"><?=e($o['item_count'])?> Position(en)<?php if((int)$o['order_count']>0):?> · <?=e($o['completed_orders'])?>/<?=e($o['order_count'])?> Aufträge abgeschlossen<?php endif;?></p><a class="btn secondary" href="<?=e(url('/admin/direktangebot/'.$o['id']))?>">Öffnen</a></article><?php endforeach; ?><?php if(!$offers):?><div class="empty">Noch keine Direktangebote vorhanden.</div><?php endif;?></div>
    <?php render('Direktangebote',ob_get_clean());exit;
}

// ADMIN: Angebot anlegen
if($path==='/admin/direktangebote/neu'&&$method==='GET'){
    require_admin();
    $sellers=db()->query("SELECT id,first_name,last_name,email FROM sellers WHERE deleted_at IS NULL ORDER BY first_name,last_name")->fetchAll();
    ob_start(); ?>
    <div class="dashboard-head"><div><div class="eyebrow">Direktangebot</div><h1>Neues Angebot</h1></div></div>
    <form method="post" class="panel"><?=csrf_field()?>
      <div class="form-grid"><label>Verkäuferin<select name="seller_id" required><option value="">Bitte wählen</option><?php foreach($sellers as $s):?><option value="<?=e($s['id'])?>"><?=e($s['first_name'].' '.$s['last_name'].' · '.$s['email'])?></option><?php endforeach;?></select></label><label>Titel<input name="title" required maxlength="190" placeholder="z. B. 14 Tage Socken + Schuhe"></label></div>
      <label>Einleitung / Hinweise<textarea name="intro" rows="4" placeholder="Kurze Beschreibung des Gesamtangebots"></textarea></label>
      <label>Regeln, die bei Annahme bestätigt werden müssen<textarea name="rules_text" rows="10" required placeholder="Alle verbindlichen Regeln dieses Angebots"></textarea></label>
      <button class="btn">Entwurf erstellen</button>
    </form>
    <?php render('Direktangebot erstellen',ob_get_clean());exit;
}
if($path==='/admin/direktangebote/neu'&&$method==='POST'){
    $a=require_admin();
    $sellerId=(int)post('seller_id');$title=post('title');$rules=post('rules_text');$intro=post('intro');
    $q=db()->prepare('SELECT COUNT(*) FROM sellers WHERE id=? AND deleted_at IS NULL');$q->execute([$sellerId]);
    if(!$sellerId||!$title||!$rules||(int)$q->fetchColumn()!==1){flash('error','Verkäuferin, Titel und Regeln sind Pflicht.');redirect('/admin/direktangebote/neu');}
    $no=direct_offer_number();
    db()->prepare("INSERT INTO direct_offers(offer_no,seller_id,admin_id,title,intro,rules_text,status) VALUES(?,?,?,?,?,?,'draft')")
      ->execute([$no,$sellerId,$a['id'],$title,$intro?:null,$rules]);
    redirect('/admin/direktangebot/'.db()->lastInsertId());
}

// ADMIN: Angebotsdetail
if(preg_match('#^/admin/direktangebot/(\d+)$#',$path,$m)&&$method==='GET'){
    require_admin();$offerId=(int)$m[1];
    $q=db()->prepare("SELECT d.*,CONCAT(s.first_name,' ',s.last_name) seller_name,s.email seller_email FROM direct_offers d JOIN sellers s ON s.id=d.seller_id WHERE d.id=?");$q->execute([$offerId]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT * FROM direct_offer_items WHERE offer_id=? ORDER BY position_no');$q->execute([$offerId]);$items=$q->fetchAll();
    $q=db()->prepare("SELECT x.*,i.position_no,i.title item_title,i.precheck_required_count,i.daily_required_count,i.precheck_instructions,i.daily_instructions,
      (SELECT COUNT(*) FROM direct_precheck_uploads p WHERE p.order_id=x.id) precheck_count,
      (SELECT COUNT(*) FROM direct_order_days d WHERE d.order_id=x.id AND d.status='fulfilled') fulfilled_days,
      (SELECT COUNT(*) FROM direct_order_days d WHERE d.order_id=x.id AND d.status='not_fulfilled') failed_days
      FROM direct_orders x JOIN direct_offer_items i ON i.id=x.item_id WHERE x.offer_id=? ORDER BY i.position_no");$q->execute([$offerId]);$orders=$q->fetchAll();
    ob_start(); ?>
    <div class="dashboard-head"><div><div class="eyebrow">Direktangebot <?=e($o['offer_no'])?></div><h1><?=e($o['title'])?></h1><p><?=e($o['seller_name'])?> · <?=e($o['seller_email'])?></p></div><span class="badge"><?=e(direct_status_label((string)$o['status']))?></span></div>
    <?php if($o['intro']):?><section class="panel"><h2>Hinweise</h2><p><?=nl2br(e($o['intro']))?></p></section><?php endif;?>
    <section class="panel"><h2>Verbindliche Regeln</h2><p><?=nl2br(e($o['rules_text']))?></p></section>
    <h2>Positionen</h2><div class="grid"><?php foreach($items as $i):?><article class="card"><span class="badge">Position <?=e($i['position_no'])?></span><h3><?=e($i['title'])?></h3><p><?=nl2br(e($i['description']??''))?></p><div class="price"><?=money($i['compensation'])?></div><p class="meta"><?=e($i['duration_days'])?> erforderliche erfolgreiche Tag(e) · <?=e($i['precheck_required_count'])?> Vorabfoto(s) · <?=e($i['daily_required_count'])?> Foto(s) je Tag</p><?php if($o['status']==='draft'):?><form method="post" action="<?=e(url('/admin/direktangebot/'.$o['id'].'/position/'.$i['id'].'/loeschen'))?>"><?=csrf_field()?><button class="btn secondary">Position löschen</button></form><?php endif;?></article><?php endforeach;?><?php if(!$items):?><div class="empty">Noch keine Positionen angelegt.</div><?php endif;?></div>
    <?php if($o['status']==='draft'):?><br><form class="panel" method="post" action="<?=e(url('/admin/direktangebot/'.$o['id'].'/position'))?>"><?=csrf_field()?><h2>Position hinzufügen</h2><div class="form-grid"><label>Titel<input name="title" required maxlength="190"></label><label>Vergütung (€)<input type="number" min="0" step="0.01" name="compensation" required value="0.00"></label><label>Erforderliche erfolgreiche Tage<input type="number" min="1" max="365" name="duration_days" value="1" required></label><label>Vorabfotos<input type="number" min="1" max="20" name="precheck_required_count" value="1" required></label><label>Fotos pro Tag<input type="number" min="1" max="20" name="daily_required_count" value="1" required></label></div><label>Beschreibung<textarea name="description" rows="3"></textarea></label><label>Anforderungen Vorabkontrolle<textarea name="precheck_instructions" rows="3" required placeholder="z. B. 1 Foto des Artikels, 1 Foto getragen"></textarea></label><label>Anforderungen pro Tag<textarea name="daily_instructions" rows="3" required placeholder="z. B. 3 Fotos aus unterschiedlichen Perspektiven"></textarea></label><button class="btn">Position hinzufügen</button></form>
    <br><form method="post" action="<?=e(url('/admin/direktangebot/'.$o['id'].'/senden'))?>" class="panel"><?=csrf_field()?><h2>Angebot senden</h2><p class="meta">Nach dem Senden können die Positionen im MVP nicht mehr verändert werden. Die Verkäuferin muss die Regeln ausdrücklich bestätigen.</p><button class="btn" <?=$items?'':'disabled'?>>An Verkäuferin senden</button></form><?php endif;?>
    <?php if($orders):?><h2 style="margin-top:28px">Entstandene Aufträge</h2><?php foreach($orders as $ord):?>
      <section class="panel"><div class="dashboard-head"><div><div class="eyebrow">Position <?=e($ord['position_no'])?></div><h3><?=e($ord['order_no'])?> · <?=e($ord['item_title'])?></h3></div><span class="badge"><?=e(direct_status_label((string)$ord['status']))?></span></div>
      <p class="meta">Vorab: <?=e($ord['precheck_count'])?>/<?=e($ord['precheck_required_count'])?> Fotos · Erfolgreich: <?=e($ord['fulfilled_days'])?>/<?=e($ord['required_days'])?> Tage · nicht erfüllt: <?=e($ord['failed_days'])?></p>
      <?php $pq=db()->prepare('SELECT * FROM direct_precheck_uploads WHERE order_id=? ORDER BY id');$pq->execute([$ord['id']]);$pre=$pq->fetchAll(); if($pre):?><div style="display:flex;gap:10px;flex-wrap:wrap"><?php foreach($pre as $p):?><a href="<?=e(url('/direktdatei/precheck/'.$p['id']))?>" target="_blank"><img src="<?=e(url('/direktdatei/precheck/'.$p['id']))?>" alt="Vorabnachweis" style="width:110px;height:110px;object-fit:cover;border-radius:12px"></a><?php endforeach;?></div><?php endif;?>
      <?php if($ord['status']==='precheck'):?><p><?=nl2br(e($ord['precheck_instructions']))?></p><form method="post" action="<?=e(url('/admin/direktauftrag/'.$ord['id'].'/vorab-freigeben'))?>"><?=csrf_field()?><button class="btn" <?=((int)$ord['precheck_count']<(int)$ord['precheck_required_count'])?'disabled':''?>>Vorabkontrolle freigeben & Auftrag starten</button></form><?php endif;?>
      <?php if(in_array($ord['status'],['running','completed'],true)):
        $dq=db()->prepare('SELECT * FROM direct_order_days WHERE order_id=? ORDER BY day_no');$dq->execute([$ord['id']]);$days=$dq->fetchAll(); ?>
        <div style="overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th>Tag</th><th>Typ</th><th>Status</th><th>Fotos</th><th>Prüfung</th></tr></thead><tbody><?php foreach($days as $d): $uq=db()->prepare('SELECT * FROM direct_day_uploads WHERE day_id=? ORDER BY id');$uq->execute([$d['id']]);$ups=$uq->fetchAll();?><tr><td>#<?=e($d['day_no'])?></td><td><?=((int)$d['is_extension']===1)?'Verlängerung':'Regulär'?></td><td><?=e(direct_status_label((string)$d['status']))?></td><td><?php foreach($ups as $u):?><a href="<?=e(url('/direktdatei/tag/'.$u['id']))?>" target="_blank">Foto <?=e($u['id'])?></a> <?php endforeach;?></td><td><?php if($d['status']==='submitted'):?><form method="post" action="<?=e(url('/admin/direkttag/'.$d['id'].'/bewerten'))?>" style="display:flex;gap:8px;flex-wrap:wrap"><?=csrf_field()?><button class="btn" name="decision" value="fulfilled">Erfüllt</button><button class="btn secondary" name="decision" value="not_fulfilled">Nicht erfüllt (+1 Tag)</button></form><?php else:?><?=e(direct_status_label((string)$d['status']))?><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
      <?php endif;?></section>
    <?php endforeach; endif; ?>
    <?php render('Direktangebot '.$o['offer_no'],ob_get_clean());exit;
}

if(preg_match('#^/admin/direktangebot/(\d+)/position$#',$path,$m)&&$method==='POST'){
    require_admin();$offerId=(int)$m[1];
    $q=db()->prepare("SELECT status FROM direct_offers WHERE id=?");$q->execute([$offerId]);if($q->fetchColumn()!=='draft'){flash('error','Positionen können nur im Entwurf geändert werden.');redirect('/admin/direktangebot/'.$offerId);}
    $title=post('title');$days=max(1,min(365,(int)post('duration_days','1')));$pre=max(1,min(20,(int)post('precheck_required_count','1')));$daily=max(1,min(20,(int)post('daily_required_count','1')));
    if(!$title||!post('precheck_instructions')||!post('daily_instructions')){flash('error','Titel und Nachweisanforderungen sind Pflicht.');redirect('/admin/direktangebot/'.$offerId);}
    $q=db()->prepare('SELECT COALESCE(MAX(position_no),0)+1 FROM direct_offer_items WHERE offer_id=?');$q->execute([$offerId]);$pos=(int)$q->fetchColumn();
    db()->prepare('INSERT INTO direct_offer_items(offer_id,position_no,title,description,compensation,duration_days,precheck_required_count,daily_required_count,precheck_instructions,daily_instructions) VALUES(?,?,?,?,?,?,?,?,?,?)')
      ->execute([$offerId,$pos,$title,post('description')?:null,max(0,(float)post('compensation','0')),$days,$pre,$daily,post('precheck_instructions'),post('daily_instructions')]);
    flash('success','Position '.$pos.' wurde hinzugefügt.');redirect('/admin/direktangebot/'.$offerId);
}

if(preg_match('#^/admin/direktangebot/(\d+)/position/(\d+)/loeschen$#',$path,$m)&&$method==='POST'){
    require_admin();$offerId=(int)$m[1];$itemId=(int)$m[2];
    $q=db()->prepare("SELECT status FROM direct_offers WHERE id=?");$q->execute([$offerId]);if($q->fetchColumn()!=='draft'){flash('error','Nur Entwürfe können geändert werden.');redirect('/admin/direktangebot/'.$offerId);}
    db()->prepare('DELETE FROM direct_offer_items WHERE id=? AND offer_id=?')->execute([$itemId,$offerId]);
    $q=db()->prepare('SELECT id FROM direct_offer_items WHERE offer_id=? ORDER BY position_no,id');$q->execute([$offerId]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$n=1;foreach($ids as $id){db()->prepare('UPDATE direct_offer_items SET position_no=? WHERE id=?')->execute([$n++,$id]);}
    flash('success','Position wurde gelöscht.');redirect('/admin/direktangebot/'.$offerId);
}

if(preg_match('#^/admin/direktangebot/(\d+)/senden$#',$path,$m)&&$method==='POST'){
    require_admin();$offerId=(int)$m[1];
    $q=db()->prepare("SELECT d.*,s.email seller_email,s.first_name FROM direct_offers d JOIN sellers s ON s.id=d.seller_id WHERE d.id=?");$q->execute([$offerId]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare('SELECT COUNT(*) FROM direct_offer_items WHERE offer_id=?');$q->execute([$offerId]);
    if($o['status']!=='draft'||(int)$q->fetchColumn()<1){flash('error','Das Angebot benötigt mindestens eine Position und muss im Entwurf sein.');redirect('/admin/direktangebot/'.$offerId);}
    db()->prepare("UPDATE direct_offers SET status='sent',sent_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$offerId]);
    send_app_mail((string)$o['seller_email'],'Neues Direktangebot '.$o['offer_no'],'<h1>Neues Angebot</h1><p>Hallo '.e($o['first_name']).', für dich wurde ein neues Direktangebot <strong>'.e($o['offer_no']).'</strong> erstellt.</p><p><a href="'.e(url('/direktangebot/'.$offerId)).'">Angebot öffnen</a></p>');
    flash('success','Angebot wurde an die Verkäuferin gesendet.');redirect('/admin/direktangebot/'.$offerId);
}

if(preg_match('#^/admin/direktauftrag/(\d+)/vorab-freigeben$#',$path,$m)&&$method==='POST'){
    require_admin();$orderId=(int)$m[1];
    $q=db()->prepare("SELECT o.*,i.precheck_required_count,i.daily_required_count FROM direct_orders o JOIN direct_offer_items i ON i.id=o.item_id WHERE o.id=?");$q->execute([$orderId]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck'){flash('error','Dieser Auftrag befindet sich nicht in der Vorabkontrolle.');redirect('/admin/direktangebot/'.$o['offer_id']);}
    $q=db()->prepare('SELECT COUNT(*) FROM direct_precheck_uploads WHERE order_id=?');$q->execute([$orderId]);
    if((int)$q->fetchColumn()<(int)$o['precheck_required_count']){flash('error','Es fehlen noch Vorabfotos.');redirect('/admin/direktangebot/'.$o['offer_id']);}
    db()->beginTransaction();try{
        db()->prepare("UPDATE direct_orders SET status='running',precheck_approved_at=NOW(),started_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$orderId]);
        $q=db()->prepare('SELECT COUNT(*) FROM direct_order_days WHERE order_id=?');$q->execute([$orderId]);
        if((int)$q->fetchColumn()===0){
            $ins=db()->prepare("INSERT INTO direct_order_days(order_id,day_no,is_extension,required_photo_count,status) VALUES(?,?,0,?,'planned')");
            for($d=1;$d<=(int)$o['required_days'];$d++)$ins->execute([$orderId,$d,$o['daily_required_count']]);
        }
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    direct_sync_offer_status((int)$o['offer_id']);
    flash('success','Vorabkontrolle freigegeben. Der Auftrag läuft jetzt.');redirect('/admin/direktangebot/'.$o['offer_id']);
}

if(preg_match('#^/admin/direkttag/(\d+)/bewerten$#',$path,$m)&&$method==='POST'){
    $a=require_admin();$dayId=(int)$m[1];$decision=post('decision');
    if(!in_array($decision,['fulfilled','not_fulfilled'],true)){flash('error','Ungültige Entscheidung.');redirect('/admin/direktangebote');}
    $q=db()->prepare("SELECT d.*,o.offer_id,o.required_days,o.status order_status,i.daily_required_count FROM direct_order_days d JOIN direct_orders o ON o.id=d.order_id JOIN direct_offer_items i ON i.id=o.item_id WHERE d.id=?");$q->execute([$dayId]);$d=$q->fetch();if(!$d)not_found();
    if($d['status']!=='submitted'){flash('error','Nur eingereichte Tage können bewertet werden.');redirect('/admin/direktangebot/'.$d['offer_id']);}
    db()->beginTransaction();try{
        db()->prepare('UPDATE direct_order_days SET status=?,reviewed_at=NOW(),reviewed_by=?,updated_at=NOW() WHERE id=?')->execute([$decision,$a['id'],$dayId]);
        if($decision==='not_fulfilled'){
            $q=db()->prepare('SELECT id FROM direct_order_days WHERE extension_for_day_id=? LIMIT 1');$q->execute([$dayId]);
            if(!$q->fetchColumn()){
                $q=db()->prepare('SELECT COALESCE(MAX(day_no),0)+1 FROM direct_order_days WHERE order_id=?');$q->execute([$d['order_id']]);$next=(int)$q->fetchColumn();
                db()->prepare("INSERT INTO direct_order_days(order_id,day_no,is_extension,extension_for_day_id,required_photo_count,status) VALUES(?,?,1,?,?,'planned')")
                  ->execute([$d['order_id'],$next,$dayId,$d['daily_required_count']]);
                db()->prepare('UPDATE direct_orders SET extension_days=extension_days+1,updated_at=NOW() WHERE id=?')->execute([$d['order_id']]);
            }
        }
        $q=db()->prepare("SELECT COUNT(*) FROM direct_order_days WHERE order_id=? AND status='fulfilled'");$q->execute([$d['order_id']]);$fulfilled=(int)$q->fetchColumn();
        $q=db()->prepare("SELECT COUNT(*) FROM direct_order_days WHERE order_id=? AND status IN('planned','submitted')");$q->execute([$d['order_id']]);$open=(int)$q->fetchColumn();
        db()->prepare('UPDATE direct_orders SET successful_days=?,updated_at=NOW() WHERE id=?')->execute([$fulfilled,$d['order_id']]);
        if($fulfilled>=(int)$d['required_days']&&$open===0)db()->prepare("UPDATE direct_orders SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$d['order_id']]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    direct_sync_offer_status((int)$d['offer_id']);
    flash('success',$decision==='fulfilled'?'Tag als erfüllt bestätigt.':'Tag als nicht erfüllt markiert. Ein zusätzlicher Tag wurde angehängt.');redirect('/admin/direktangebot/'.$d['offer_id']);
}

// SELLER: Übersicht
if($path==='/direktangebote'&&$method==='GET'){
    $s=require_seller();
    $q=db()->prepare("SELECT d.*,(SELECT COUNT(*) FROM direct_offer_items i WHERE i.offer_id=d.id) item_count FROM direct_offers d WHERE d.seller_id=? AND d.status<>'draft' ORDER BY d.created_at DESC");$q->execute([$s['id']]);$offers=$q->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Meine Direktangebote</div><h1>Angebote & Aufträge</h1></div></div><div class="grid"><?php foreach($offers as $o):?><article class="card"><span class="badge"><?=e(direct_status_label((string)$o['status']))?></span><h3><?=e($o['offer_no'])?> · <?=e($o['title'])?></h3><p class="meta"><?=e($o['item_count'])?> Position(en)</p><a class="btn" href="<?=e(url('/direktangebot/'.$o['id']))?>">Öffnen</a></article><?php endforeach;?><?php if(!$offers):?><div class="empty">Du hast aktuell keine Direktangebote.</div><?php endif;?></div><?php render('Meine Direktangebote',ob_get_clean());exit;
}

if(preg_match('#^/direktangebot/(\d+)$#',$path,$m)&&$method==='GET'){
    $s=require_seller();$offerId=(int)$m[1];
    $q=db()->prepare('SELECT * FROM direct_offers WHERE id=? AND seller_id=?');$q->execute([$offerId,$s['id']]);$o=$q->fetch();if(!$o||$o['status']==='draft')not_found();
    $q=db()->prepare('SELECT * FROM direct_offer_items WHERE offer_id=? ORDER BY position_no');$q->execute([$offerId]);$items=$q->fetchAll();
    $q=db()->prepare("SELECT x.*,i.position_no,i.title item_title,i.description,i.compensation,i.precheck_required_count,i.daily_required_count,i.precheck_instructions,i.daily_instructions FROM direct_orders x JOIN direct_offer_items i ON i.id=x.item_id WHERE x.offer_id=? AND x.seller_id=? ORDER BY i.position_no");$q->execute([$offerId,$s['id']]);$orders=$q->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Direktangebot <?=e($o['offer_no'])?></div><h1><?=e($o['title'])?></h1></div><span class="badge"><?=e(direct_status_label((string)$o['status']))?></span></div><?php if($o['intro']):?><section class="panel"><p><?=nl2br(e($o['intro']))?></p></section><?php endif;?><section class="panel"><h2>Verbindliche Regeln</h2><p><?=nl2br(e($o['rules_text']))?></p></section>
    <h2>Positionen</h2><div class="grid"><?php foreach($items as $i):?><article class="card"><span class="badge">Position <?=e($i['position_no'])?></span><h3><?=e($i['title'])?></h3><p><?=nl2br(e($i['description']??''))?></p><div class="price"><?=money($i['compensation'])?></div><p class="meta"><?=e($i['duration_days'])?> erfolgreiche Tag(e) · Vorab <?=e($i['precheck_required_count'])?> Foto(s) · täglich <?=e($i['daily_required_count'])?> Foto(s)</p><p><strong>Vorab:</strong><br><?=nl2br(e($i['precheck_instructions']))?></p><p><strong>Pro Tag:</strong><br><?=nl2br(e($i['daily_instructions']))?></p></article><?php endforeach;?></div>
    <?php if($o['status']==='sent'):?><br><form method="post" action="<?=e(url('/direktangebot/'.$o['id'].'/annehmen'))?>" class="panel"><?=csrf_field()?><h2>Angebot verbindlich annehmen</h2><label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="accept_rules" value="1" required style="width:auto;margin-top:5px"><span>Ich habe sämtliche Regeln und Anforderungen gelesen und akzeptiere sie verbindlich. Mir ist bekannt, dass jede Position zu einem eigenen Auftrag wird und ein nicht erfüllter Tag einen zusätzlichen Durchführungstag erzeugt.</span></label><button class="btn">Angebot annehmen</button></form><?php endif;?>
    <?php foreach($orders as $ord):?><section class="panel" style="margin-top:18px"><div class="dashboard-head"><div><div class="eyebrow">Position <?=e($ord['position_no'])?></div><h2><?=e($ord['order_no'])?> · <?=e($ord['item_title'])?></h2></div><span class="badge"><?=e(direct_status_label((string)$ord['status']))?></span></div>
      <?php if($ord['status']==='precheck'):
        $pq=db()->prepare('SELECT * FROM direct_precheck_uploads WHERE order_id=? ORDER BY id');$pq->execute([$ord['id']]);$pre=$pq->fetchAll(); ?><h3>Vorabkontrolle</h3><p><?=nl2br(e($ord['precheck_instructions']))?></p><p class="meta"><?=e(count($pre))?>/<?=e($ord['precheck_required_count'])?> Foto(s) hochgeladen</p><?php if($pre):?><div style="display:flex;gap:10px;flex-wrap:wrap"><?php foreach($pre as $p):?><a href="<?=e(url('/direktdatei/precheck/'.$p['id']))?>" target="_blank"><img src="<?=e(url('/direktdatei/precheck/'.$p['id']))?>" alt="Vorabfoto" style="width:110px;height:110px;object-fit:cover;border-radius:12px"></a><?php endforeach;?></div><?php endif;?><?php if(count($pre)<(int)$ord['precheck_required_count']):?><form method="post" enctype="multipart/form-data" action="<?=e(url('/direktauftrag/'.$ord['id'].'/vorab'))?>" style="margin-top:14px"><?=csrf_field()?><label>Vorabfotos<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple required></label><button class="btn">Fotos hochladen</button></form><?php else:?><p><strong>Vorabkontrolle vollständig eingereicht.</strong> Warte auf die Freigabe durch den Admin.</p><?php endif;?>
      <?php elseif(in_array($ord['status'],['running','completed'],true)):
        $dq=db()->prepare('SELECT * FROM direct_order_days WHERE order_id=? ORDER BY day_no');$dq->execute([$ord['id']]);$days=$dq->fetchAll();
        $fulfilled=count(array_filter($days,fn($d)=>$d['status']==='fulfilled'));$failed=count(array_filter($days,fn($d)=>$d['status']==='not_fulfilled'));
        $current=null;foreach($days as $day){if(in_array($day['status'],['planned','submitted'],true)){$current=$day;break;}} ?>
        <p><strong>Fortschritt: <?=e($fulfilled)?>/<?=e($ord['required_days'])?> erfolgreiche Tage</strong><?php if($failed):?> · <?=e($failed)?> nicht erfüllt → +<?=e($ord['extension_days'])?> Tag(e)<?php endif;?></p><p class="meta">Tagesanforderung: <?=nl2br(e($ord['daily_instructions']))?></p>
        <?php if($ord['status']==='completed'):?><div class="empty">Dieser Auftrag ist abgeschlossen.</div><?php elseif($current):?><div class="card"><h3>Aktueller Tag <?=e($current['day_no'])?><?=((int)$current['is_extension']===1)?' · Verlängerung':''?></h3><?php if($current['status']==='submitted'):?><p>Nachweise wurden eingereicht und warten auf Prüfung.</p><?php else:?><p><?=e($current['required_photo_count'])?> Foto(s) erforderlich.</p><form method="post" enctype="multipart/form-data" action="<?=e(url('/direkttag/'.$current['id'].'/nachweise'))?>"><?=csrf_field()?><label>Fotos<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple required></label><label>Kommentar (optional)<textarea name="seller_note" rows="3"></textarea></label><button class="btn">Tag einreichen</button></form><?php endif;?></div><?php endif;?>
        <div style="overflow:auto;margin-top:14px"><table style="width:100%;border-collapse:collapse"><thead><tr><th>Tag</th><th>Status</th><th>Typ</th></tr></thead><tbody><?php foreach($days as $d):?><tr><td><?=e($d['day_no'])?></td><td><?=e(direct_status_label((string)$d['status']))?></td><td><?=((int)$d['is_extension']===1)?'Verlängerung':'Regulär'?></td></tr><?php endforeach;?></tbody></table></div>
      <?php endif;?></section><?php endforeach;?>
    <?php render('Direktangebot '.$o['offer_no'],ob_get_clean());exit;
}

if(preg_match('#^/direktangebot/(\d+)/annehmen$#',$path,$m)&&$method==='POST'){
    $s=require_seller();$offerId=(int)$m[1];
    if(post('accept_rules')!=='1'){flash('error','Die Regeln müssen verbindlich akzeptiert werden.');redirect('/direktangebot/'.$offerId);}
    $q=db()->prepare("SELECT * FROM direct_offers WHERE id=? AND seller_id=? FOR UPDATE");
    db()->beginTransaction();try{
        $q->execute([$offerId,$s['id']]);$o=$q->fetch();if(!$o||$o['status']!=='sent')throw new RuntimeException('Dieses Angebot kann nicht mehr angenommen werden.');
        $iq=db()->prepare('SELECT * FROM direct_offer_items WHERE offer_id=? ORDER BY position_no');$iq->execute([$offerId]);$items=$iq->fetchAll();if(!$items)throw new RuntimeException('Das Angebot enthält keine Positionen.');
        db()->prepare("UPDATE direct_offers SET status='accepted',accepted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$offerId]);
        db()->prepare('INSERT INTO direct_offer_acceptances(offer_id,seller_id,rules_snapshot,payload_json) VALUES(?,?,?,?)')->execute([$offerId,$s['id'],$o['rules_text'],json_encode(['offer_no'=>$o['offer_no'],'title'=>$o['title'],'accepted_at'=>date(DATE_ATOM),'item_count'=>count($items),'extension_rule'=>'Jeder nicht erfüllte Tag erzeugt genau einen zusätzlichen Durchführungstag.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $ins=db()->prepare("INSERT INTO direct_orders(order_no,offer_id,item_id,seller_id,status,required_days,extension_days,successful_days,compensation) VALUES(?,?,?,?,'precheck',?,0,0,?)");
        foreach($items as $item)$ins->execute([direct_order_number((string)$o['offer_no'],(int)$item['position_no']),$offerId,$item['id'],$s['id'],$item['duration_days'],$item['compensation']]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();flash('error',$e->getMessage());redirect('/direktangebot/'.$offerId);}
    direct_sync_offer_status($offerId);
    flash('success','Angebot angenommen. Bitte jetzt für jede Position die Vorabkontrolle durchführen.');redirect('/direktangebot/'.$offerId);
}

if(preg_match('#^/direktauftrag/(\d+)/vorab$#',$path,$m)&&$method==='POST'){
    $s=require_seller();$orderId=(int)$m[1];
    $q=db()->prepare("SELECT o.*,i.precheck_required_count,i.daily_required_count FROM direct_orders o JOIN direct_offer_items i ON i.id=o.item_id WHERE o.id=? AND o.seller_id=?");$q->execute([$orderId,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck'){flash('error','Die Vorabkontrolle ist nicht mehr geöffnet.');redirect('/direktangebot/'.$o['offer_id']);}
    $q=db()->prepare('SELECT COUNT(*) FROM direct_precheck_uploads WHERE order_id=?');$q->execute([$orderId]);$existing=(int)$q->fetchColumn();$needed=(int)$o['precheck_required_count'];
    $files=direct_upload_files('photos');
    if(!$files){flash('error','Bitte mindestens ein Foto auswählen.');redirect('/direktangebot/'.$o['offer_id']);}
    if($existing+count($files)>$needed){flash('error','Bitte nur die noch benötigte Anzahl an Vorabfotos hochladen.');redirect('/direktangebot/'.$o['offer_id']);}
    try{
        foreach($files as $file){$up=private_image_upload($file,'direct/order-'.$orderId.'/precheck');db()->prepare('INSERT INTO direct_precheck_uploads(order_id,seller_id,file_path,mime_type,file_size,sha256) VALUES(?,?,?,?,?,?)')->execute([$orderId,$s['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);}
        flash('success','Vorabfoto(s) wurden gespeichert.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('/direktangebot/'.$o['offer_id']);
}

if(preg_match('#^/direkttag/(\d+)/nachweise$#',$path,$m)&&$method==='POST'){
    $s=require_seller();$dayId=(int)$m[1];
    $q=db()->prepare("SELECT d.*,o.offer_id,o.seller_id,o.status order_status FROM direct_order_days d JOIN direct_orders o ON o.id=d.order_id WHERE d.id=? AND o.seller_id=?");$q->execute([$dayId,$s['id']]);$d=$q->fetch();if(!$d)not_found();
    if($d['order_status']!=='running'||$d['status']!=='planned'){flash('error','Dieser Tag kann aktuell nicht eingereicht werden.');redirect('/direktangebot/'.$d['offer_id']);}
    $q=db()->prepare("SELECT id FROM direct_order_days WHERE order_id=? AND status IN('planned','submitted') ORDER BY day_no LIMIT 1");$q->execute([$d['order_id']]);if((int)$q->fetchColumn()!==$dayId){flash('error','Bitte die Tage der Reihe nach abschließen.');redirect('/direktangebot/'.$d['offer_id']);}
    $files=direct_upload_files('photos');$required=(int)$d['required_photo_count'];
    if(count($files)!==$required){flash('error','Für diesen Tag sind genau '.$required.' Foto(s) erforderlich.');redirect('/direktangebot/'.$d['offer_id']);}
    db()->beginTransaction();try{
        foreach($files as $file){$up=private_image_upload($file,'direct/order-'.$d['order_id'].'/day-'.$d['day_no']);db()->prepare('INSERT INTO direct_day_uploads(day_id,seller_id,file_path,mime_type,file_size,sha256) VALUES(?,?,?,?,?,?)')->execute([$dayId,$s['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);}
        db()->prepare("UPDATE direct_order_days SET status='submitted',seller_note=?,submitted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([post('seller_note')?:null,$dayId]);
        db()->commit();flash('success','Tag '.$d['day_no'].' wurde vollständig eingereicht und wartet auf Prüfung.');
    }catch(Throwable $e){db()->rollBack();flash('error',$e->getMessage());}
    redirect('/direktangebot/'.$d['offer_id']);
}

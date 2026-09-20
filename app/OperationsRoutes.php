<?php
declare(strict_types=1);

/**
 * V1 operational routes: today views, spontaneous evidence, tasks,
 * reusable task library, private individual offers and admin search.
 */

function v1_order_for_seller(string $orderNo, int $sellerId): ?array {
    $q=db()->prepare("SELECT o.*,f.title,f.fulfillment_type FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");
    $q->execute([$orderNo,$sellerId]);
    return $q->fetch() ?: null;
}

function v1_create_order_from_offer(array $offer, array $sellerRow, array $selectedOptions=[]): string {
    $optionsTotal=0.0;
    foreach($selectedOptions as $opt) $optionsTotal+=(float)$opt['price'];
    $total=(float)$offer['compensation']+$optionsTotal;
    $no=order_number();

    db()->beginTransaction();
    try{
        db()->prepare("INSERT INTO orders(order_no,seller_id,offer_id,offer_version,status,base_compensation,total_compensation,duration_days) VALUES(?,?,?,?, 'precheck',?,?,?)")
            ->execute([$no,$sellerRow['id'],$offer['id'],$offer['current_version'],$offer['compensation'],$total,$offer['duration_days']]);
        $oid=(int)db()->lastInsertId();
        db()->prepare("INSERT INTO order_runs(order_id,run_no,status) VALUES(?,1,'precheck')")->execute([$oid]);
        foreach($selectedOptions as $opt){
            db()->prepare("INSERT INTO order_options(order_id,offer_option_id,label_snapshot,price_snapshot) VALUES(?,?,?,?)")
                ->execute([$oid,$opt['id'],$opt['label'],$opt['price']]);
        }
        db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Auftragswert vorgemerkt')")
            ->execute([$sellerRow['id'],$oid,$total]);
        if(in_array($offer['fulfillment_type'],['digital','mixed'],true)){
            db()->prepare("INSERT INTO rights_acceptances(order_id,seller_id,terms_version,payload_json) VALUES(?,?,?,?)")
                ->execute([$oid,$sellerRow['id'],'v1',json_encode(['scope'=>'technical_processing_and_order_terms'],JSON_UNESCAPED_UNICODE)]);
        }
        log_event('order.accepted',(int)$sellerRow['id'],$oid,['offer_version'=>(int)$offer['current_version'],'total'=>$total]);
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
            ->execute([$oid,'Auftrag '.$no.' wurde angenommen und befindet sich in der Vorbereitung.']);
        db()->commit();
    }catch(Throwable $e){
        db()->rollBack();
        throw $e;
    }
    notify_seller((int)$sellerRow['id'],'order.accepted','Auftrag angenommen','Auftrag '.$no.' wurde erfolgreich angelegt.','/auftrag/'.$no,null,true);
    return $no;
}

if ($path==='/heute' && $method==='GET') {
    $s=require_seller();
    $today=date('Y-m-d');

    $q=db()->prepare("SELECT ew.*,o.order_no,f.title FROM evidence_windows ew JOIN orders o ON o.id=ew.order_id JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? AND DATE(ew.starts_at)=? AND ew.status IN('planned','open','missed') ORDER BY ew.starts_at");
    $q->execute([$s['id'],$today]);$windows=$q->fetchAll();

    $q=db()->prepare("SELECT sr.*,o.order_no,f.title FROM spontaneous_requests sr JOIN orders o ON o.id=sr.order_id JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? AND sr.status NOT IN('reviewed') AND sr.grace_ends_at>=NOW() ORDER BY sr.due_at");
    $q->execute([$s['id']]);$spontaneous=$q->fetchAll();

    $q=db()->prepare("SELECT t.*,o.order_no,f.title offer_title FROM order_tasks t JOIN orders o ON o.id=t.order_id JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? AND t.status IN('open','rejected') ORDER BY COALESCE(t.due_at,'9999-12-31')");
    $q->execute([$s['id']]);$tasks=$q->fetchAll();

    $q=db()->prepare("SELECT r.*,o.order_no,f.title FROM revision_rounds r JOIN orders o ON o.id=r.order_id JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? AND r.status='open' ORDER BY COALESCE(r.due_at,'9999-12-31')");
    $q->execute([$s['id']]);$revisions=$q->fetchAll();

    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Heute</div><h1>Deine Arbeitsliste</h1><p class="meta"><?=e(date('d.m.Y'))?></p></div><a class="btn secondary" href="<?=e(url('/dashboard'))?>">Dashboard</a></div>
    <div class="grid two">
      <section class="panel"><h2>Jetzt erledigen</h2>
        <div class="timeline">
        <?php foreach($windows as $w):?>
          <div><strong><?=e($w['title'])?> · <?=e($w['window_key'])?></strong><br><span class="meta"><?=e(date('H:i',strtotime($w['starts_at'])))?>–<?=e(date('H:i',strtotime($w['ends_at'])))?> · Status <?=e($w['status'])?></span><br><a href="<?=e(url('/auftrag/'.$w['order_no']))?>">Auftrag öffnen</a></div>
        <?php endforeach;?>
        <?php foreach($spontaneous as $r):?>
          <div><strong>Spontaner Nachweis · <?=e($r['title'])?></strong><p><?=e($r['instructions'])?></p><span class="meta">Fällig <?=e(date('d.m.Y H:i',strtotime($r['due_at'])))?></span><br><a href="<?=e(url('/auftrag/'.$r['order_no'].'/spontan/'.$r['id']))?>">Jetzt erledigen</a></div>
        <?php endforeach;?>
        <?php if(!$windows&&!$spontaneous):?><div class="empty">Aktuell keine sofortigen Nachweise.</div><?php endif;?>
        </div>
      </section>
      <section class="panel"><h2>Aufgaben & Revisionen</h2>
        <div class="timeline">
        <?php foreach($tasks as $t):?><div><strong><?=e($t['title'])?></strong><p><?=e($t['description']??'')?></p><span class="meta"><?=e($t['due_at']?'Fällig '.date('d.m.Y H:i',strtotime($t['due_at'])):'Keine feste Frist')?></span><br><a href="<?=e(url('/auftrag/'.$t['order_no'].'/aufgabe/'.$t['id']))?>">Aufgabe öffnen</a></div><?php endforeach;?>
        <?php foreach($revisions as $r):?><div><strong>Revision · <?=e($r['title'])?></strong><span class="meta"> · Runde <?=e($r['round_no'])?></span><br><a href="<?=e(url('/auftrag/'.$r['order_no'].'/digital'))?>">Digitale Abgabe öffnen</a></div><?php endforeach;?>
        <?php if(!$tasks&&!$revisions):?><div class="empty">Keine offenen Aufgaben oder Revisionen.</div><?php endif;?>
        </div>
      </section>
    </div>
    <?php render('Heute',ob_get_clean());exit;
}

if (preg_match('#^/admin/auftrag/(\d{8})/spontan$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $instructions=post('instructions');$count=max(1,(int)post('required_count','1'));$dueRaw=post('due_at');
    if($instructions===''||$dueRaw===''){flash('error','Anweisung und Frist sind erforderlich.');redirect('/admin/auftrag/'.$o['order_no']);}
    $due=new DateTimeImmutable($dueRaw,new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
    $grace=$due->modify('+'.max(0,(int)setting_value('grace_minutes','60')).' minutes');
    db()->prepare("INSERT INTO spontaneous_requests(order_id,instructions,required_count,due_at,grace_ends_at) VALUES(?,?,?,?,?)")
        ->execute([$o['id'],$instructions,$count,$due->format('Y-m-d H:i:s'),$grace->format('Y-m-d H:i:s')]);
    $id=(int)db()->lastInsertId();
    notify_seller((int)$o['seller_id'],'spontaneous.request','Spontaner Nachweis angefordert',$instructions.' · Fällig '.$due->format('d.m.Y H:i'),'/auftrag/'.$o['order_no'].'/spontan/'.$id,'spontaneous-'.$id.'-created',true);
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Spontaner Nachweis angefordert: '.$instructions.' · '.$count.' Foto(s) · Frist '.$due->format('d.m.Y H:i')]);
    flash('success','Spontane Nachweisanforderung erstellt.');redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/auftrag/(\d{8})/spontan/(\d+)$#',$path,$m) && $method==='GET') {
    $s=require_seller();$o=v1_order_for_seller($m[1],(int)$s['id']);if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM spontaneous_requests WHERE id=? AND order_id=?");$q->execute([(int)$m[2],$o['id']]);$r=$q->fetch();if(!$r)not_found();
    $q=db()->prepare("SELECT * FROM evidences WHERE source_type='spontaneous' AND source_id=? ORDER BY created_at");$q->execute([$r['id']]);$files=$q->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Spontaner Nachweis</div><h1><?=e($o['title'])?></h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div>
    <div class="panel"><p><?=nl2br(e($r['instructions']))?></p><p class="meta">Benötigt: <?=e($r['required_count'])?> · Frist <?=e(date('d.m.Y H:i',strtotime($r['due_at'])))?> · Nachfrist bis <?=e(date('d.m.Y H:i',strtotime($r['grace_ends_at'])))?></p><p><strong><?=count($files)?> / <?=e($r['required_count'])?></strong> Nachweise eingereicht</p></div>
    <?php if(count($files)<(int)$r['required_count'] && strtotime($r['grace_ends_at'])>=time()):?><form class="panel" method="post" enctype="multipart/form-data"><?=csrf_field()?><label>Live-Aufnahme<input data-camera-input type="file" name="evidence" required></label><button class="btn">Nachweis einreichen</button></form><?php endif;?>
    <?php render('Spontaner Nachweis',ob_get_clean());exit;
}

if (preg_match('#^/auftrag/(\d{8})/spontan/(\d+)$#',$path,$m) && $method==='POST') {
    $s=require_seller();$o=v1_order_for_seller($m[1],(int)$s['id']);if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM spontaneous_requests WHERE id=? AND order_id=?");$q->execute([(int)$m[2],$o['id']]);$r=$q->fetch();if(!$r)not_found();
    if(strtotime($r['grace_ends_at'])<time()){flash('error','Die Frist einschließlich Nachfrist ist abgelaufen.');redirect('/auftrag/'.$o['order_no'].'/spontan/'.$r['id']);}
    try{
        $up=private_upload($_FILES['evidence']??[],'order-'.$o['id']);
        db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,source_type,source_id,file_path,mime_type,file_size,sha256) VALUES(?,?,?,'spontaneous','spontaneous',?,?,?,?,?)")
            ->execute([$o['id'],current_run_id((int)$o['id']),$s['id'],$r['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);
        $cnt=db()->prepare("SELECT COUNT(*) FROM evidences WHERE source_type='spontaneous' AND source_id=?");$cnt->execute([$r['id']]);$n=(int)$cnt->fetchColumn();
        if($n>=(int)$r['required_count']) db()->prepare("UPDATE spontaneous_requests SET status='uploaded' WHERE id=?")->execute([$r['id']]);
        flash('success','Spontaner Nachweis gespeichert ('.$n.'/'.$r['required_count'].').');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('/auftrag/'.$o['order_no'].'/spontan/'.$r['id']);
}

if ($path==='/admin/aufgabenbibliothek' && $method==='GET') {
    require_admin();$rows=db()->query("SELECT * FROM task_library ORDER BY active DESC,title")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Aufgabenbibliothek</h1></div></div>
    <form class="panel" method="post"><?=csrf_field()?><div class="form-grid"><label>Titel<input name="title" required></label><label>Standardvergütung (€)<input type="number" step=".01" min="0" name="compensation" value="0"></label></div><label>Beschreibung<textarea name="description"></textarea></label><label>Antworttyp<select name="response_type"><option value="text">Freitext</option><option value="number">Zahl</option><option value="scale10">Skala 1–10</option><option value="boolean">Ja/Nein</option></select></label><label><input type="checkbox" name="violation_enabled" value="1" checked style="width:auto"> Nichterfüllung kann Verstoß auslösen</label><button class="btn">Vorlage speichern</button></form>
    <h2>Vorlagen</h2><div class="table-wrap"><table><thead><tr><th>Titel</th><th>Vergütung</th><th>Verstoß</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['title'])?></td><td><?=money($r['default_compensation'])?></td><td><?=$r['violation_enabled']?'Ja':'Nein'?></td><td><?=$r['active']?'Aktiv':'Inaktiv'?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Aufgabenbibliothek',ob_get_clean());exit;
}

if ($path==='/admin/aufgabenbibliothek' && $method==='POST') {
    require_admin();$fields=json_encode(['response_type'=>post('response_type','text')],JSON_UNESCAPED_UNICODE);
    db()->prepare("INSERT INTO task_library(title,description,fields_json,default_compensation,violation_enabled) VALUES(?,?,?,?,?)")
        ->execute([post('title'),post('description'),$fields,(float)post('compensation'),isset($_POST['violation_enabled'])?1:0]);
    flash('success','Aufgabenvorlage gespeichert.');redirect('/admin/aufgabenbibliothek');
}

if (preg_match('#^/admin/auftrag/(\d{8})/aufgabe$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $fields=json_encode(['response_type'=>post('response_type','text')],JSON_UNESCAPED_UNICODE);
    $comp=max(0,(float)post('compensation','0'));
    db()->prepare("INSERT INTO order_tasks(order_id,title,description,due_at,fields_json,compensation,violation_enabled) VALUES(?,?,?,?,?,?,?)")
      ->execute([$o['id'],post('title'),post('description'),post('due_at')?:null,$fields,$comp,isset($_POST['violation_enabled'])?1:0]);
    $tid=(int)db()->lastInsertId();
    if($comp>0){
      db()->prepare("UPDATE orders SET total_compensation=total_compensation+?,updated_at=NOW() WHERE id=?")->execute([$comp,$o['id']]);
      db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Vergütung Zusatzaufgabe')")->execute([$o['seller_id'],$o['id'],$comp]);
    }
    notify_seller((int)$o['seller_id'],'task.created','Neue Zusatzaufgabe',post('title').(post('due_at')?' · Frist '.post('due_at'):''),'/auftrag/'.$o['order_no'].'/aufgabe/'.$tid,null,true);
    flash('success','Zusatzaufgabe wurde angelegt.');redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/admin/auftrag/(\d{8})/aufgabe-aus-vorlage$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM task_library WHERE id=? AND active=1");$q->execute([(int)post('template_id')]);$t=$q->fetch();if(!$t){flash('error','Vorlage nicht gefunden.');redirect('/admin/auftrag/'.$o['order_no']);}
    $comp=(float)$t['default_compensation'];
    db()->prepare("INSERT INTO order_tasks(order_id,title,description,due_at,fields_json,compensation,violation_enabled) VALUES(?,?,?,?,?,?,?)")
        ->execute([$o['id'],$t['title'],$t['description'],post('due_at')?:null,$t['fields_json'],$comp,$t['violation_enabled']]);
    $tid=(int)db()->lastInsertId();
    if($comp>0){db()->prepare("UPDATE orders SET total_compensation=total_compensation+? WHERE id=?")->execute([$comp,$o['id']]);db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Vergütung Zusatzaufgabe')")->execute([$o['seller_id'],$o['id'],$comp]);}
    notify_seller((int)$o['seller_id'],'task.created','Neue Zusatzaufgabe',$t['title'],'/auftrag/'.$o['order_no'].'/aufgabe/'.$tid,null,true);
    flash('success','Aufgabe aus Bibliothek hinzugefügt.');redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/auftrag/(\d{8})/aufgabe/(\d+)$#',$path,$m) && $method==='GET') {
    $s=require_seller();$o=v1_order_for_seller($m[1],(int)$s['id']);if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM order_tasks WHERE id=? AND order_id=?");$q->execute([(int)$m[2],$o['id']]);$t=$q->fetch();if(!$t)not_found();
    $cfg=json_decode($t['fields_json']?:'{}',true)?:[];$type=$cfg['response_type']??'text';
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Zusatzaufgabe</div><h1><?=e($t['title'])?></h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div><div class="panel"><p><?=nl2br(e($t['description']??''))?></p><p class="meta"><?=e($t['due_at']?'Fällig '.date('d.m.Y H:i',strtotime($t['due_at'])):'Keine feste Frist')?> · Vergütung <?=money($t['compensation'])?></p></div>
    <?php if(in_array($t['status'],['open','rejected'],true)):?><form class="panel" method="post" enctype="multipart/form-data"><?=csrf_field()?>
    <?php if($type==='number'):?><label>Wert<input type="number" step=".01" name="value" required></label>
    <?php elseif($type==='scale10'):?><label>Bewertung 1–10<input type="number" min="1" max="10" name="value" required></label>
    <?php elseif($type==='boolean'):?><label>Antwort<select name="value"><option value="ja">Ja</option><option value="nein">Nein</option></select></label>
    <?php else:?><label>Antwort<textarea name="value" required></textarea></label><?php endif;?>
    <label>Optionaler Fotobeleg<input data-camera-input type="file" name="evidence"></label><button class="btn">Aufgabe einreichen</button></form><?php else:?><div class="panel"><strong>Status: <?=e($t['status'])?></strong></div><?php endif;?>
    <?php render('Zusatzaufgabe',ob_get_clean());exit;
}

if (preg_match('#^/auftrag/(\d{8})/aufgabe/(\d+)$#',$path,$m) && $method==='POST') {
    $s=require_seller();$o=v1_order_for_seller($m[1],(int)$s['id']);if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM order_tasks WHERE id=? AND order_id=?");$q->execute([(int)$m[2],$o['id']]);$t=$q->fetch();if(!$t)not_found();
    $submission=['value'=>post('value'),'submitted_at'=>date(DATE_ATOM)];
    db()->prepare("UPDATE order_tasks SET submission_json=?,status='submitted',submitted_at=NOW() WHERE id=?")->execute([json_encode($submission,JSON_UNESCAPED_UNICODE),$t['id']]);
    if(isset($_FILES['evidence'])&&($_FILES['evidence']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
        $up=private_upload($_FILES['evidence'],'order-'.$o['id']);
        db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,source_type,source_id,file_path,mime_type,file_size,sha256) VALUES(?,?,?,'task','task',?,?,?,?,?)")
          ->execute([$o['id'],current_run_id((int)$o['id']),$s['id'],$t['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);
    }
    notify_seller((int)$s['id'],'task.submitted','Aufgabe eingereicht','Die Zusatzaufgabe „'.$t['title'].'“ wurde eingereicht.','/auftrag/'.$o['order_no']);
    flash('success','Aufgabe wurde eingereicht.');redirect('/auftrag/'.$o['order_no']);
}

if ($path==='/admin/suche' && $method==='GET') {
    require_admin();$q=trim((string)($_GET['q']??''));$sellers=[];$orders=[];$offers=[];$payouts=[];
    if($q!==''){
        $like='%'.$q.'%';
        $st=db()->prepare("SELECT id,first_name,last_name,email,phone FROM sellers WHERE deleted_at IS NULL AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?) LIMIT 25");$st->execute([$like,$like,$like,$like]);$sellers=$st->fetchAll();
        $st=db()->prepare("SELECT o.order_no,o.status,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no LIKE ? LIMIT 25");$st->execute([$like]);$orders=$st->fetchAll();
        $st=db()->prepare("SELECT id,title,status FROM offers WHERE title LIKE ? LIMIT 25");$st->execute([$like]);$offers=$st->fetchAll();
        $st=db()->prepare("SELECT p.id,p.status,p.amount,s.email FROM payout_requests p JOIN sellers s ON s.id=p.seller_id WHERE CAST(p.id AS CHAR) LIKE ? OR s.email LIKE ? LIMIT 25");$st->execute([$like,$like]);$payouts=$st->fetchAll();
    }
    ob_start();?><div class="eyebrow">Administration</div><h1>Globale Suche</h1><form class="panel" method="get"><input name="q" value="<?=e($q)?>" placeholder="Auftragsnummer, Name, E-Mail, Telefon, Angebot, Auszahlung"><button class="btn">Suchen</button></form>
    <?php if($q!==''):?><div class="grid two" style="margin-top:18px"><section class="panel"><h2>Verkäuferinnen</h2><?php foreach($sellers as $x):?><div><a href="<?=e(url('/admin/verkaeuferin/'.$x['id']))?>"><?=e($x['first_name'].' '.$x['last_name'])?></a> · <?=e($x['email'])?></div><?php endforeach;?></section><section class="panel"><h2>Aufträge</h2><?php foreach($orders as $x):?><div><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>"><?=e($x['order_no'].' · '.$x['title'])?></a> · <?=e($x['status'])?></div><?php endforeach;?></section><section class="panel"><h2>Angebote</h2><?php foreach($offers as $x):?><div><a href="<?=e(url('/admin/angebot/'.$x['id']))?>"><?=e($x['title'])?></a> · <?=e($x['status'])?></div><?php endforeach;?></section><section class="panel"><h2>Auszahlungen</h2><?php foreach($payouts as $x):?><div>#<?=e($x['id'])?> · <?=e($x['email'])?> · <?=money($x['amount'])?> · <?=e($x['status'])?></div><?php endforeach;?></section></div><?php endif;?>
    <?php render('Globale Suche',ob_get_clean());exit;
}

if ($path==='/admin/einzelangebote' && $method==='GET') {
    require_admin();$sellers=db()->query("SELECT id,first_name,last_name,email FROM sellers WHERE deleted_at IS NULL ORDER BY last_name,first_name")->fetchAll();$cats=db()->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order,name")->fetchAll();
    $rows=db()->query("SELECT a.*,o.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM offer_assignments a JOIN offers o ON o.id=a.offer_id JOIN sellers s ON s.id=a.seller_id ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
    ob_start();?><div class="eyebrow">Administration</div><h1>Individuelle Angebote</h1><form class="panel" method="post"><?=csrf_field()?><div class="form-grid"><label>Verkäuferin<select name="seller_id"><?php foreach($sellers as $s):?><option value="<?=$s['id']?>"><?=e($s['last_name'].', '.$s['first_name'].' · '.$s['email'])?></option><?php endforeach;?></select></label><label>Kategorie<select name="category_id"><?php foreach($cats as $cat):?><option value="<?=$cat['id']?>"><?=e($cat['name'])?></option><?php endforeach;?></select></label><label>Titel<input name="title" required></label><label>Vergütung (€)<input type="number" step=".01" min="0" name="compensation" required></label><label>Dauer Tage<input type="number" min="1" name="duration_days"></label><label>Annahmefrist<input type="datetime-local" name="deadline" required></label></div><label>Beschreibung<textarea name="description" required></textarea></label><button class="btn">Individuelles Angebot senden</button></form>
    <h2>Gesendet</h2><div class="table-wrap"><table><thead><tr><th>Verkäuferin</th><th>Angebot</th><th>Frist</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['seller_name'])?></td><td><?=e($r['title'])?></td><td><?=e(date('d.m.Y H:i',strtotime($r['acceptance_deadline'])))?></td><td><?=e($r['status'])?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Individuelle Angebote',ob_get_clean());exit;
}

if ($path==='/admin/einzelangebote' && $method==='POST') {
    require_admin();$sellerId=(int)post('seller_id');$deadline=post('deadline');
    $slug='privat-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(4)),0,8);
    $days=post('duration_days')!==''?(int)post('duration_days'):null;
    db()->beginTransaction();try{
        db()->prepare("INSERT INTO offers(category_id,title,slug,description,compensation,duration_days,fulfillment_type,status,visibility,current_version) VALUES(?,?,?,?,?,?,?,'active','private',1)")
            ->execute([(int)post('category_id'),post('title'),$slug,post('description'),(float)post('compensation'),$days,$days?'days':'one_time']);
        $offerId=(int)db()->lastInsertId();
        $snap=json_encode(['title'=>post('title'),'description'=>post('description'),'compensation'=>(float)post('compensation'),'duration_days'=>$days,'visibility'=>'private'],JSON_UNESCAPED_UNICODE);
        db()->prepare("INSERT INTO offer_versions(offer_id,version_no,snapshot_json) VALUES(?,1,?)")->execute([$offerId,$snap]);
        db()->prepare("INSERT INTO offer_assignments(offer_id,seller_id,acceptance_deadline) VALUES(?,?,?)")->execute([$offerId,$sellerId,$deadline]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    notify_seller($sellerId,'private_offer','Individuelles Angebot','Dir wurde ein individuelles Angebot zugewiesen.','/individuelle-angebote',null,true);
    flash('success','Individuelles Angebot wurde zugewiesen.');redirect('/admin/einzelangebote');
}

if ($path==='/individuelle-angebote' && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT a.*,o.title,o.description,o.compensation,o.duration_days FROM offer_assignments a JOIN offers o ON o.id=a.offer_id WHERE a.seller_id=? ORDER BY a.created_at DESC");$q->execute([$s['id']]);$rows=$q->fetchAll();
    ob_start();?><div class="eyebrow">Persönlich zugewiesen</div><h1>Individuelle Angebote</h1><div class="grid"><?php foreach($rows as $r):?><article class="card"><span class="badge"><?=e($r['status'])?></span><h3><?=e($r['title'])?></h3><p><?=e($r['description'])?></p><div class="price"><?=money($r['compensation'])?></div><p class="meta">Annahmefrist <?=e(date('d.m.Y H:i',strtotime($r['acceptance_deadline'])))?></p><?php if($r['status']==='assigned'&&strtotime($r['acceptance_deadline'])>time()):?><div class="actions"><form method="post" action="<?=e(url('/individuelle-angebote/'.$r['id'].'/annehmen'))?>"><?=csrf_field()?><button class="btn">Annehmen</button></form><form method="post" action="<?=e(url('/individuelle-angebote/'.$r['id'].'/ablehnen'))?>"><?=csrf_field()?><input name="reason" placeholder="Ablehnungsgrund" required><button class="btn secondary">Ablehnen</button></form></div><?php endif;?></article><?php endforeach;?><?php if(!$rows):?><div class="empty">Keine individuellen Angebote vorhanden.</div><?php endif;?></div><?php render('Individuelle Angebote',ob_get_clean());exit;
}

if (preg_match('#^/individuelle-angebote/(\d+)/annehmen$#',$path,$m) && $method==='POST') {
    $s=require_seller();if(!$s['email_verified_at']){flash('error','Bitte bestätige zuerst deine E-Mail-Adresse.');redirect('/individuelle-angebote');}
    $q=db()->prepare("SELECT o.*,a.id assignment_id,a.acceptance_deadline,a.status assignment_status FROM offer_assignments a JOIN offers o ON o.id=a.offer_id WHERE a.id=? AND a.seller_id=? AND a.status='assigned'");$q->execute([(int)$m[1],$s['id']]);$a=$q->fetch();if(!$a||strtotime($a['acceptance_deadline'])<time()){flash('error','Das Angebot ist nicht mehr verfügbar.');redirect('/individuelle-angebote');}
    $dupe=db()->prepare("SELECT COUNT(*) FROM orders x JOIN offers ox ON ox.id=x.offer_id WHERE x.seller_id=? AND ox.category_id=? AND x.status IN('precheck','running','shipping','review','payout')");$dupe->execute([$s['id'],$a['category_id']]);if((int)$dupe->fetchColumn()>0){flash('error','In dieser Kategorie besteht bereits ein aktiver Auftrag.');redirect('/individuelle-angebote');}
    $no=v1_create_order_from_offer($a,$s,[]);
    db()->prepare("UPDATE offer_assignments SET status='accepted',updated_at=NOW() WHERE id=?")->execute([$a['assignment_id']]);
    flash('success','Individuelles Angebot angenommen. Auftrag '.$no.' wurde erstellt.');redirect('/auftrag/'.$no);
}

if (preg_match('#^/individuelle-angebote/(\d+)/ablehnen$#',$path,$m) && $method==='POST') {
    $s=require_seller();$reason=post('reason');if($reason===''){flash('error','Bitte Ablehnungsgrund angeben.');redirect('/individuelle-angebote');}
    db()->prepare("UPDATE offer_assignments SET status='declined',decline_reason=?,updated_at=NOW() WHERE id=? AND seller_id=? AND status='assigned'")->execute([$reason,(int)$m[1],$s['id']]);
    flash('success','Individuelles Angebot wurde abgelehnt.');redirect('/individuelle-angebote');
}


if (preg_match('#^/admin/verstoss/(\d+)/(bestaetigen|verwerfen)$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT v.*,o.order_no,o.seller_id FROM violations v JOIN orders o ON o.id=v.order_id WHERE v.id=?");
    $q->execute([(int)$m[1]]);$v=$q->fetch();if(!$v)not_found();
    if(!in_array($v['status'],['open','reviewed'],true)){flash('error','Dieser Verstoß ist bereits abschließend bearbeitet.');redirect('/admin/auftrag/'.$v['order_no']);}

    if($m[2]==='bestaetigen'){
        db()->beginTransaction();
        try{
            db()->prepare("UPDATE violations SET status='confirmed',reviewed_at=NOW() WHERE id=?")->execute([$v['id']]);
            db()->prepare("UPDATE extra_days SET status='confirmed' WHERE source_type='violation' AND source_id=? AND status='provisional'")->execute([$v['id']]);
            db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$v['order_id'],'Verstoß bestätigt: '.$v['reason'].' · +1 zusätzlicher Durchführungstag.']);
            db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        notify_seller((int)$v['seller_id'],'violation.confirmed','Verstoß bestätigt',$v['reason'].' · Der vorläufige Zusatztag ist jetzt verbindlich.','/auftrag/'.$v['order_no'],null,true);
        flash('success','Verstoß bestätigt. Der provisorische Zusatztag ist jetzt verbindlich.');
    }else{
        db()->beginTransaction();
        try{
            db()->prepare("UPDATE violations SET status='discarded',reviewed_at=NOW() WHERE id=?")->execute([$v['id']]);
            db()->prepare("UPDATE extra_days SET status='cancelled' WHERE source_type='violation' AND source_id=? AND status='provisional'")->execute([$v['id']]);
            db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$v['order_id'],'Möglicher Verstoß wurde verworfen: '.$v['reason'].'. Der provisorische Zusatztag entfällt.']);
            db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        notify_seller((int)$v['seller_id'],'violation.discarded','Möglicher Verstoß verworfen',$v['reason'].' · Der provisorische Zusatztag wurde entfernt.','/auftrag/'.$v['order_no'],null,true);
        flash('success','Verstoß verworfen. Provisorischer Zusatztag wurde entfernt.');
    }
    redirect('/admin/auftrag/'.$v['order_no']);
}

if (preg_match('#^/admin/aufgabe/(\d+)/(akzeptieren|ablehnen)$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT t.*,o.order_no,o.seller_id FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE t.id=?");$q->execute([(int)$m[1]]);$t=$q->fetch();if(!$t)not_found();
    if($m[2]==='akzeptieren'){
        db()->prepare("UPDATE order_tasks SET status='accepted' WHERE id=?")->execute([$t['id']]);
        notify_seller((int)$t['seller_id'],'task.accepted','Zusatzaufgabe akzeptiert','Die Aufgabe „'.$t['title'].'“ wurde akzeptiert.','/auftrag/'.$t['order_no'],null,true);
        flash('success','Aufgabe akzeptiert.');
    }else{
        db()->prepare("UPDATE order_tasks SET status='rejected' WHERE id=?")->execute([$t['id']]);
        notify_seller((int)$t['seller_id'],'task.rejected','Zusatzaufgabe beanstandet','Die Aufgabe „'.$t['title'].'“ wurde beanstandet und kann erneut eingereicht werden.','/auftrag/'.$t['order_no'].'/aufgabe/'.$t['id'],null,true);
        flash('success','Aufgabe zur erneuten Bearbeitung zurückgegeben.');
    }
    redirect('/admin/auftrag/'.$t['order_no']);
}

if (preg_match('#^/admin/spontan/(\d+)/abschliessen$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT r.*,o.order_no,o.seller_id FROM spontaneous_requests r JOIN orders o ON o.id=r.order_id WHERE r.id=?");$q->execute([(int)$m[1]]);$r=$q->fetch();if(!$r)not_found();
    $q=db()->prepare("SELECT COUNT(*) FROM evidences WHERE source_type='spontaneous' AND source_id=? AND status='accepted'");$q->execute([$r['id']]);
    if((int)$q->fetchColumn()<(int)$r['required_count']){flash('error','Noch nicht alle erforderlichen Bilder wurden akzeptiert.');redirect('/admin/auftrag/'.$r['order_no']);}
    db()->prepare("UPDATE spontaneous_requests SET status='reviewed' WHERE id=?")->execute([$r['id']]);
    notify_seller((int)$r['seller_id'],'spontaneous.reviewed','Spontaner Nachweis geprüft','Die spontane Nachweisanforderung wurde vollständig geprüft.','/auftrag/'.$r['order_no'],null,true);
    flash('success','Spontane Nachweisanforderung abgeschlossen.');redirect('/admin/auftrag/'.$r['order_no']);
}

if ($path==='/admin/kalender' && $method==='GET') {
    require_admin();
    $from=trim((string)($_GET['from']??date('Y-m-01')));
    $to=trim((string)($_GET['to']??date('Y-m-t')));
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=date('Y-m-01');
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=date('Y-m-t');
    $events=[];

    $q=db()->prepare("SELECT ew.starts_at event_at,'Nachweisfenster' event_type,o.order_no,f.title,ew.window_key details FROM evidence_windows ew JOIN orders o ON o.id=ew.order_id JOIN offers f ON f.id=o.offer_id WHERE DATE(ew.starts_at) BETWEEN ? AND ?");
    $q->execute([$from,$to]);$events=array_merge($events,$q->fetchAll());

    $q=db()->prepare("SELECT sr.due_at event_at,'Spontaner Nachweis' event_type,o.order_no,f.title,sr.instructions details FROM spontaneous_requests sr JOIN orders o ON o.id=sr.order_id JOIN offers f ON f.id=o.offer_id WHERE DATE(sr.due_at) BETWEEN ? AND ?");
    $q->execute([$from,$to]);$events=array_merge($events,$q->fetchAll());

    $q=db()->prepare("SELECT t.due_at event_at,'Zusatzaufgabe' event_type,o.order_no,f.title,t.title details FROM order_tasks t JOIN orders o ON o.id=t.order_id JOIN offers f ON f.id=o.offer_id WHERE t.due_at IS NOT NULL AND DATE(t.due_at) BETWEEN ? AND ?");
    $q->execute([$from,$to]);$events=array_merge($events,$q->fetchAll());

    $q=db()->prepare("SELECT r.due_at event_at,'Revision' event_type,o.order_no,f.title,CONCAT('Runde ',r.round_no) details FROM revision_rounds r JOIN orders o ON o.id=r.order_id JOIN offers f ON f.id=o.offer_id WHERE r.due_at IS NOT NULL AND DATE(r.due_at) BETWEEN ? AND ?");
    $q->execute([$from,$to]);$events=array_merge($events,$q->fetchAll());

    usort($events,fn($a,$b)=>strcmp((string)$a['event_at'],(string)$b['event_at']));
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Kalender & Fristen</h1></div></div><form class="panel form-grid" method="get"><label>Von<input type="date" name="from" value="<?=e($from)?>"></label><label>Bis<input type="date" name="to" value="<?=e($to)?>"></label><button class="btn">Zeitraum anzeigen</button></form><br>
    <div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Typ</th><th>Auftrag</th><th>Details</th></tr></thead><tbody><?php foreach($events as $ev):?><tr><td><?=e(date('d.m.Y H:i',strtotime($ev['event_at'])))?></td><td><?=e($ev['event_type'])?></td><td><a href="<?=e(url('/admin/auftrag/'.$ev['order_no']))?>"><?=e($ev['order_no'].' · '.$ev['title'])?></a></td><td><?=e($ev['details'])?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Kalender',ob_get_clean());exit;
}

if ($path==='/admin/heute' && $method==='GET') {
    require_admin();
    $openViolations=db()->query("SELECT v.*,o.order_no,f.title FROM violations v JOIN orders o ON o.id=v.order_id JOIN offers f ON f.id=o.offer_id WHERE v.status IN('open','reviewed') ORDER BY v.created_at")->fetchAll();
    $prechecks=db()->query("SELECT DISTINCT o.order_no,f.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id JOIN evidences e ON e.order_id=o.id WHERE o.status='precheck' AND e.evidence_type='precheck' AND e.status='submitted' ORDER BY o.created_at")->fetchAll();
    $damage=db()->query("SELECT d.*,o.order_no,f.title FROM damage_cases d JOIN orders o ON o.id=d.order_id JOIN offers f ON f.id=o.offer_id WHERE d.status IN('reported','evidence_requested','review') ORDER BY d.created_at")->fetchAll();
    $payouts=db()->query("SELECT p.*,CONCAT(s.first_name,' ',s.last_name) seller_name FROM payout_requests p JOIN sellers s ON s.id=p.seller_id WHERE p.status IN('requested','review','released') ORDER BY p.created_at")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Heute – Arbeitsliste</h1></div><div class="actions"><a class="btn secondary" href="<?=e(url('/admin/kalender'))?>">Kalender</a><a class="btn secondary" href="<?=e(url('/admin/suche'))?>">Suche</a></div></div>
    <div class="grid two"><section class="panel"><h2>Sofort bearbeiten · Vorabkontrollen</h2><?php foreach($prechecks as $x):?><div><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>"><?=e($x['order_no'].' · '.$x['title'])?></a> · <?=e($x['seller_name'])?></div><?php endforeach;?><?php if(!$prechecks):?><p class="meta">Keine offenen Vorabkontrollen.</p><?php endif;?></section>
    <section class="panel"><h2>Offene Verstöße</h2><?php foreach($openViolations as $x):?><div><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>"><?=e($x['order_no'])?></a> · <?=e($x['reason'])?></div><?php endforeach;?><?php if(!$openViolations):?><p class="meta">Keine offenen Verstöße.</p><?php endif;?></section>
    <section class="panel"><h2>Beschädigungen</h2><?php foreach($damage as $x):?><div><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>"><?=e($x['order_no'].' · '.$x['title'])?></a> · <?=e($x['status'])?></div><?php endforeach;?><?php if(!$damage):?><p class="meta">Keine offenen Beschädigungsvorgänge.</p><?php endif;?></section>
    <section class="panel"><h2>Auszahlungen</h2><?php foreach($payouts as $x):?><div><?=e($x['seller_name'])?> · <?=money($x['amount'])?> · <?=e($x['status'])?></div><?php endforeach;?><?php if(!$payouts):?><p class="meta">Keine offenen Auszahlungen.</p><?php endif;?></section></div>
    <?php render('Admin Heute',ob_get_clean());exit;
}

<?php
declare(strict_types=1);

/**
 * Operational V1 routes that are intentionally separate from FeatureRoutes.
 * Keep each URL here unique to avoid ambiguous routing.
 */

if ($path==='/admin/kalender' && $method==='GET') {
    require_admin();
    $from=trim((string)($_GET['from']??date('Y-m-01')));
    $to=trim((string)($_GET['to']??date('Y-m-t')));
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)) $from=date('Y-m-01');
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) $to=date('Y-m-t');

    $events=[];
    $queries=[
      ["SELECT ew.starts_at event_at,'Nachweisfenster' event_type,o.order_no,f.title,ew.window_key details FROM evidence_windows ew JOIN orders o ON o.id=ew.order_id JOIN offers f ON f.id=o.offer_id WHERE DATE(ew.starts_at) BETWEEN ? AND ?",[$from,$to]],
      ["SELECT sr.due_at event_at,'Spontaner Nachweis' event_type,o.order_no,f.title,sr.instructions details FROM spontaneous_requests sr JOIN orders o ON o.id=sr.order_id JOIN offers f ON f.id=o.offer_id WHERE DATE(sr.due_at) BETWEEN ? AND ?",[$from,$to]],
      ["SELECT t.due_at event_at,'Zusatzaufgabe' event_type,o.order_no,f.title,t.title details FROM order_tasks t JOIN orders o ON o.id=t.order_id JOIN offers f ON f.id=o.offer_id WHERE t.due_at IS NOT NULL AND DATE(t.due_at) BETWEEN ? AND ?",[$from,$to]],
      ["SELECT r.due_at event_at,'Revision' event_type,o.order_no,f.title,CONCAT('Runde ',r.round_no) details FROM revision_rounds r JOIN orders o ON o.id=r.order_id JOIN offers f ON f.id=o.offer_id WHERE r.due_at IS NOT NULL AND DATE(r.due_at) BETWEEN ? AND ?",[$from,$to]],
      ["SELECT a.acceptance_deadline event_at,'Einzelangebot' event_type,'' order_no,o.title,'Annahmefrist' details FROM offer_assignments a JOIN offers o ON o.id=a.offer_id WHERE DATE(a.acceptance_deadline) BETWEEN ? AND ?",[$from,$to]],
    ];
    foreach($queries as [$sql,$args]){$q=db()->prepare($sql);$q->execute($args);$events=array_merge($events,$q->fetchAll());}
    usort($events,fn($a,$b)=>strcmp((string)$a['event_at'],(string)$b['event_at']));

    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Kalender & Fristen</h1></div></div>
    <form class="panel form-grid" method="get"><label>Von<input type="date" name="from" value="<?=e($from)?>"></label><label>Bis<input type="date" name="to" value="<?=e($to)?>"></label><button class="btn">Zeitraum anzeigen</button></form><br>
    <div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Typ</th><th>Auftrag</th><th>Details</th></tr></thead><tbody><?php foreach($events as $ev):?><tr><td><?=e(date('d.m.Y H:i',strtotime($ev['event_at'])))?></td><td><?=e($ev['event_type'])?></td><td><?php if($ev['order_no']):?><a href="<?=e(url('/admin/auftrag/'.$ev['order_no']))?>"><?=e($ev['order_no'].' · '.$ev['title'])?></a><?php else:?><?=e($ev['title'])?><?php endif;?></td><td><?=e($ev['details'])?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Kalender',ob_get_clean());exit;
}

if ($path==='/admin/heute' && $method==='GET') {
    require_admin();
    $openViolations=db()->query("SELECT v.*,o.order_no,f.title FROM violations v JOIN orders o ON o.id=v.order_id JOIN offers f ON f.id=o.offer_id WHERE v.status IN('open','reviewed') ORDER BY v.created_at")->fetchAll();
    $prechecks=db()->query("SELECT DISTINCT o.order_no,f.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id JOIN evidences e ON e.order_id=o.id WHERE o.status='precheck' AND e.evidence_type='precheck' AND e.status='submitted' ORDER BY o.created_at")->fetchAll();
    $damage=db()->query("SELECT d.*,o.order_no,f.title FROM damage_cases d JOIN orders o ON o.id=d.order_id JOIN offers f ON f.id=o.offer_id WHERE d.status IN('reported','evidence_requested','review') ORDER BY d.created_at")->fetchAll();
    $payouts=db()->query("SELECT p.*,CONCAT(s.first_name,' ',s.last_name) seller_name FROM payout_requests p JOIN sellers s ON s.id=p.seller_id WHERE p.status IN('requested','review','released') ORDER BY p.created_at")->fetchAll();
    $tasks=db()->query("SELECT t.*,o.order_no FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE t.status='submitted' ORDER BY t.submitted_at")->fetchAll();

    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Heute – Arbeitsliste</h1></div><div class="actions"><a class="btn secondary" href="<?=e(url('/admin/kalender'))?>">Kalender</a><a class="btn secondary" href="<?=e(url('/admin/suche'))?>">Suche</a></div></div>
    <div class="grid two">
      <section class="panel"><h2>Sofort bearbeiten · Vorabkontrollen</h2><?php foreach($prechecks as $x):?><div><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>"><?=e($x['order_no'].' · '.$x['title'])?></a> · <?=e($x['seller_name'])?></div><?php endforeach;?><?php if(!$prechecks):?><p class="meta">Keine offenen Vorabkontrollen.</p><?php endif;?></section>
      <section class="panel"><h2>Offene Verstöße</h2><?php foreach($openViolations as $x):?><div><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>"><?=e($x['order_no'])?></a> · <?=e($x['reason'])?></div><?php endforeach;?><?php if(!$openViolations):?><p class="meta">Keine offenen Verstöße.</p><?php endif;?></section>
      <section class="panel"><h2>Beschädigungen</h2><?php foreach($damage as $x):?><div><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>"><?=e($x['order_no'].' · '.$x['title'])?></a> · <?=e($x['status'])?></div><?php endforeach;?><?php if(!$damage):?><p class="meta">Keine offenen Beschädigungsvorgänge.</p><?php endif;?></section>
      <section class="panel"><h2>Eingereichte Aufgaben</h2><?php foreach($tasks as $x):?><div><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>"><?=e($x['order_no'].' · '.$x['title'])?></a></div><?php endforeach;?><?php if(!$tasks):?><p class="meta">Keine Aufgaben zur Prüfung.</p><?php endif;?></section>
      <section class="panel"><h2>Auszahlungen</h2><?php foreach($payouts as $x):?><div><?=e($x['seller_name'])?> · <?=money($x['amount'])?> · <?=e($x['status'])?></div><?php endforeach;?><?php if(!$payouts):?><p class="meta">Keine offenen Auszahlungen.</p><?php endif;?></section>
    </div>
    <?php render('Admin Heute',ob_get_clean());exit;
}

if ($path==='/archiv' && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? AND o.archived_at IS NOT NULL ORDER BY o.archived_at DESC");
    $q->execute([$s['id']]);$rows=$q->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Historie</div><h1>Archiv</h1><p class="meta">Archivierte Aufträge sind schreibgeschützt.</p></div><a class="btn secondary" href="<?=e(url('/dashboard'))?>">Dashboard</a></div>
    <div class="table-wrap"><table><thead><tr><th>Nr.</th><th>Auftrag</th><th>Status</th><th>Freigegeben</th><th>Archiviert</th><th></th></tr></thead><tbody><?php foreach($rows as $o):?><tr><td><?=e($o['order_no'])?></td><td><?=e($o['title'])?></td><td><?=e($o['status'])?></td><td><?=money($o['released_amount']??0)?></td><td><?=e(date('d.m.Y H:i',strtotime($o['archived_at'])))?></td><td><a href="<?=e(url('/auftrag/'.$o['order_no']))?>">Ansehen</a></td></tr><?php endforeach;?></tbody></table></div>
    <?php if(!$rows):?><div class="empty">Noch keine archivierten Aufträge.</div><?php endif;?><?php render('Archiv',ob_get_clean());exit;
}

if ($path==='/admin/archiv' && $method==='GET') {
    require_admin();
    $rows=db()->query("SELECT o.*,f.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id WHERE o.archived_at IS NOT NULL ORDER BY o.archived_at DESC")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Auftragsarchiv</h1><p class="meta">Archivierte Aufträge sind schreibgeschützt. Endgültig abgelehnte Aufträge können nicht wiederhergestellt werden.</p></div><a class="btn secondary" href="<?=e(url('/admin/auftraege'))?>">Aufträge</a></div>
    <div class="table-wrap"><table><thead><tr><th>Nr.</th><th>Verkäuferin</th><th>Auftrag</th><th>Status</th><th>Archiviert</th><th>Aktion</th></tr></thead><tbody><?php foreach($rows as $o):?><tr><td><?=e($o['order_no'])?></td><td><?=e($o['seller_name'])?></td><td><?=e($o['title'])?></td><td><?=e($o['status'])?></td><td><?=e(date('d.m.Y H:i',strtotime($o['archived_at'])))?></td><td><div class="actions"><a href="<?=e(url('/admin/auftrag/'.$o['order_no']))?>">Ansehen</a><?php if($o['status']!=='rejected'):?><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/wiederherstellen'))?>"><?=csrf_field()?><button class="btn secondary">Wiederherstellen</button></form><?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div>
    <?php if(!$rows):?><div class="empty">Archiv ist leer.</div><?php endif;?><?php render('Auftragsarchiv',ob_get_clean());exit;
}

if (preg_match('#^/admin/auftrag/(\d{8})/archivieren$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    if(!in_array($o['status'],['completed','rejected'],true)){flash('error','Nur abgeschlossene oder endgültig abgelehnte Aufträge können archiviert werden.');redirect('/admin/auftrag/'.$o['order_no']);}
    db()->prepare("UPDATE orders SET archived_at=COALESCE(archived_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$o['id']]);
    flash('success','Auftrag archiviert.');redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/admin/auftrag/(\d{8})/wiederherstellen$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']==='rejected'){flash('error','Endgültig abgelehnte Aufträge können nicht wieder geöffnet werden.');redirect('/admin/auftrag/'.$o['order_no']);}
    if($o['status']!=='completed'){flash('error','Nur abgeschlossene archivierte Aufträge können wiederhergestellt werden.');redirect('/admin/auftrag/'.$o['order_no']);}
    db()->prepare("UPDATE orders SET archived_at=NULL,updated_at=NOW() WHERE id=?")->execute([$o['id']]);
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Auftrag wurde durch den Admin aus dem Archiv wiederhergestellt.')")->execute([$o['id']]);
    flash('success','Auftrag wurde wiederhergestellt und kann wieder bearbeitet werden.');redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/admin/verkaeuferin/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();
    $q=db()->prepare("SELECT * FROM sellers WHERE id=?");$q->execute([(int)$m[1]]);$s=$q->fetch();if(!$s)not_found();
    $q=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? ORDER BY o.created_at DESC");$q->execute([$s['id']]);$orders=$q->fetchAll();
    $wb=db()->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='available' THEN amount WHEN entry_type='adjustment' THEN amount WHEN entry_type='paid' THEN -amount ELSE 0 END),0) FROM wallet_entries WHERE seller_id=?");$wb->execute([$s['id']]);$walletBalance=(float)$wb->fetchColumn();

    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Verkäuferinnenakte</div><h1><?=e($s['first_name'].' '.$s['last_name'])?></h1><p class="meta">Registriert <?=e(date('d.m.Y H:i',strtotime($s['created_at'])))?> · E-Mail <?=$s['email_verified_at']?'bestätigt':'offen'?><?=$s['deleted_at']?' · Konto anonymisiert':''?></p></div><a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen'))?>">Zur Übersicht</a></div>
    <div class="grid two">
      <form class="panel" method="post" action="<?=e(url('/admin/verkaeuferin/'.$s['id'].'/speichern'))?>"><?=csrf_field()?><h2>Stammdaten bearbeiten</h2>
        <div class="form-grid"><label>Vorname<input name="first_name" value="<?=e($s['first_name'])?>" required></label><label>Nachname<input name="last_name" value="<?=e($s['last_name'])?>" required></label><label>Geburtsdatum<input type="date" name="birth_date" value="<?=e($s['birth_date'])?>" required></label><label>E-Mail<input type="email" name="email" value="<?=e($s['email'])?>" required></label><label>Telefon<input name="phone" value="<?=e($s['phone'])?>" required></label><label>Straße<input name="street" value="<?=e($s['street'])?>" required></label><label>PLZ<input name="postal_code" value="<?=e($s['postal_code'])?>" required></label><label>Ort<input name="city" value="<?=e($s['city'])?>" required></label></div>
        <label><input type="checkbox" name="confirm_sensitive" value="1" style="width:auto"> Änderung von E-Mail oder Geburtsdatum zusätzlich bestätigen</label>
        <button class="btn" <?=$s['deleted_at']?'disabled':''?>>Änderungen speichern</button>
      </form>
      <section class="panel"><h2>Kontoverwaltung</h2><p>Bei einer Kontolöschung werden personenbezogene Profildaten und Auszahlungsdaten anonymisiert. Historische Auftrags-, Zahlungs- und Nachweisdaten bleiben erhalten.</p>
        <?php if(!$s['deleted_at']):?><form method="post" action="<?=e(url('/admin/verkaeuferin/'.$s['id'].'/loeschen'))?>" data-confirm="Verkäuferinnenkonto wirklich anonymisieren?"><?=csrf_field()?><button class="btn danger">Konto anonymisieren / löschen</button></form><?php else:?><span class="badge">ANONYMISIERT</span><?php endif;?>
        <hr><h3>Wallet-Korrektur</h3><p>Aktuell verfügbar: <strong><?=money($walletBalance)?></strong></p>
        <?php if(!$s['deleted_at']):?><form method="post" action="<?=e(url('/admin/verkaeuferin/'.$s['id'].'/wallet-korrektur'))?>"><?=csrf_field()?><div class="form-grid"><label>Richtung<select name="direction"><option value="credit">Gutschrift</option><option value="debit">Belastung</option></select></label><label>Betrag (€)<input type="number" name="amount" min=".01" step=".01" required></label></div><p class="meta">Die Verkäuferin sieht nur den daraus resultierenden Wallet-Saldo, nicht diese interne Korrekturbuchung.</p><button class="btn secondary">Saldo korrigieren</button></form><?php endif;?>
      </section>
    </div>
    <h2>Aufträge</h2><div class="table-wrap"><table><thead><tr><th>Nr.</th><th>Auftrag</th><th>Status</th><th>Archiv</th><th></th></tr></thead><tbody><?php foreach($orders as $x):?><tr><td><?=e($x['order_no'])?></td><td><?=e($x['title'])?></td><td><?=e($x['status'])?></td><td><?=$x['archived_at']?'Ja':'Nein'?></td><td><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>">Öffnen</a></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Verkäuferinnenakte',ob_get_clean());exit;
}

if (preg_match('#^/admin/verkaeuferin/(\d+)/speichern$#',$path,$m) && $method==='POST') {
    $a=require_admin();
    $q=db()->prepare("SELECT * FROM sellers WHERE id=?");$q->execute([(int)$m[1]]);$s=$q->fetch();if(!$s)not_found();
    if($s['deleted_at']){flash('error','Ein anonymisiertes Konto kann nicht mehr bearbeitet werden.');redirect('/admin/verkaeuferin/'.$s['id']);}

    $new=[
      'first_name'=>post('first_name'),'last_name'=>post('last_name'),'birth_date'=>post('birth_date'),
      'street'=>post('street'),'postal_code'=>post('postal_code'),'city'=>post('city'),
      'phone'=>post('phone'),'email'=>strtolower(post('email')),
    ];
    if(!filter_var($new['email'],FILTER_VALIDATE_EMAIL)){flash('error','Ungültige E-Mail-Adresse.');redirect('/admin/verkaeuferin/'.$s['id']);}
    $sensitiveChanged=($new['email']!==strtolower($s['email']) || $new['birth_date']!==$s['birth_date']);
    if($sensitiveChanged && !isset($_POST['confirm_sensitive'])){flash('error','Änderungen an E-Mail oder Geburtsdatum müssen zusätzlich bestätigt werden.');redirect('/admin/verkaeuferin/'.$s['id']);}

    $changes=[];foreach($new as $k=>$v){if((string)$s[$k]!== (string)$v)$changes[$k]=['old'=>$s[$k],'new'=>$v];}
    try{
      db()->prepare("UPDATE sellers SET first_name=?,last_name=?,birth_date=?,street=?,postal_code=?,city=?,phone=?,email=?,email_verified_at=?,updated_at=NOW() WHERE id=?")
        ->execute([$new['first_name'],$new['last_name'],$new['birth_date'],$new['street'],$new['postal_code'],$new['city'],$new['phone'],$new['email'],$new['email']!==strtolower($s['email'])?null:$s['email_verified_at'],$s['id']]);
    }catch(PDOException $e){
      if(($e->errorInfo[1]??null)===1062){flash('error','Diese E-Mail-Adresse wird bereits verwendet.');redirect('/admin/verkaeuferin/'.$s['id']);}
      throw $e;
    }

    if($new['email']!==strtolower($s['email'])){
      db()->prepare("DELETE FROM email_verifications WHERE seller_id=?")->execute([$s['id']]);
      [$raw,$hash]=make_token();db()->prepare("INSERT INTO email_verifications(seller_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))")->execute([$s['id'],$hash]);
      send_app_mail($new['email'],'Neue E-Mail bestätigen','<p>Bitte bestätige die neue E-Mail-Adresse:</p><p><a href="'.e(url('/email-bestaetigen?token='.$raw)).'">E-Mail bestätigen</a></p>');
    }
    if($changes) log_event('seller.updated',(int)$s['id'],null,['changes'=>$changes,'admin_id'=>(int)$a['id']]);
    flash('success','Verkäuferinnendaten gespeichert.'.($new['email']!==strtolower($s['email'])?' Die neue E-Mail muss bestätigt werden.':''));
    redirect('/admin/verkaeuferin/'.$s['id']);
}

if (preg_match('#^/admin/verkaeuferin/(\d+)/loeschen$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM sellers WHERE id=? AND deleted_at IS NULL");$q->execute([(int)$m[1]]);$s=$q->fetch();if(!$s)not_found();
    $active=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status IN('precheck','running','shipping','review','payout')");$active->execute([$s['id']]);
    if((int)$active->fetchColumn()>0){flash('error','Das Konto kann nicht anonymisiert werden, solange aktive Aufträge bestehen.');redirect('/admin/verkaeuferin/'.$s['id']);}

    $anon='deleted-'.$s['id'].'-'.bin2hex(random_bytes(4)).'@invalid.local';
    db()->beginTransaction();
    try{
      db()->prepare("UPDATE sellers SET first_name='Gelöscht',last_name='Konto',birth_date='1900-01-01',street='gelöscht',postal_code='00000',city='gelöscht',phone='gelöscht',email=?,password_hash=?,email_verified_at=NULL,deleted_at=NOW(),updated_at=NOW() WHERE id=?")
        ->execute([$anon,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$s['id']]);
      db()->prepare("DELETE FROM email_verifications WHERE seller_id=?")->execute([$s['id']]);
      db()->prepare("DELETE FROM password_resets WHERE seller_id=?")->execute([$s['id']]);
      db()->prepare("UPDATE payout_profiles SET iban=NULL,bic=NULL,account_holder=NULL,paypal=NULL WHERE seller_id=?")->execute([$s['id']]);
      db()->prepare("UPDATE system_events SET payload_json=? WHERE seller_id=? AND event_type='seller.updated'")
        ->execute([json_encode(['redacted'=>true],JSON_UNESCAPED_UNICODE),$s['id']]);
      log_event('seller.anonymized',(int)$s['id'],null,['seller_id'=>(int)$s['id']]);
      db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}

    flash('success','Verkäuferinnenkonto wurde anonymisiert. Historische Auftrags-, Zahlungs- und Nachweisdaten bleiben erhalten.');
    redirect('/admin/verkaeuferinnen');
}

if ($path==='/admin/auszahlungen' && $method==='GET') {
    require_admin();
    $rows=db()->query("SELECT p.*,CONCAT(s.first_name,' ',s.last_name) seller_name,s.email FROM payout_requests p JOIN sellers s ON s.id=p.seller_id ORDER BY FIELD(p.status,'requested','review','released','paid','withdrawn','rejected'),p.created_at DESC")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Auszahlungen</h1></div><a class="btn secondary" href="<?=e(url('/admin/einstellungen'))?>">Auszahlungseinstellungen</a></div>
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Verkäuferin</th><th>Betrag</th><th>Gebühr</th><th>Netto</th><th>Methode</th><th>Status</th><th>Aktion</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['id'])?></td><td><?=e($r['seller_name'])?><br><span class="meta"><?=e($r['email'])?></span></td><td><?=money($r['amount'])?></td><td><?=money($r['fee'])?></td><td><?=money($r['net_amount'])?></td><td><?=e($r['method'])?></td><td><?=e($r['status'])?></td><td><div class="actions"><?php if($r['status']==='requested'):?><form method="post" action="<?=e(url('/admin/auszahlung/'.$r['id'].'/status/review'))?>"><?=csrf_field()?><button class="btn secondary">In Prüfung</button></form><form method="post" action="<?=e(url('/admin/auszahlung/'.$r['id'].'/status/rejected'))?>"><?=csrf_field()?><button class="btn danger">Ablehnen</button></form><?php elseif($r['status']==='review'):?><form method="post" action="<?=e(url('/admin/auszahlung/'.$r['id'].'/status/released'))?>"><?=csrf_field()?><button class="btn">Freigeben</button></form><form method="post" action="<?=e(url('/admin/auszahlung/'.$r['id'].'/status/rejected'))?>"><?=csrf_field()?><button class="btn danger">Ablehnen</button></form><?php elseif($r['status']==='released'):?><form method="post" action="<?=e(url('/admin/auszahlung/'.$r['id'].'/bezahlt'))?>"><?=csrf_field()?><button class="btn">Als bezahlt markieren</button></form><?php else:?>–<?php endif;?></div></td></tr><?php endforeach;?></tbody></table></div>
    <?php if(!$rows):?><div class="empty">Keine Auszahlungsanträge vorhanden.</div><?php endif;?><?php render('Auszahlungen',ob_get_clean());exit;
}


if (preg_match('#^/admin/auszahlung/(\d+)/status/(review|released|rejected)$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM payout_requests WHERE id=?");$q->execute([(int)$m[1]]);$r=$q->fetch();if(!$r)not_found();
    $target=$m[2];

    $valid=
      ($r['status']==='requested' && in_array($target,['review','rejected'],true)) ||
      ($r['status']==='review' && in_array($target,['released','rejected'],true));
    if(!$valid){flash('error','Dieser Statuswechsel ist nicht zulässig.');redirect('/admin/auszahlungen');}

    db()->prepare("UPDATE payout_requests SET status=?,updated_at=NOW() WHERE id=?")->execute([$target,$r['id']]);
    $labels=['review'=>'in Prüfung','released'=>'freigegeben','rejected'=>'abgelehnt'];
    notify_seller((int)$r['seller_id'],'payout.status','Auszahlungsstatus geändert','Dein Auszahlungsantrag #'.$r['id'].' ist jetzt '.$labels[$target].'.','/wallet',null,true);
    flash('success','Auszahlungsstatus auf „'.$labels[$target].'“ gesetzt.');redirect('/admin/auszahlungen');
}

if (preg_match('#^/digitale-datei/(\d+)$#',$path,$m) && $method==='GET') {
    $q=db()->prepare("SELECT d.*,o.seller_id,o.order_no,o.archived_at,o.status order_status FROM digital_versions d JOIN orders o ON o.id=d.order_id WHERE d.id=?");
    $q->execute([(int)$m[1]]);$d=$q->fetch();if(!$d||!$d['file_path'])not_found();

    $allow=false;
    if(admin()) $allow=true;
    elseif(($s=seller()) && (int)$s['id']===(int)$d['seller_id'] && $d['order_status']!=='rejected') $allow=true;
    if(!$allow){http_response_code(403);exit('Zugriff verweigert.');}

    $real=__DIR__.'/../storage/private/'.$d['file_path'];if(!is_file($real))not_found();
    header('Content-Type: '.($d['mime_type']?:'application/octet-stream'));
    header('Content-Length: '.filesize($real));header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');header('Cache-Control: private, no-store, max-age=0');
    readfile($real);exit;
}

if (preg_match('#^/admin/revisionspunkt/(\d+)/(erledigt|unzureichend|erneut)$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT i.*,r.order_id,o.order_no FROM revision_items i JOIN revision_rounds r ON r.id=i.revision_round_id JOIN orders o ON o.id=r.order_id WHERE i.id=?");
    $q->execute([(int)$m[1]]);$i=$q->fetch();if(!$i)not_found();

    $status=$m[2]==='erledigt'?'done':($m[2]==='unzureichend'?'insufficient':'change_again');
    db()->prepare("UPDATE revision_items SET status=? WHERE id=?")->execute([$status,$i['id']]);
    $left=db()->prepare("SELECT COUNT(*) FROM revision_items WHERE revision_round_id=? AND status<>'done'");$left->execute([$i['revision_round_id']]);
    if((int)$left->fetchColumn()===0) db()->prepare("UPDATE revision_rounds SET status='reviewed' WHERE id=?")->execute([$i['revision_round_id']]);

    flash('success','Revisionspunkt aktualisiert.');redirect('/admin/auftrag/'.$i['order_no']);
}


if (preg_match('#^/admin/kategorie/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();
    $q=db()->prepare("SELECT * FROM categories WHERE id=?");$q->execute([(int)$m[1]]);$cat=$q->fetch();if(!$cat)not_found();
    $q=db()->prepare("SELECT * FROM category_fields WHERE category_id=? ORDER BY sort_order,id");$q->execute([$cat['id']]);$fields=$q->fetchAll();

    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Kategorie</div><h1><?=e($cat['name'])?></h1></div><a class="btn secondary" href="<?=e(url('/admin/kategorien'))?>">Zur Übersicht</a></div>
    <div class="grid two">
      <form class="panel" method="post" action="<?=e(url('/admin/kategorie/'.$cat['id'].'/speichern'))?>"><?=csrf_field()?><h2>Kategorie bearbeiten</h2>
        <label>Name<input name="name" value="<?=e($cat['name'])?>" required></label>
        <label>Sortierung<input type="number" name="sort_order" value="<?=e($cat['sort_order'])?>"></label>
        <label><input type="checkbox" name="is_active" value="1" style="width:auto" <?=$cat['is_active']?'checked':''?>> Aktiv</label>
        <button class="btn">Kategorie speichern</button>
      </form>
      <form class="panel" method="post" action="<?=e(url('/admin/kategorie/'.$cat['id'].'/feld'))?>"><?=csrf_field()?><h2>Neues Zusatzfeld</h2>
        <div class="form-grid"><label>Bezeichnung<input name="label" required></label><label>Feldschlüssel<input name="field_key" placeholder="z. B. groesse"></label>
        <label>Typ<select name="field_type"><option value="text">Text</option><option value="number">Zahl</option><option value="select">Auswahl</option><option value="multiselect">Mehrfachauswahl</option><option value="boolean">Ja/Nein</option><option value="date">Datum</option></select></label><label>Sortierung<input type="number" name="sort_order" value="0"></label></div>
        <label>Optionen bei Auswahlfeldern – eine Zeile je Option<textarea name="options"></textarea></label>
        <label><input type="checkbox" name="required" value="1" style="width:auto"> Pflichtfeld</label>
        <button class="btn">Feld hinzufügen</button>
      </form>
    </div>
    <h2>Kategoriefelder</h2><div class="table-wrap"><table><thead><tr><th>Bezeichnung</th><th>Schlüssel</th><th>Typ</th><th>Pflicht</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($fields as $fld):?><tr><td><?=e($fld['label'])?></td><td><?=e($fld['field_key'])?></td><td><?=e($fld['field_type'])?></td><td><?=$fld['required']?'Ja':'Nein'?></td><td><?=$fld['is_active']?'Aktiv':'Inaktiv'?></td><td><form method="post" action="<?=e(url('/admin/kategoriefeld/'.$fld['id'].'/umschalten'))?>"><?=csrf_field()?><button class="btn secondary"><?=$fld['is_active']?'Deaktivieren':'Aktivieren'?></button></form></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Kategorie '.$cat['name'],ob_get_clean());exit;
}

if (preg_match('#^/admin/kategorie/(\d+)/speichern$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT * FROM categories WHERE id=?");$q->execute([(int)$m[1]]);$cat=$q->fetch();if(!$cat)not_found();
    db()->prepare("UPDATE categories SET name=?,sort_order=?,is_active=? WHERE id=?")->execute([post('name'),(int)post('sort_order','0'),isset($_POST['is_active'])?1:0,$cat['id']]);
    flash('success','Kategorie gespeichert.');redirect('/admin/kategorie/'.$cat['id']);
}

if (preg_match('#^/admin/kategorie/(\d+)/feld$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT id FROM categories WHERE id=?");$q->execute([(int)$m[1]]);if(!$q->fetchColumn())not_found();
    $label=post('label');$key=post('field_key');
    if($key===''){$key=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',strtr($label,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'])),'-'));$key=str_replace('-','_',$key);}
    if(!preg_match('/^[a-z0-9_]{2,100}$/',$key)){flash('error','Der Feldschlüssel darf nur Kleinbuchstaben, Zahlen und Unterstriche enthalten.');redirect('/admin/kategorie/'.$m[1]);}
    $opts=array_values(array_filter(array_map('trim',preg_split('/\r?\n/',post('options')))));
    try{
      db()->prepare("INSERT INTO category_fields(category_id,field_key,label,field_type,options_json,required,is_active,sort_order) VALUES(?,?,?,?,?,?,1,?)")
        ->execute([(int)$m[1],$key,$label,post('field_type','text'),$opts?json_encode($opts,JSON_UNESCAPED_UNICODE):null,isset($_POST['required'])?1:0,(int)post('sort_order','0')]);
    }catch(PDOException $e){
      if(($e->errorInfo[1]??null)===1062){flash('error','Dieser Feldschlüssel existiert bereits.');redirect('/admin/kategorie/'.$m[1]);}
      throw $e;
    }
    flash('success','Kategoriefeld angelegt.');redirect('/admin/kategorie/'.$m[1]);
}

if (preg_match('#^/admin/kategoriefeld/(\d+)/umschalten$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT * FROM category_fields WHERE id=?");$q->execute([(int)$m[1]]);$fld=$q->fetch();if(!$fld)not_found();
    db()->prepare("UPDATE category_fields SET is_active=IF(is_active=1,0,1) WHERE id=?")->execute([$fld['id']]);
    flash('success','Feldstatus geändert.');redirect('/admin/kategorie/'.$fld['category_id']);
}


if (preg_match('#^/admin/angebot/(\d+)/versandschritt$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT id FROM offers WHERE id=?");$q->execute([(int)$m[1]]);if(!$q->fetchColumn())not_found();
    $title=post('title');if($title===''){flash('error','Titel des Versandschritts fehlt.');redirect('/admin/angebot/'.$m[1]);}
    db()->prepare("INSERT INTO offer_shipping_steps(offer_id,sort_order,title,instructions,required_photos,requires_text,requires_checkbox,is_dispatch_step,deadline_hours,active) VALUES(?,?,?,?,?,?,?,?,?,1)")
      ->execute([(int)$m[1],(int)post('sort_order','0'),$title,post('instructions'),max(0,(int)post('required_photos','0')),isset($_POST['requires_text'])?1:0,isset($_POST['requires_checkbox'])?1:0,isset($_POST['is_dispatch_step'])?1:0,post('deadline_hours')!==''?max(1,(int)post('deadline_hours')):null]);
    $version=bump_offer_version((int)$m[1],'shipping_step_added');
    flash('success','Versandschritt gespeichert. Angebot ist jetzt Version V'.$version.'.');redirect('/admin/angebot/'.$m[1]);
}

if (preg_match('#^/admin/versandschritt/(\d+)/loeschen$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT offer_id FROM offer_shipping_steps WHERE id=?");$q->execute([(int)$m[1]]);$offerId=$q->fetchColumn();if($offerId===false)not_found();
    db()->prepare("DELETE FROM offer_shipping_steps WHERE id=?")->execute([(int)$m[1]]);
    $version=bump_offer_version((int)$offerId,'shipping_step_removed');
    flash('success','Versandschritt entfernt. Bereits angenommene Aufträge behalten ihre gespeicherten Schritte. Angebot ist jetzt Version V'.$version.'.');redirect('/admin/angebot/'.(int)$offerId);
}


if (preg_match('#^/admin/digital-version/(\d+)/pruefen$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT d.*,o.order_no,o.seller_id,o.id order_id FROM digital_versions d JOIN orders o ON o.id=d.order_id WHERE d.id=?");
    $q->execute([(int)$m[1]]);$d=$q->fetch();if(!$d)not_found();

    $decision=post('decision');$allowed=['accepted','revision_required','partial','rejected'];
    if(!in_array($decision,$allowed,true)){flash('error','Ungültige Prüfentscheidung.');redirect('/admin/auftrag/'.$d['order_no']);}
    $note=post('review_note');

    db()->prepare("UPDATE digital_versions SET status=?,review_note=?,reviewed_at=NOW() WHERE id=?")
      ->execute([$decision,$note?:null,$d['id']]);

    $labels=['accepted'=>'akzeptiert','revision_required'=>'Revision erforderlich','partial'=>'teilweise akzeptiert','rejected'=>'abgelehnt'];
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
      ->execute([$d['order_id'],'Digitale Version V'.$d['version_no'].' wurde geprüft: '.$labels[$decision].($note!==''?' · '.$note:'')]);

    notify_seller((int)$d['seller_id'],'digital.review','Digitale Version geprüft','Version V'.$d['version_no'].' wurde als „'.$labels[$decision].'“ bewertet.'.($note!==''?' '.$note:''),'/auftrag/'.$d['order_no'].'/digital',null,true);
    flash('success','Digitale Version wurde bewertet.');redirect('/admin/auftrag/'.$d['order_no']);
}


if (preg_match('#^/auftrag/(\d{8})/startdatum$#',$path,$m) && $method==='POST') {
    $s=require_seller();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=? AND status='precheck'");
    $q->execute([$m[1],$s['id']]);$o=$q->fetch();if(!$o)not_found();
    if(!empty($o['planned_start_date'])){flash('error','Ein Startdatum ist bereits festgelegt. Änderungen müssen beantragt werden.');redirect('/auftrag/'.$o['order_no']);}

    $date=post('start_date');
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    $min=(new DateTimeImmutable('today',$tz))->modify('+1 day');
    try{$requested=new DateTimeImmutable($date.' 00:00:00',$tz);}catch(Throwable){$requested=false;}
    if(!$requested || $requested<$min){flash('error','Das erste Startdatum muss frühestens morgen liegen.');redirect('/auftrag/'.$o['order_no']);}

    db()->prepare("UPDATE orders SET planned_start_date=?,updated_at=NOW() WHERE id=?")->execute([$requested->format('Y-m-d'),$o['id']]);
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
      ->execute([$o['id'],'Startdatum festgelegt: '.$requested->format('d.m.Y').'.']);
    log_event('start_date.selected',(int)$s['id'],(int)$o['id'],['date'=>$requested->format('Y-m-d')]);
    flash('success','Startdatum wurde auf '.$requested->format('d.m.Y').' festgelegt.');
    redirect('/auftrag/'.$o['order_no']);
}

if (preg_match('#^/auftrag/(\d{8})/startdatum-aendern$#',$path,$m) && $method==='POST') {
    $s=require_seller();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=? AND status='precheck'");
    $q->execute([$m[1],$s['id']]);$o=$q->fetch();if(!$o)not_found();
    if(empty($o['planned_start_date'])){flash('error','Lege zuerst ein Startdatum fest.');redirect('/auftrag/'.$o['order_no']);}

    $open=db()->prepare("SELECT COUNT(*) FROM order_start_date_requests WHERE order_id=? AND status='pending'");
    $open->execute([$o['id']]);if((int)$open->fetchColumn()>0){flash('error','Es liegt bereits eine offene Startdatumsänderung vor.');redirect('/auftrag/'.$o['order_no']);}

    $date=post('requested_date');$reason=post('reason');
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    $min=(new DateTimeImmutable('today',$tz))->modify('+1 day');
    try{$requested=new DateTimeImmutable($date.' 00:00:00',$tz);}catch(Throwable){$requested=false;}
    if(!$requested || $requested<$min || $reason===''){flash('error','Bitte wähle ein zukünftiges Datum ab morgen und gib einen Grund an.');redirect('/auftrag/'.$o['order_no']);}
    if($requested->format('Y-m-d')===$o['planned_start_date']){flash('error','Das gewünschte Datum entspricht bereits dem aktuellen Startdatum.');redirect('/auftrag/'.$o['order_no']);}

    db()->prepare("INSERT INTO order_start_date_requests(order_id,requested_date,reason) VALUES(?,?,?)")
      ->execute([$o['id'],$requested->format('Y-m-d'),$reason]);
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
      ->execute([$o['id'],'Änderung des Startdatums auf '.$requested->format('d.m.Y').' beantragt.']);
    log_event('start_date.change_requested',(int)$s['id'],(int)$o['id'],['requested_date'=>$requested->format('Y-m-d'),'reason'=>$reason]);
    flash('success','Änderung des Startdatums wurde zur Freigabe eingereicht.');
    redirect('/auftrag/'.$o['order_no']);
}

if (preg_match('#^/admin/startdatum/(\d+)/(genehmigen|ablehnen)$#',$path,$m) && $method==='POST') {
    $a=require_admin();
    $q=db()->prepare("SELECT r.*,r.status request_status,o.order_no,o.seller_id,o.status order_status,o.planned_start_date FROM order_start_date_requests r JOIN orders o ON o.id=r.order_id WHERE r.id=?");
    $q->execute([(int)$m[1]]);$r=$q->fetch();if(!$r)not_found();
    if($r['request_status']!=='pending'){flash('error','Diese Anfrage wurde bereits bearbeitet.');redirect('/admin/auftrag/'.$r['order_no']);}
    if($r['order_status']!=='precheck'){flash('error','Nur Aufträge in der Vorbereitungsphase können noch verschoben werden.');redirect('/admin/auftrag/'.$r['order_no']);}

    $note=post('admin_note');
    if($m[2]==='genehmigen'){
        $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
        $min=(new DateTimeImmutable('today',$tz))->modify('+1 day');
        $requested=new DateTimeImmutable($r['requested_date'].' 00:00:00',$tz);
        if($requested<$min){flash('error','Das beantragte Datum liegt inzwischen zu früh. Bitte eine neue Anfrage stellen lassen.');redirect('/admin/auftrag/'.$r['order_no']);}

        db()->beginTransaction();
        try{
          db()->prepare("UPDATE order_start_date_requests SET status='approved',decided_at=NOW(),admin_note=? WHERE id=?")->execute([$note?:null,$r['id']]);
          db()->prepare("UPDATE orders SET planned_start_date=?,updated_at=NOW() WHERE id=?")->execute([$r['requested_date'],$r['order_id']]);
          db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
            ->execute([$r['order_id'],'Startdatumsänderung genehmigt. Neues Startdatum: '.date('d.m.Y',strtotime($r['requested_date'])).'.']);
          log_event('start_date.change_approved',(int)$r['seller_id'],(int)$r['order_id'],['request_id'=>(int)$r['id'],'date'=>$r['requested_date'],'admin_id'=>(int)$a['id']]);
          db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        notify_seller((int)$r['seller_id'],'start_date.approved','Startdatum geändert','Das neue Startdatum für Auftrag '.$r['order_no'].' ist der '.date('d.m.Y',strtotime($r['requested_date'])).'.','/auftrag/'.$r['order_no'],null,true);
        flash('success','Startdatumsänderung genehmigt.');
    }else{
        db()->prepare("UPDATE order_start_date_requests SET status='rejected',decided_at=NOW(),admin_note=? WHERE id=?")->execute([$note?:null,$r['id']]);
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
          ->execute([$r['order_id'],'Startdatumsänderung abgelehnt. Das bisherige Startdatum bleibt bestehen.']);
        log_event('start_date.change_rejected',(int)$r['seller_id'],(int)$r['order_id'],['request_id'=>(int)$r['id'],'admin_id'=>(int)$a['id'],'note'=>$note]);
        notify_seller((int)$r['seller_id'],'start_date.rejected','Startdatumsänderung abgelehnt','Das bisherige Startdatum für Auftrag '.$r['order_no'].' bleibt bestehen.','/auftrag/'.$r['order_no'],null,true);
        flash('success','Startdatumsänderung abgelehnt.');
    }
    redirect('/admin/auftrag/'.$r['order_no']);
}


if (preg_match('#^/admin/auftrag/(\d{8})/optionen$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    if(!in_array($o['status'],['precheck','running','shipping','review','payout'],true) || $o['archived_at']){
        flash('error','Optionen können bei diesem Auftrag nicht mehr geändert werden.');redirect('/admin/auftrag/'.$o['order_no']);
    }

    $requested=array_values(array_unique(array_map('intval',(array)($_POST['option_ids']??[]))));
    $selected=[];
    if($requested){
        $ph=implode(',',array_fill(0,count($requested),'?'));
        $args=array_merge([$o['offer_id']],$requested);
        $q=db()->prepare("SELECT * FROM offer_options WHERE offer_id=? AND id IN ($ph) ORDER BY id");
        $q->execute($args);$selected=$q->fetchAll();
        if(count($selected)!==count($requested)){flash('error','Mindestens eine Option gehört nicht zu diesem Angebot.');redirect('/admin/auftrag/'.$o['order_no']);}
    }

    $oldQ=db()->prepare("SELECT COALESCE(SUM(price_snapshot),0) FROM order_options WHERE order_id=?");$oldQ->execute([$o['id']]);$oldTotal=(float)$oldQ->fetchColumn();
    $newTotal=0.0;foreach($selected as $opt)$newTotal+=(float)$opt['price'];
    $delta=round($newTotal-$oldTotal,2);

    db()->beginTransaction();
    try{
        db()->prepare("DELETE FROM order_options WHERE order_id=?")->execute([$o['id']]);
        foreach($selected as $opt){
            db()->prepare("INSERT INTO order_options(order_id,offer_option_id,label_snapshot,price_snapshot) VALUES(?,?,?,?)")
              ->execute([$o['id'],$opt['id'],$opt['label'],$opt['price']]);
        }
        if(abs($delta)>0.0001){
            db()->prepare("UPDATE orders SET total_compensation=total_compensation+?,updated_at=NOW() WHERE id=?")->execute([$delta,$o['id']]);
            db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Optionsänderung durch Admin')")
              ->execute([$o['seller_id'],$o['id'],$delta]);
        }
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
          ->execute([$o['id'],'Zusatzoptionen wurden durch den Admin geändert. Änderung des Auftragswerts: '.money($delta).'.']);
        log_event('order.options_changed_by_admin',(int)$o['seller_id'],(int)$o['id'],['option_ids'=>$requested,'old_options_total'=>$oldTotal,'new_options_total'=>$newTotal,'delta'=>$delta]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}

    notify_seller((int)$o['seller_id'],'order.options_changed','Zusatzoptionen geändert','Die Zusatzoptionen für Auftrag '.$o['order_no'].' wurden angepasst. Neuer Auftragswert: '.money((float)$o['total_compensation']+$delta).'.','/auftrag/'.$o['order_no'],null,true);
    flash('success','Zusatzoptionen aktualisiert. Neuer Auftragswert: '.money((float)$o['total_compensation']+$delta).'.');
    redirect('/admin/auftrag/'.$o['order_no']);
}


if (preg_match('#^/admin/auftrag/(\d{8})/bonus$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    if(!in_array($o['status'],['precheck','running','shipping','review','payout'],true) || $o['archived_at']){
        flash('error','Für diesen Auftrag kann kein Bonus mehr vorgemerkt werden.');redirect('/admin/auftrag/'.$o['order_no']);
    }
    $amount=round((float)post('amount'),2);
    if($amount<=0){flash('error','Der Bonus muss größer als 0 € sein.');redirect('/admin/auftrag/'.$o['order_no']);}

    db()->beginTransaction();
    try{
      db()->prepare("INSERT INTO order_bonuses(order_id,amount,status,note) VALUES(?,?,'reserved',?)")
        ->execute([$o['id'],$amount,post('note')?:null]);
      $bonusId=(int)db()->lastInsertId();
      db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,bonus_id,entry_type,amount,description) VALUES(?,?,?,'reserved',?,'Bonus vorgemerkt')")
        ->execute([$o['seller_id'],$o['id'],$bonusId,$amount]);
      db()->prepare("UPDATE orders SET total_compensation=total_compensation+?,updated_at=NOW() WHERE id=?")->execute([$amount,$o['id']]);
      db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
        ->execute([$o['id'],'Bonus vorgemerkt: '.money($amount).(post('note')!==''?' · '.post('note'):'')]);
      log_event('order.bonus_added',(int)$o['seller_id'],(int)$o['id'],['bonus_id'=>$bonusId,'amount'=>$amount]);
      db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}

    notify_seller((int)$o['seller_id'],'order.bonus','Bonus vorgemerkt','Für Auftrag '.$o['order_no'].' wurde ein Bonus von '.money($amount).' vorgemerkt.','/auftrag/'.$o['order_no'],null,true);
    flash('success','Bonus vorgemerkt.');redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/admin/bonus/(\d+)/entfernen$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT b.*,o.order_no,o.seller_id,o.id order_id,o.status order_status,o.archived_at FROM order_bonuses b JOIN orders o ON o.id=b.order_id WHERE b.id=?");
    $q->execute([(int)$m[1]]);$b=$q->fetch();if(!$b)not_found();
    if($b['status']!=='reserved' || $b['archived_at'] || in_array($b['order_status'],['completed','rejected'],true)){
        flash('error','Dieser Bonus kann nicht mehr entfernt werden.');redirect('/admin/auftrag/'.$b['order_no']);
    }

    db()->beginTransaction();
    try{
      db()->prepare("UPDATE order_bonuses SET status='cancelled',cancelled_at=NOW() WHERE id=? AND status='reserved'")->execute([$b['id']]);
      db()->prepare("UPDATE wallet_entries SET entry_type='cancelled',description='Bonus vor Freigabe entfernt' WHERE bonus_id=? AND entry_type='reserved'")->execute([$b['id']]);
      db()->prepare("UPDATE orders SET total_compensation=GREATEST(0,total_compensation-?),updated_at=NOW() WHERE id=?")->execute([$b['amount'],$b['order_id']]);
      db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$b['order_id'],'Vorgemerkter Bonus von '.money($b['amount']).' wurde vor Freigabe entfernt.']);
      log_event('order.bonus_cancelled',(int)$b['seller_id'],(int)$b['order_id'],['bonus_id'=>(int)$b['id'],'amount'=>(float)$b['amount']]);
      db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}

    notify_seller((int)$b['seller_id'],'order.bonus_cancelled','Bonus entfernt','Der noch nicht freigegebene Bonus von '.money($b['amount']).' wurde aus Auftrag '.$b['order_no'].' entfernt.','/auftrag/'.$b['order_no'],null,true);
    flash('success','Bonus entfernt.');redirect('/admin/auftrag/'.$b['order_no']);
}

if (preg_match('#^/admin/verkaeuferin/(\d+)/wallet-korrektur$#',$path,$m) && $method==='POST') {
    $a=require_admin();
    $q=db()->prepare("SELECT * FROM sellers WHERE id=? AND deleted_at IS NULL");$q->execute([(int)$m[1]]);$s=$q->fetch();if(!$s)not_found();

    $amount=round(abs((float)post('amount')),2);
    $direction=post('direction');
    if($amount<=0 || !in_array($direction,['credit','debit'],true)){flash('error','Bitte eine gültige Wallet-Korrektur eingeben.');redirect('/admin/verkaeuferin/'.$s['id']);}
    $signed=$direction==='credit'?$amount:-$amount;

    db()->prepare("INSERT INTO wallet_entries(seller_id,entry_type,amount,description) VALUES(?,'adjustment',?,'Administrative Wallet-Korrektur')")
      ->execute([$s['id'],$signed]);
    log_event('wallet.adjustment',(int)$s['id'],null,['amount'=>$signed,'admin_id'=>(int)$a['id']]);

    // Verkäuferin sieht bewusst nur den neuen Saldo, nicht die interne Korrekturbuchung.
    $q=db()->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='available' THEN amount WHEN entry_type='adjustment' THEN amount WHEN entry_type='paid' THEN -amount ELSE 0 END),0) FROM wallet_entries WHERE seller_id=?");
    $q->execute([$s['id']]);$balance=(float)$q->fetchColumn();
    notify_seller((int)$s['id'],'wallet.balance_changed','Wallet-Saldo aktualisiert','Dein aktuell verfügbarer Wallet-Saldo beträgt '.money($balance).'.','/wallet',null,true);

    flash('success','Wallet korrigiert. Neuer verfügbarer Saldo: '.money($balance).'.');
    redirect('/admin/verkaeuferin/'.$s['id']);
}


if (preg_match('#^/auftrag/(\d{8})/retake/(\d+)$#',$path,$m) && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT r.*,o.order_no,o.status order_status,f.title,e.evidence_type,e.day_no,e.window_key,e.rejection_reason FROM evidence_retake_requests r JOIN orders o ON o.id=r.order_id JOIN offers f ON f.id=o.offer_id JOIN evidences e ON e.id=r.original_evidence_id WHERE r.id=? AND o.order_no=? AND r.seller_id=?");
    $q->execute([(int)$m[2],$m[1],$s['id']]);$r=$q->fetch();if(!$r)not_found();

    $replacement=null;
    if($r['replacement_evidence_id']){
      $q=db()->prepare("SELECT * FROM evidences WHERE id=?");$q->execute([$r['replacement_evidence_id']]);$replacement=$q->fetch()?:null;
    }
    $canUpload=$r['status']==='requested' && strtotime($r['grace_ends_at'])>=time() && empty($replacement);

    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Neuaufnahme · Auftrag <?=e($r['order_no'])?></div><h1><?=e($r['title'])?></h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$r['order_no']))?>">Zum Auftrag</a></div>
    <div class="grid two"><section class="panel"><h2>Anforderung</h2><p><?=nl2br(e($r['instructions']))?></p><p class="meta">Ursprünglicher Nachweis: <?=e($r['evidence_type'])?><?= $r['day_no']?' · Tag '.e($r['day_no']):'' ?><?= $r['window_key']?' · '.e($r['window_key']):'' ?><br>Frist <?=e(date('d.m.Y H:i',strtotime($r['due_at'])))?> · Nachfrist bis <?=e(date('d.m.Y H:i',strtotime($r['grace_ends_at'])))?></p><p><span class="badge"><?=e($r['status'])?></span></p><?php if($r['rejection_reason']):?><p><strong>Beanstandungsgrund:</strong> <?=e($r['rejection_reason'])?></p><?php endif;?></section>
    <section class="panel"><h2>Neuaufnahme einreichen</h2><?php if($canUpload):?><form method="post" enctype="multipart/form-data"><?=csrf_field()?><label>Live-Aufnahme<input data-camera-input type="file" name="evidence" required></label><button class="btn">Neuaufnahme einreichen</button></form><?php elseif($replacement):?><p>Die Neuaufnahme wurde eingereicht und wartet auf Prüfung.</p><p class="meta">Eingereicht <?=e(date('d.m.Y H:i',strtotime($replacement['created_at'])))?> · Status <?=e($replacement['status'])?></p><?php elseif($r['status']==='missed'):?><p class="meta">Die Frist einschließlich Nachfrist ist abgelaufen.</p><?php else:?><p class="meta">Für diese Anforderung ist aktuell kein weiterer Upload möglich.</p><?php endif;?></section></div>
    <?php render('Neuaufnahme',ob_get_clean());exit;
}

if (preg_match('#^/auftrag/(\d{8})/retake/(\d+)$#',$path,$m) && $method==='POST') {
    $s=require_seller();
    $q=db()->prepare("SELECT r.*,o.order_no,o.status order_status,e.order_run_id,e.evidence_type,e.day_no,e.window_key FROM evidence_retake_requests r JOIN orders o ON o.id=r.order_id JOIN evidences e ON e.id=r.original_evidence_id WHERE r.id=? AND o.order_no=? AND r.seller_id=?");
    $q->execute([(int)$m[2],$m[1],$s['id']]);$r=$q->fetch();if(!$r)not_found();
    if($r['status']!=='requested' || strtotime($r['grace_ends_at'])<time()){flash('error','Diese Neuaufnahme kann nicht mehr eingereicht werden.');redirect('/auftrag/'.$r['order_no'].'/retake/'.$r['id']);}

    $existing=db()->prepare("SELECT COUNT(*) FROM evidences WHERE source_type='retake' AND source_id=?");
    $existing->execute([$r['id']]);if((int)$existing->fetchColumn()>0){flash('error','Für diese Neuaufnahme wurde bereits eine Datei eingereicht.');redirect('/auftrag/'.$r['order_no'].'/retake/'.$r['id']);}

    try{
      $up=private_upload($_FILES['evidence']??[],'order-'.$r['order_id'].'/retakes');
      $late=strtotime($r['due_at'])<time()?1:0;
      db()->beginTransaction();
      db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,day_no,window_key,source_type,source_id,file_path,mime_type,file_size,sha256,is_late) VALUES(?,?,?,?,?,?,'retake',?,?,?,?,?,?)")
        ->execute([$r['order_id'],$r['order_run_id'],$s['id'],$r['evidence_type'],$r['day_no'],$r['window_key'],$r['id'],$up['path'],$up['mime'],$up['size'],$up['sha256'],$late]);
      $eid=(int)db()->lastInsertId();
      db()->prepare("UPDATE evidence_retake_requests SET replacement_evidence_id=?,status='uploaded' WHERE id=?")->execute([$eid,$r['id']]);
      db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Angeforderte Neuaufnahme wurde eingereicht und wartet auf Prüfung.')")->execute([$r['order_id']]);
      db()->commit();
      log_event('evidence.retake_submitted',(int)$s['id'],(int)$r['order_id'],['retake_id'=>(int)$r['id'],'evidence_id'=>$eid,'late'=>(bool)$late]);
      flash('success','Neuaufnahme wurde sicher eingereicht.');
    }catch(Throwable $e){
      if(db()->inTransaction()) db()->rollBack();
      flash('error',$e->getMessage());
    }
    redirect('/auftrag/'.$r['order_no'].'/retake/'.$r['id']);
}


if ($path==='/admin/ausfaelle' && $method==='GET') {
    require_admin();
    $rows=db()->query("SELECT o.*,COUNT(i.id) impact_count FROM system_outages o LEFT JOIN outage_impacts i ON i.outage_id=o.id GROUP BY o.id ORDER BY o.starts_at DESC LIMIT 100")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Technische Systemausfälle</h1><p class="meta">Hier werden ausschließlich tatsächlich eingetretene Plattformausfälle dokumentiert. Betroffene Fristen werden einmalig um die Ausfalldauer verlängert.</p></div></div>
    <form class="panel" method="post"><?=csrf_field()?><h2>Bestätigten Ausfall erfassen</h2>
      <div class="form-grid"><label>Beginn<input type="datetime-local" name="starts_at" required></label><label>Ende<input type="datetime-local" name="ends_at" required></label></div>
      <label>Technischer Grund<textarea name="reason" required placeholder="z. B. Webserver-Störung / Datenbankausfall"></textarea></label>
      <button class="btn">Ausfall erfassen und Fristen korrigieren</button>
    </form>
    <h2>Historie</h2><div class="table-wrap"><table><thead><tr><th>Zeitraum</th><th>Dauer</th><th>Grund</th><th>Betroffene Fristen</th><th>Angewendet</th></tr></thead><tbody>
    <?php foreach($rows as $r): $secs=max(0,strtotime($r['ends_at'])-strtotime($r['starts_at']));?><tr><td><?=e(date('d.m.Y H:i',strtotime($r['starts_at'])))?><br>bis <?=e(date('d.m.Y H:i',strtotime($r['ends_at'])))?></td><td><?=e((string)round($secs/60))?> Min.</td><td><?=e($r['reason'])?></td><td><?=e($r['impact_count'])?></td><td><?=e($r['applied_at']?date('d.m.Y H:i',strtotime($r['applied_at'])):'offen')?></td></tr><?php endforeach;?>
    </tbody></table></div><?php if(!$rows):?><div class="empty">Noch keine technischen Ausfälle dokumentiert.</div><?php endif;?>
    <?php render('Systemausfälle',ob_get_clean());exit;
}

if ($path==='/admin/ausfaelle' && $method==='POST') {
    require_admin();
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    try{
        $start=new DateTimeImmutable(post('starts_at'),$tz);
        $end=new DateTimeImmutable(post('ends_at'),$tz);
        $now=new DateTimeImmutable('now',$tz);
    }catch(Throwable){
        flash('error','Beginn oder Ende ist ungültig.');redirect('/admin/ausfaelle');
    }
    if($end<=$start){flash('error','Das Ende muss nach dem Beginn liegen.');redirect('/admin/ausfaelle');}
    if($end>$now){flash('error','Nur tatsächlich beendete technische Ausfälle können bestätigt werden.');redirect('/admin/ausfaelle');}
    if(trim(post('reason'))===''){flash('error','Bitte einen technischen Grund angeben.');redirect('/admin/ausfaelle');}

    db()->prepare("INSERT INTO system_outages(starts_at,ends_at,reason,status) VALUES(?,?,?,'ended')")
        ->execute([$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),post('reason')]);
    $id=(int)db()->lastInsertId();
    try{
        $result=apply_system_outage($id);
        flash('success','Systemausfall berücksichtigt: '.$result['entities'].' Frist(en) in '.$result['orders'].' Auftrag/Aufträgen wurden um '.round($result['seconds']/60).' Minuten verschoben.');
    }catch(Throwable $e){
        flash('error','Der Ausfall wurde gespeichert, konnte aber nicht vollständig angewendet werden: '.$e->getMessage());
    }
    redirect('/admin/ausfaelle');
}

if (preg_match('#^/auftrag/(\d{8})/zwischenstaende$#',$path,$m) && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");
    $q->execute([$m[1],$s['id']]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM order_interim_summaries WHERE order_id=? ORDER BY completed_days DESC");$q->execute([$o['id']]);$rows=$q->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1>Zwischenstände</h1><p class="meta"><?=e($o['title'])?></p></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div>
    <div class="timeline"><?php foreach($rows as $r): $x=json_decode($r['snapshot_json'],true)?:[];?><div><strong>Nach <?=e($r['completed_days'])?> abgeschlossenen Tagen</strong><div class="meta"><?=e(date('d.m.Y H:i',strtotime($r['created_at'])))?></div><p>Planfortschritt: <?=e($x['completed_days']??0)?> / <?=e($x['total_scheduled_days']??0)?> Tage · bestätigte Verstöße: <?=e($x['confirmed_violations']??0)?> · Zusatztage: <?=e($x['extra_days']??0)?> · offene Zusatzaufgaben: <?=e($x['open_tasks']??0)?> · offene spontane Nachweise: <?=e($x['open_spontaneous']??0)?></p><p><strong>Aktueller Auftragswert: <?=money($x['current_order_value']??0)?></strong></p></div><?php endforeach;?><?php if(!$rows):?><div class="empty">Für diesen Auftrag wurde noch kein automatischer Zwischenstand erzeugt.</div><?php endif;?></div>
    <?php render('Zwischenstände',ob_get_clean());exit;
}

if (preg_match('#^/admin/auftrag/(\d{8})/zwischenstaende$#',$path,$m) && $method==='GET') {
    require_admin();
    $q=db()->prepare("SELECT o.*,f.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id WHERE o.order_no=?");
    $q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM order_interim_summaries WHERE order_id=? ORDER BY completed_days DESC");$q->execute([$o['id']]);$rows=$q->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration · <?=e($o['order_no'])?></div><h1>Zwischenstände</h1><p class="meta"><?=e($o['seller_name'].' · '.$o['title'])?></p></div><a class="btn secondary" href="<?=e(url('/admin/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div>
    <div class="timeline"><?php foreach($rows as $r): $x=json_decode($r['snapshot_json'],true)?:[];?><div><strong>Nach <?=e($r['completed_days'])?> abgeschlossenen Tagen</strong><div class="meta"><?=e(date('d.m.Y H:i',strtotime($r['created_at'])))?></div><pre style="white-space:pre-wrap"><?=e(json_encode($x,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre></div><?php endforeach;?><?php if(!$rows):?><div class="empty">Noch keine Zwischenstände erzeugt.</div><?php endif;?></div>
    <?php render('Zwischenstände',ob_get_clean());exit;
}


if ($path==='/admin/versandadressen' && $method==='GET') {
    require_admin();
    $rows=db()->query("SELECT * FROM shipping_addresses ORDER BY active DESC,label,id")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Versandadressen</h1><p class="meta">Angebote verweisen auf diese Adressen. Bereits angenommene Aufträge behalten ihren gespeicherten Snapshot.</p></div><a class="btn secondary" href="<?=e(url('/admin/einstellungen'))?>">Einstellungen</a></div>
    <form class="panel" method="post"><?=csrf_field()?>
      <div class="form-grid">
        <label>Bezeichnung<input name="label" placeholder="z. B. Hauptadresse" required></label>
        <label>Empfängername<input name="recipient_name" required></label>
        <label>Straße / Hausnummer<input name="street" required></label>
        <label>Adresszusatz<input name="address_extra"></label>
        <label>PLZ<input name="postal_code" required></label>
        <label>Ort<input name="city" required></label>
        <label>Land<select name="country_code"><option value="DE">Deutschland</option></select></label>
      </div>
      <button class="btn">Versandadresse anlegen</button>
    </form><br>
    <div class="table-wrap"><table><thead><tr><th>Bezeichnung</th><th>Empfänger</th><th>Adresse</th><th>Status</th><th>Aktion</th></tr></thead><tbody>
    <?php foreach($rows as $r):?><tr><td><?=e($r['label'])?></td><td><?=e($r['recipient_name'])?></td><td><?=e($r['street'])?><?= $r['address_extra']?'<br>'.e($r['address_extra']):'' ?><br><?=e($r['postal_code'].' '.$r['city'])?></td><td><?=$r['active']?'Aktiv':'Inaktiv'?></td><td><form method="post" action="<?=e(url('/admin/versandadresse/'.$r['id'].'/umschalten'))?>"><?=csrf_field()?><button class="btn secondary"><?=$r['active']?'Deaktivieren':'Aktivieren'?></button></form></td></tr><?php endforeach;?>
    </tbody></table></div><?php if(!$rows):?><div class="empty">Noch keine Versandadresse hinterlegt.</div><?php endif;?>
    <?php render('Versandadressen',ob_get_clean());exit;
}

if ($path==='/admin/versandadressen' && $method==='POST') {
    require_admin();
    db()->prepare("INSERT INTO shipping_addresses(label,recipient_name,street,address_extra,postal_code,city,country_code) VALUES(?,?,?,?,?,?,'DE')")
      ->execute([post('label'),post('recipient_name'),post('street'),post('address_extra')?:null,post('postal_code'),post('city')]);
    flash('success','Versandadresse angelegt.');redirect('/admin/versandadressen');
}

if (preg_match('#^/admin/versandadresse/(\d+)/umschalten$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM shipping_addresses WHERE id=?");$q->execute([(int)$m[1]]);$row=$q->fetch();if(!$row)not_found();
    db()->prepare("UPDATE shipping_addresses SET active=IF(active=1,0,1),updated_at=NOW() WHERE id=?")->execute([$row['id']]);
    flash('success','Versandadresse aktualisiert.');redirect('/admin/versandadressen');
}


if (preg_match('#^/admin/angebot/(\d+)/als-vorlage$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM offers WHERE id=?");$q->execute([(int)$m[1]]);$offer=$q->fetch();if(!$offer)not_found();
    $name=post('template_name');if($name===''){flash('error','Bitte einen Namen für die Vorlage angeben.');redirect('/admin/angebot/'.$offer['id']);}

    $q=db()->prepare("SELECT label,price,requirements_json,active FROM offer_options WHERE offer_id=? ORDER BY id");$q->execute([$offer['id']]);$options=$q->fetchAll();
    $q=db()->prepare("SELECT sort_order,title,instructions,required_photos,requires_text,requires_checkbox,is_dispatch_step,deadline_hours,active FROM offer_shipping_steps WHERE offer_id=? ORDER BY sort_order,id");$q->execute([$offer['id']]);$steps=$q->fetchAll();

    $snapshot=[
      'offer'=>[
        'category_id'=>(int)$offer['category_id'],
        'title'=>$offer['title'],
        'description'=>$offer['description'],
        'compensation'=>(float)$offer['compensation'],
        'duration_days'=>$offer['duration_days']!==null?(int)$offer['duration_days']:null,
        'fulfillment_type'=>$offer['fulfillment_type'],
        'evidence_rules_json'=>$offer['evidence_rules_json'],
        'shipping_rules_json'=>$offer['shipping_rules_json'],
        'shipping_address_id'=>$offer['shipping_address_id']!==null?(int)$offer['shipping_address_id']:null,
        'shipping_cost_mode'=>$offer['shipping_cost_mode'],
        'shipping_allowance'=>(float)$offer['shipping_allowance'],
        'preferred_carrier'=>$offer['preferred_carrier'],
      ],
      'options'=>$options,
      'shipping_steps'=>$steps,
      'source_offer_id'=>(int)$offer['id'],
      'created_at'=>date(DATE_ATOM),
    ];

    db()->prepare("INSERT INTO offer_templates(name,snapshot_json,active) VALUES(?,?,1)")
      ->execute([$name,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    flash('success','Angebotsvorlage „'.$name.'“ gespeichert.');redirect('/admin/angebot/'.$offer['id']);
}

if ($path==='/admin/angebotsvorlagen' && $method==='GET') {
    require_admin();
    $rows=db()->query("SELECT * FROM offer_templates ORDER BY active DESC,name,id DESC")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Angebotsvorlagen</h1><p class="meta">Vorlagen enthalten Angebotsdaten, Nachweisplan, Versandbedingungen, Zusatzoptionen und den konfigurierten Versand-/Endworkflow.</p></div><a class="btn secondary" href="<?=e(url('/admin/angebote'))?>">Angebote</a></div>
    <div class="grid"><?php foreach($rows as $r):$snap=json_decode($r['snapshot_json'],true)?:[];$offer=$snap['offer']??[];?><article class="card"><span class="badge"><?=$r['active']?'AKTIV':'INAKTIV'?></span><h3><?=e($r['name'])?></h3><p class="meta">Basis: <?=e($offer['title']??'–')?> · <?=isset($offer['compensation'])?money($offer['compensation']):'–'?></p><div class="actions"><?php if($r['active']):?><form method="post" action="<?=e(url('/admin/angebotsvorlage/'.$r['id'].'/verwenden'))?>"><?=csrf_field()?><button class="btn">Als neuen Entwurf verwenden</button></form><?php endif;?><form method="post" action="<?=e(url('/admin/angebotsvorlage/'.$r['id'].'/umschalten'))?>"><?=csrf_field()?><button class="btn secondary"><?=$r['active']?'Deaktivieren':'Aktivieren'?></button></form></div></article><?php endforeach;?><?php if(!$rows):?><div class="empty">Noch keine Angebotsvorlagen vorhanden. Speichere eine Vorlage aus einem bestehenden Angebot.</div><?php endif;?></div>
    <?php render('Angebotsvorlagen',ob_get_clean());exit;
}

if (preg_match('#^/admin/angebotsvorlage/(\d+)/verwenden$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM offer_templates WHERE id=? AND active=1");$q->execute([(int)$m[1]]);$tpl=$q->fetch();if(!$tpl)not_found();
    $snap=json_decode($tpl['snapshot_json'],true)?:[];$o=$snap['offer']??[];
    if(!$o){flash('error','Die Vorlage enthält keine gültigen Angebotsdaten.');redirect('/admin/angebotsvorlagen');}

    $baseTitle=(string)($o['title']??$tpl['name']);$title=$baseTitle.' – Entwurf';
    $slug=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',strtr($baseTitle,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'])),'-')).'-'.substr(bin2hex(random_bytes(4)),0,8);

    db()->beginTransaction();
    try{
      db()->prepare("INSERT INTO offers(category_id,title,slug,description,compensation,duration_days,fulfillment_type,evidence_rules_json,shipping_rules_json,shipping_address_id,shipping_cost_mode,shipping_allowance,preferred_carrier,status,visibility,current_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'draft','public',1)")
        ->execute([(int)$o['category_id'],$title,$slug,(string)$o['description'],(float)$o['compensation'],$o['duration_days']??null,(string)$o['fulfillment_type'],$o['evidence_rules_json']??null,$o['shipping_rules_json']??null,$o['shipping_address_id']??null,$o['shipping_cost_mode']??'seller',(float)($o['shipping_allowance']??0),$o['preferred_carrier']??null]);
      $offerId=(int)db()->lastInsertId();

      $versionSnapshot=$o;$versionSnapshot['title']=$title;$versionSnapshot['status']='draft';$versionSnapshot['template_id']=(int)$tpl['id'];
      db()->prepare("INSERT INTO offer_versions(offer_id,version_no,snapshot_json) VALUES(?,1,?)")
        ->execute([$offerId,json_encode($versionSnapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

      foreach((array)($snap['options']??[]) as $opt){
        db()->prepare("INSERT INTO offer_options(offer_id,label,price,requirements_json,active) VALUES(?,?,?,?,?)")
          ->execute([$offerId,$opt['label'],(float)$opt['price'],$opt['requirements_json']??null,(int)($opt['active']??1)]);
      }
      foreach((array)($snap['shipping_steps']??[]) as $step){
        db()->prepare("INSERT INTO offer_shipping_steps(offer_id,sort_order,title,instructions,required_photos,requires_text,requires_checkbox,is_dispatch_step,deadline_hours,active) VALUES(?,?,?,?,?,?,?,?,?,?)")
          ->execute([$offerId,(int)$step['sort_order'],$step['title'],$step['instructions']??null,(int)$step['required_photos'],(int)$step['requires_text'],(int)$step['requires_checkbox'],(int)$step['is_dispatch_step'],$step['deadline_hours']??null,(int)($step['active']??1)]);
      }
      db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}

    flash('success','Neuer Angebotsentwurf aus Vorlage erstellt.');redirect('/admin/angebot/'.$offerId);
}

if (preg_match('#^/admin/angebotsvorlage/(\d+)/umschalten$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT id FROM offer_templates WHERE id=?");$q->execute([(int)$m[1]]);if(!$q->fetchColumn())not_found();
    db()->prepare("UPDATE offer_templates SET active=IF(active=1,0,1),updated_at=NOW() WHERE id=?")->execute([(int)$m[1]]);
    flash('success','Vorlagenstatus geändert.');redirect('/admin/angebotsvorlagen');
}

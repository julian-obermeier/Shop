<?php
declare(strict_types=1);

/**
 * V1 feature routes loaded after core routes and before public legal pages.
 * Every matching route exits after rendering/redirecting.
 */

if ($path==='/profil' && $method==='GET') {
    $s=require_seller();
    $p=db()->prepare("SELECT * FROM payout_profiles WHERE seller_id=?");$p->execute([$s['id']]);$pay=$p->fetch()?:[];
    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Konto</div><h1>Profil</h1></div></div>
    <div class="grid two">
      <form class="panel" method="post" action="<?=e(url('/profil'))?>"><?=csrf_field()?>
        <h2>Kontaktdaten</h2>
        <div class="form-grid">
          <label>Vorname<input name="first_name" value="<?=e($s['first_name'])?>" required></label>
          <label>Nachname<input name="last_name" value="<?=e($s['last_name'])?>" required></label>
          <label>Telefon<input name="phone" value="<?=e($s['phone'])?>" required></label>
          <label>E-Mail<input type="email" name="email" value="<?=e($s['email'])?>" required></label>
          <label>Straße<input name="street" value="<?=e($s['street'])?>" required></label>
          <label>PLZ<input name="postal_code" value="<?=e($s['postal_code'])?>" required></label>
          <label>Ort<input name="city" value="<?=e($s['city'])?>" required></label>
        </div><button class="btn">Profil speichern</button>
      </form>
      <form class="panel" method="post" action="<?=e(url('/profil/auszahlung'))?>"><?=csrf_field()?>
        <h2>Auszahlungsdaten</h2>
        <label>Kontoinhaber<input name="account_holder" value="<?=e($pay['account_holder']??'')?>"></label>
        <label>IBAN<input name="iban" value="<?=e($pay['iban']??'')?>"></label>
        <label>BIC (optional)<input name="bic" value="<?=e($pay['bic']??'')?>"></label>
        <label>PayPal E-Mail/Benutzerkennung<input name="paypal" value="<?=e($pay['paypal']??'')?>"></label>
        <button class="btn">Auszahlungsdaten speichern</button>
      </form>
    </div>
    <?php render('Profil',ob_get_clean());exit;
}
if ($path==='/profil' && $method==='POST') {
    $s=require_seller();$newEmail=strtolower(post('email'));if(!filter_var($newEmail,FILTER_VALIDATE_EMAIL)){flash('error','Ungültige E-Mail-Adresse.');redirect('/profil');}
    $changed=!hash_equals(strtolower($s['email']),$newEmail);
    db()->prepare("UPDATE sellers SET first_name=?,last_name=?,phone=?,street=?,postal_code=?,city=?,email=?,email_verified_at=".($changed?'NULL':'email_verified_at').",updated_at=NOW() WHERE id=?")
      ->execute([post('first_name'),post('last_name'),post('phone'),post('street'),post('postal_code'),post('city'),$newEmail,$s['id']]);
    if($changed){db()->prepare("DELETE FROM email_verifications WHERE seller_id=?")->execute([$s['id']]);[$raw,$hash]=make_token();db()->prepare("INSERT INTO email_verifications(seller_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))")->execute([$s['id'],$hash]);send_app_mail($newEmail,'Neue E-Mail bestätigen','<p><a href="'.e(url('/email-bestaetigen?token='.$raw)).'">Neue E-Mail-Adresse bestätigen</a></p>');}
    flash('success',$changed?'Profil gespeichert. Die neue E-Mail muss bestätigt werden.':'Profil gespeichert.');redirect('/profil');
}
if ($path==='/profil/auszahlung' && $method==='POST') {
    $s=require_seller();
    db()->prepare("INSERT INTO payout_profiles(seller_id,iban,bic,account_holder,paypal) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE iban=VALUES(iban),bic=VALUES(bic),account_holder=VALUES(account_holder),paypal=VALUES(paypal)")
      ->execute([$s['id'],post('iban'),post('bic'),post('account_holder'),post('paypal')]);
    flash('success','Auszahlungsdaten gespeichert. Bestehende Anträge behalten ihren bisherigen Snapshot.');redirect('/profil');
}
if ($path==='/wallet' && $method==='GET') {
    $s=require_seller();
    $st=db()->prepare("SELECT * FROM wallet_entries WHERE seller_id=? ORDER BY created_at DESC");$st->execute([$s['id']]);$entries=$st->fetchAll();
    $sum=db()->prepare("SELECT entry_type,SUM(amount) total FROM wallet_entries WHERE seller_id=? GROUP BY entry_type");$sum->execute([$s['id']]);$tot=[];foreach($sum->fetchAll() as $r)$tot[$r['entry_type']]=$r['total'];
    $req=db()->prepare("SELECT * FROM payout_requests WHERE seller_id=? ORDER BY created_at DESC");$req->execute([$s['id']]);$requests=$req->fetchAll();
    $available=(float)($tot['available']??0)-(float)($tot['paid']??0);
    $p=db()->prepare("SELECT * FROM payout_profiles WHERE seller_id=?");$p->execute([$s['id']]);$profile=$p->fetch()?:[];
    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Finanzen</div><h1>Wallet</h1></div><a class="btn secondary" href="<?=e(url('/profil'))?>">Auszahlungsdaten</a></div>
    <div class="grid"><div class="card"><div class="meta">Vorgemerkt</div><div class="stat"><?=money($tot['reserved']??0)?></div></div><div class="card"><div class="meta">Verfügbar</div><div class="stat"><?=money(max(0,$available))?></div></div><div class="card"><div class="meta">Ausgezahlt</div><div class="stat"><?=money($tot['paid']??0)?></div></div></div>
    <h2>Auszahlung beantragen</h2>
    <form class="panel" method="post" action="<?=e(url('/wallet/auszahlung'))?>"><?=csrf_field()?>
      <div class="form-grid"><label>Betrag (€)<input type="number" name="amount" step=".01" min="1" max="<?=e((string)max(0,$available))?>" required></label><label>Methode<select name="method"><option value="bank">Banküberweisung</option><option value="paypal">PayPal</option></select></label></div>
      <p class="meta">Es ist nur ein offener Auszahlungsantrag gleichzeitig möglich. Zahlungsdaten werden bei Antragstellung als Snapshot gespeichert.</p><button class="btn">Auszahlung beantragen</button>
    </form>
    <h2>Auszahlungsverlauf</h2><div class="table-wrap"><table><thead><tr><th>Datum</th><th>Betrag</th><th>Netto</th><th>Methode</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($requests as $r):?><tr><td><?=e(date('d.m.Y H:i',strtotime($r['created_at'])))?></td><td><?=money($r['amount'])?></td><td><?=money($r['net_amount'])?></td><td><?=e($r['method'])?></td><td><?=e($r['status'])?></td><td><?php if($r['status']==='requested'):?><form method="post" action="<?=e(url('/wallet/auszahlung/'.$r['id'].'/zurueckziehen'))?>"><?=csrf_field()?><button class="btn secondary">Zurückziehen</button></form><?php endif;?></td></tr><?php endforeach;?>
    </tbody></table></div>
    <?php render('Wallet',ob_get_clean());exit;
}
if ($path==='/wallet/auszahlung' && $method==='POST') {
    $s=require_seller();
    $open=db()->prepare("SELECT COUNT(*) FROM payout_requests WHERE seller_id=? AND status IN('requested','review','released')");$open->execute([$s['id']]);if((int)$open->fetchColumn()>0){flash('error','Es besteht bereits ein offener Auszahlungsantrag.');redirect('/wallet');}
    $sum=db()->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='available' THEN amount WHEN entry_type='paid' THEN -amount ELSE 0 END),0) FROM wallet_entries WHERE seller_id=?");$sum->execute([$s['id']]);$available=(float)$sum->fetchColumn();$amount=(float)post('amount');$method=post('method');
    if($amount<=0||$amount>$available){flash('error','Der gewünschte Betrag ist nicht verfügbar.');redirect('/wallet');}
    $p=db()->prepare("SELECT * FROM payout_profiles WHERE seller_id=?");$p->execute([$s['id']]);$profile=$p->fetch();
    if(!$profile||($method==='bank'&&(!$profile['iban']||!$profile['account_holder']))||($method==='paypal'&&!$profile['paypal'])){flash('error','Bitte hinterlege zuerst vollständige Auszahlungsdaten.');redirect('/profil');}
    $snap=json_encode($method==='bank'?['iban'=>$profile['iban'],'bic'=>$profile['bic'],'account_holder'=>$profile['account_holder']]:['paypal'=>$profile['paypal']],JSON_UNESCAPED_UNICODE);
    db()->prepare("INSERT INTO payout_requests(seller_id,amount,fee,net_amount,method,payment_snapshot_json) VALUES(?,?,0,?,?,?)")->execute([$s['id'],$amount,$amount,$method,$snap]);
    flash('success','Auszahlungsantrag wurde gestellt.');redirect('/wallet');
}
if (preg_match('#^/wallet/auszahlung/(\d+)/zurueckziehen$#',$path,$m)&&$method==='POST') {
    $s=require_seller();db()->prepare("UPDATE payout_requests SET status='withdrawn',updated_at=NOW() WHERE id=? AND seller_id=? AND status='requested'")->execute([(int)$m[1],$s['id']]);flash('success','Auszahlungsantrag zurückgezogen.');redirect('/wallet');
}
if (preg_match('#^/datei/(\d+)$#',$path,$m)&&$method==='GET') {
    $ev=db()->prepare("SELECT e.*,o.seller_id,o.status order_status FROM evidences e JOIN orders o ON o.id=e.order_id WHERE e.id=?");$ev->execute([(int)$m[1]]);$f=$ev->fetch();if(!$f)not_found();
    $allow=false;if(admin())$allow=true;elseif(($s=seller())&&(int)$s['id']===(int)$f['seller_id']&&$f['order_status']!=='rejected')$allow=true;
    if(!$allow){http_response_code(403);exit('Zugriff verweigert.');}
    $real=__DIR__.'/../storage/private/'.$f['file_path'];if(!is_file($real))not_found();
    header('Content-Type: '.$f['mime_type']);header('Content-Length: '.filesize($real));header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline; filename="nachweis-'.$f['id'].'"');readfile($real);exit;
}
if (preg_match('#^/auftrag/(\d{8})/chat$#',$path,$m)&&$method==='GET') {
    $s=require_seller();$st=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM chat_messages WHERE order_id=? ORDER BY created_at");$q->execute([$o['id']]);$messages=$q->fetchAll();$locked=in_array($o['status'],['archived'],true);
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1>Auftragschat</h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div><div class="timeline"><?php foreach($messages as $msg):?><div><strong><?=e($msg['sender_type']==='seller'?'Du':($msg['sender_type']==='admin'?'Admin':'System'))?></strong><div><?=nl2br(e($msg['message']))?></div><small class="meta"><?=e(date('d.m.Y H:i',strtotime($msg['created_at'])))?></small></div><?php endforeach;?></div><?php if(!$locked):?><form class="panel" method="post"><?=csrf_field()?><label>Nachricht<textarea name="message" required></textarea></label><button class="btn">Senden</button></form><?php endif;?><?php render('Auftragschat',ob_get_clean());exit;
}
if (preg_match('#^/auftrag/(\d{8})/chat$#',$path,$m)&&$method==='POST') {
    $s=require_seller();$st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    $msg=post('message');if($msg!=='')db()->prepare("INSERT INTO chat_messages(order_id,sender_type,sender_id,message) VALUES(?,'seller',?,?)")->execute([$o['id'],$s['id'],$msg]);redirect('/auftrag/'.$o['order_no'].'/chat');
}
if (preg_match('#^/auftrag/(\d{8})/tagesnachweis$#',$path,$m)&&$method==='POST') {
    $s=require_seller();$st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=? AND status='running'");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o){flash('error','Der Auftrag ist nicht in der Durchführungsphase.');redirect('/dashboard');}
    try{$up=private_upload($_FILES['evidence']??[],'order-'.$o['id']);$run=db()->prepare("SELECT id FROM order_runs WHERE order_id=? ORDER BY run_no DESC LIMIT 1");$run->execute([$o['id']]);db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,day_no,window_key,file_path,mime_type,file_size,sha256) VALUES(?,?,?,'daily',?,?,?,?,?,?)")->execute([$o['id'],$run->fetchColumn()?:null,$s['id'],max(1,(int)post('day_no','1')),post('window_key','custom'),$up['path'],$up['mime'],$up['size'],$up['sha256']]);flash('success','Tagesnachweis gespeichert.');}catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('/auftrag/'.$o['order_no']);
}
if (preg_match('#^/auftrag/(\d{8})/beschaedigung$#',$path,$m)&&$method==='POST') {
    $s=require_seller();$st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=? AND status='running'");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    $reason=post('reason');if($reason===''){flash('error','Bitte beschreibe die Beschädigung.');redirect('/auftrag/'.$o['order_no']);}
    db()->prepare("INSERT INTO damage_cases(order_id,reason) VALUES(?,?)")->execute([$o['id'],$reason]);$caseId=(int)db()->lastInsertId();
    if(isset($_FILES['evidence'])&&($_FILES['evidence']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){$up=private_upload($_FILES['evidence'],'order-'.$o['id']);db()->prepare("INSERT INTO evidences(order_id,seller_id,evidence_type,file_path,mime_type,file_size,sha256) VALUES(?,?,'damage',?,?,?,?)")->execute([$o['id'],$s['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);}
    db()->prepare("INSERT INTO system_events(seller_id,order_id,event_type,payload_json) VALUES(?,?,'damage.reported',?)")->execute([$s['id'],$o['id'],json_encode(['damage_case_id'=>$caseId],JSON_UNESCAPED_UNICODE)]);flash('success','Beschädigung wurde gemeldet. Der Auftrag läuft bis zur Entscheidung weiter.');redirect('/auftrag/'.$o['order_no']);
}
if ($path==='/admin/verkaeuferinnen'&&$method==='GET') {
    require_admin();$rows=db()->query("SELECT s.*,COUNT(o.id) orders_count FROM sellers s LEFT JOIN orders o ON o.seller_id=s.id WHERE s.deleted_at IS NULL GROUP BY s.id ORDER BY s.created_at DESC")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Verkäuferinnen</h1></div></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Verifiziert</th><th>Aufträge</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['first_name'].' '.$r['last_name'])?></td><td><?=e($r['email'])?></td><td><?=$r['email_verified_at']?'Ja':'Nein'?></td><td><?=e($r['orders_count'])?></td><td><a href="<?=e(url('/admin/verkaeuferin/'.$r['id']))?>">Akte</a></td></tr><?php endforeach;?></tbody></table></div><?php render('Verkäuferinnen',ob_get_clean());exit;
}
if (preg_match('#^/admin/verkaeuferin/(\d+)$#',$path,$m)&&$method==='GET') {
    require_admin();$st=db()->prepare("SELECT * FROM sellers WHERE id=?");$st->execute([(int)$m[1]]);$s=$st->fetch();if(!$s)not_found();$o=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? ORDER BY o.created_at DESC");$o->execute([$s['id']]);$orders=$o->fetchAll();
    ob_start();?><div class="eyebrow">Verkäuferinnenakte</div><h1><?=e($s['first_name'].' '.$s['last_name'])?></h1><div class="grid two"><div class="card"><h2>Stammdaten</h2><p><?=e($s['email'])?><br><?=e($s['phone'])?><br><?=e($s['street'])?><br><?=e($s['postal_code'].' '.$s['city'])?><br>Geboren: <?=e(date('d.m.Y',strtotime($s['birth_date'])))?></p></div><div class="card"><h2>Status</h2><p>E-Mail: <?=$s['email_verified_at']?'bestätigt':'offen'?><br>Registriert: <?=e(date('d.m.Y H:i',strtotime($s['created_at'])))?></p></div></div><h2>Aufträge</h2><div class="table-wrap"><table><tbody><?php foreach($orders as $x):?><tr><td><?=e($x['order_no'])?></td><td><?=e($x['title'])?></td><td><?=e($x['status'])?></td><td><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>">Öffnen</a></td></tr><?php endforeach;?></tbody></table></div><?php render('Verkäuferinnenakte',ob_get_clean());exit;
}
if ($path==='/admin/auszahlungen'&&$method==='GET') {
    require_admin();$rows=db()->query("SELECT p.*,CONCAT(s.first_name,' ',s.last_name) seller_name,s.email FROM payout_requests p JOIN sellers s ON s.id=p.seller_id ORDER BY p.created_at DESC")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Auszahlungen</h1></div></div><div class="table-wrap"><table><thead><tr><th>Verkäuferin</th><th>Betrag</th><th>Netto</th><th>Methode</th><th>Status</th><th>Aktion</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['seller_name'])?></td><td><?=money($r['amount'])?></td><td><?=money($r['net_amount'])?></td><td><?=e($r['method'])?></td><td><?=e($r['status'])?></td><td><?php if(!in_array($r['status'],['paid','withdrawn','rejected'],true)):?><form method="post" action="<?=e(url('/admin/auszahlung/'.$r['id'].'/bezahlt'))?>"><?=csrf_field()?><button class="btn">Als bezahlt markieren</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php render('Auszahlungen',ob_get_clean());exit;
}
if (preg_match('#^/admin/auszahlung/(\d+)/bezahlt$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM payout_requests WHERE id=?");$st->execute([(int)$m[1]]);$r=$st->fetch();if(!$r)not_found();if($r['status']!=='paid'){db()->beginTransaction();try{db()->prepare("UPDATE payout_requests SET status='paid',updated_at=NOW() WHERE id=?")->execute([$r['id']]);db()->prepare("INSERT INTO wallet_entries(seller_id,entry_type,amount,description) VALUES(?,'paid',?,'Auszahlung')")->execute([$r['seller_id'],$r['amount']]);db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}}flash('success','Auszahlung als bezahlt markiert.');redirect('/admin/auszahlungen');
}
if (preg_match('#^/admin/auftrag/(\d{8})/chat$#',$path,$m)&&$method==='GET') {
    require_admin();$st=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();$q=db()->prepare("SELECT * FROM chat_messages WHERE order_id=? ORDER BY created_at");$q->execute([$o['id']]);$messages=$q->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Admin · <?=e($o['order_no'])?></div><h1>Auftragschat</h1></div><a class="btn secondary" href="<?=e(url('/admin/auftrag/'.$o['order_no']))?>">Zurück</a></div><div class="timeline"><?php foreach($messages as $msg):?><div><strong><?=e($msg['sender_type'])?></strong><div><?=nl2br(e($msg['message']))?></div><small class="meta"><?=e(date('d.m.Y H:i',strtotime($msg['created_at'])))?></small></div><?php endforeach;?></div><form class="panel" method="post"><?=csrf_field()?><textarea name="message" required></textarea><button class="btn">Senden</button></form><?php render('Admin Chat',ob_get_clean());exit;
}
if (preg_match('#^/admin/auftrag/(\d{8})/chat$#',$path,$m)&&$method==='POST') {
    $a=require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();$msg=post('message');if($msg!=='')db()->prepare("INSERT INTO chat_messages(order_id,sender_type,sender_id,message) VALUES(?,'admin',?,?)")->execute([$o['id'],$a['id'],$msg]);redirect('/admin/auftrag/'.$o['order_no'].'/chat');
}
if (preg_match('#^/admin/auftrag/(\d{8})/zusatztag$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();$paid=($_POST['paid']??'')==='1';$amount=$paid?(float)post('amount'):0;
    db()->prepare("INSERT INTO extra_days(order_id,source_type,paid,amount,reason) VALUES(?,'manual',?,?,?)")->execute([$o['id'],$paid?1:0,$amount,post('reason')]);
    if($paid&&$amount>0){db()->prepare("UPDATE orders SET total_compensation=total_compensation+?,updated_at=NOW() WHERE id=?")->execute([$amount,$o['id']]);db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Bezahlter manueller Zusatztag')")->execute([$o['seller_id'],$o['id'],$amount]);}
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Manueller Zusatztag hinzugefügt: '.($paid?('bezahlt '.money($amount)):'unbezahlt').(post('reason')!==''?' – '.post('reason'):'')]);flash('success','Zusatztag hinzugefügt.');redirect('/admin/auftrag/'.$o['order_no']);
}
if (preg_match('#^/admin/auftrag/(\d{8})/verstoss$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();db()->prepare("INSERT INTO violations(order_id,violation_type,status,reason,extension_days) VALUES(?,?,'confirmed',?,1)")->execute([$o['id'],post('violation_type','manual'),post('reason')]);$vid=(int)db()->lastInsertId();db()->prepare("INSERT INTO extra_days(order_id,source_type,source_id,paid,amount,reason) VALUES(?,'violation',?,0,0,?)")->execute([$o['id'],$vid,post('reason')]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Bestätigter Verstoß: '.post('reason').' · +1 zusätzlicher Durchführungstag']);flash('success','Verstoß bestätigt und +1 Tag angehängt.');redirect('/admin/auftrag/'.$o['order_no']);
}
if (preg_match('#^/admin/beschaedigung/(\d+)/(anerkennen|ablehnen)$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT d.*,o.order_no,o.id order_id,o.seller_id FROM damage_cases d JOIN orders o ON o.id=d.order_id WHERE d.id=?");$st->execute([(int)$m[1]]);$d=$st->fetch();if(!$d)not_found();
    if($m[2]==='ablehnen'){db()->prepare("UPDATE damage_cases SET status='rejected',decided_at=NOW() WHERE id=?")->execute([$d['id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Beschädigung nicht anerkannt. Der Auftrag wird mit demselben Artikel fortgeführt.')")->execute([$d['order_id']]);}
    else {db()->beginTransaction();try{$rn=(int)db()->query("SELECT COALESCE(MAX(run_no),0)+1 FROM order_runs WHERE order_id=".(int)$d['order_id'])->fetchColumn();db()->prepare("UPDATE damage_cases SET status='restarted',decided_at=NOW() WHERE id=?")->execute([$d['id']]);db()->prepare("UPDATE order_runs SET status='restarted',ended_at=NOW() WHERE order_id=? AND status='running'")->execute([$d['order_id']]);db()->prepare("INSERT INTO order_runs(order_id,run_no,status,restart_reason) VALUES(?,?,'precheck',?)")->execute([$d['order_id'],$rn,$d['reason']]);db()->prepare("UPDATE orders SET status='precheck',started_at=NULL,updated_at=NOW() WHERE id=?")->execute([$d['order_id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$d['order_id'],'Beschädigung anerkannt. Neuer Durchlauf '.$rn.' – neue Vorabkontrolle erforderlich.']);db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}}
    flash('success','Beschädigungsvorgang entschieden.');redirect('/admin/auftrag/'.$d['order_no']);
}
if (preg_match('#^/admin/auftrag/(\d{8})/abschliessen$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();$decision=post('decision');
    if($decision==='accept'){db()->beginTransaction();try{db()->prepare("UPDATE orders SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$o['id']]);db()->prepare("UPDATE wallet_entries SET entry_type='available',description='Auftrag freigegeben' WHERE order_id=? AND entry_type='reserved'")->execute([$o['id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Auftrag wurde vollständig akzeptiert. Vergütung ist im Wallet verfügbar.')")->execute([$o['id']]);db()->commit();}catch(Throwable $e){db()->rollBack();throw $e;}}
    elseif($decision==='reject'){db()->prepare("UPDATE orders SET status='rejected',rejection_reason=?,completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([post('reason'),$o['id']]);db()->prepare("UPDATE wallet_entries SET entry_type='cancelled',description='Auftrag abgelehnt' WHERE order_id=? AND entry_type='reserved'")->execute([$o['id']]);}
    flash('success','Abschlussentscheidung gespeichert.');redirect('/admin/auftrag/'.$o['order_no']);
}
if ($path==='/admin/einstellungen'&&$method==='GET') {
    require_admin();$rows=db()->query("SELECT * FROM settings ORDER BY setting_key")->fetchAll();$set=[];foreach($rows as $r)$set[$r['setting_key']]=$r['setting_value'];
    ob_start();?><div class="eyebrow">Administration</div><h1>Systemeinstellungen</h1><form class="panel" method="post"><?=csrf_field()?><div class="form-grid"><label>Mindestauszahlung (€)<input type="number" step=".01" name="payout_min" value="<?=e($set['payout_min']??'10.00')?>"></label><label>Support-E-Mail<input type="email" name="support_email" value="<?=e($set['support_email']??app_config('mail.from',''))?>"></label><label>Morgenfenster<input name="window_morning" value="<?=e($set['window_morning']??'06:00-10:00')?>"></label><label>Mittagsfenster<input name="window_midday" value="<?=e($set['window_midday']??'12:00-16:00')?>"></label><label>Abendfenster<input name="window_evening" value="<?=e($set['window_evening']??'18:00-23:59')?>"></label><label>Grace Period Minuten<input type="number" name="grace_minutes" value="<?=e($set['grace_minutes']??'60')?>"></label></div><button class="btn">Speichern</button></form><?php render('Einstellungen',ob_get_clean());exit;
}
if ($path==='/admin/einstellungen'&&$method==='POST') {
    require_admin();foreach(['payout_min','support_email','window_morning','window_midday','window_evening','grace_minutes'] as $k){db()->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,post($k)]);}flash('success','Einstellungen gespeichert.');redirect('/admin/einstellungen');
}


if (preg_match('#^/auftrag/(\\d{8})/versand$#',$path,$m)&&$method==='GET') {
    $s=require_seller();$st=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM shipments WHERE order_id=?");$q->execute([$o['id']]);$ship=$q->fetch();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1>Versand</h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div>
    <?php if($ship):?><div class="panel"><h2>Status: <?=e($ship['status'])?></h2><p>Tracking: <?=e($ship['tracking_number']?:'–')?></p><?php if($ship['proof_evidence_id']):?><a href="<?=e(url('/datei/'.$ship['proof_evidence_id']))?>" target="_blank">Versandnachweis ansehen</a><?php endif;?></div><?php endif;?>
    <?php if(!$ship || $ship['status']==='preparing'):?><form class="panel" method="post" enctype="multipart/form-data"><?=csrf_field()?><h2>Versand nachweisen</h2><div class="form-grid"><label>Versanddienstleister<input name="carrier" placeholder="z. B. DHL"></label><label>Trackingnummer<input name="tracking_number"></label></div><label>Einlieferungsbeleg / Versandnachweis<input data-camera-input type="file" name="evidence"></label><p class="meta">Mindestens Trackingnummer oder ein Versandnachweis ist erforderlich.</p><button class="btn">Als versendet melden</button></form><?php endif;?>
    <?php render('Versand',ob_get_clean());exit;
}
if (preg_match('#^/auftrag/(\\d{8})/versand$#',$path,$m)&&$method==='POST') {
    $s=require_seller();$st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    $tracking=post('tracking_number');$proofId=null;
    if(isset($_FILES['evidence'])&&($_FILES['evidence']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){$up=private_upload($_FILES['evidence'],'order-'.$o['id']);db()->prepare("INSERT INTO evidences(order_id,seller_id,evidence_type,file_path,mime_type,file_size,sha256) VALUES(?,?,'shipping',?,?,?,?)")->execute([$o['id'],$s['id'],$up['path'],$up['mime'],$up['size'],$up['sha256']]);$proofId=(int)db()->lastInsertId();}
    if($tracking===''&&!$proofId){flash('error','Bitte Trackingnummer oder Versandnachweis angeben.');redirect('/auftrag/'.$o['order_no'].'/versand');}
    db()->prepare("INSERT INTO shipments(order_id,tracking_number,carrier,proof_evidence_id,status,shipped_at) VALUES(?,?,?,?,'shipped',NOW()) ON DUPLICATE KEY UPDATE tracking_number=VALUES(tracking_number),carrier=VALUES(carrier),proof_evidence_id=VALUES(proof_evidence_id),status='shipped',shipped_at=NOW()")->execute([$o['id'],$tracking,post('carrier'),$proofId]);
    db()->prepare("UPDATE orders SET status='shipping',updated_at=NOW() WHERE id=?")->execute([$o['id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Versand nachgewiesen – wartet auf Eingang.')")->execute([$o['id']]);flash('success','Versand wurde dokumentiert.');redirect('/auftrag/'.$o['order_no'].'/versand');
}
if (preg_match('#^/admin/auftrag/(\\d{8})/wareneingang$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();
    db()->prepare("UPDATE shipments SET status='received',received_at=NOW() WHERE order_id=?")->execute([$o['id']]);db()->prepare("UPDATE orders SET status='review',updated_at=NOW() WHERE id=?")->execute([$o['id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Sendung ist eingegangen und befindet sich in der Abschlussprüfung.')")->execute([$o['id']]);flash('success','Wareneingang bestätigt.');redirect('/admin/auftrag/'.$o['order_no']);
}
if (preg_match('#^/auftrag/(\\d{8})/digital$#',$path,$m)&&$method==='GET') {
    $s=require_seller();$st=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    $v=db()->prepare("SELECT * FROM digital_versions WHERE order_id=? ORDER BY version_no DESC");$v->execute([$o['id']]);$versions=$v->fetchAll();$rr=db()->prepare("SELECT r.*,COUNT(i.id) item_count FROM revision_rounds r LEFT JOIN revision_items i ON i.revision_round_id=r.id WHERE r.order_id=? GROUP BY r.id ORDER BY r.round_no DESC");$rr->execute([$o['id']]);$rounds=$rr->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Digitale Abgabe · <?=e($o['order_no'])?></div><h1><?=e($o['title'])?></h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div>
    <form class="panel" method="post" enctype="multipart/form-data"><?=csrf_field()?><h2>Neue Version einreichen</h2><label>Textinhalt (optional)<textarea name="text_content"></textarea></label><label>Datei (optional: Audio/Video/Bild)<input type="file" name="digital_file"></label><p class="meta">Mindestens Text oder Datei erforderlich. Jede Einreichung erzeugt eine neue unveränderliche Version.</p><button class="btn">Version final einreichen</button></form>
    <h2>Versionen</h2><div class="table-wrap"><table><thead><tr><th>Version</th><th>Zeitpunkt</th><th>Status</th><th>Inhalt</th></tr></thead><tbody><?php foreach($versions as $x):?><tr><td>V<?=e($x['version_no'])?></td><td><?=e(date('d.m.Y H:i',strtotime($x['created_at'])))?></td><td><?=e($x['status'])?></td><td><?= $x['file_path']?'Datei':'Text' ?></td></tr><?php endforeach;?></tbody></table></div>
    <h2>Revisionen</h2><div class="table-wrap"><table><tbody><?php foreach($rounds as $r):?><tr><td>Runde <?=e($r['round_no'])?></td><td><?=e($r['status'])?></td><td><?=e($r['item_count'])?> Änderungspunkte</td><td><?=e($r['due_at']?:'keine Frist')?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Digitale Abgabe',ob_get_clean());exit;
}
if (preg_match('#^/auftrag/(\\d{8})/digital$#',$path,$m)&&$method==='POST') {
    $s=require_seller();$st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();$text=post('text_content');$pathFile=null;$mime=null;$sha=null;
    if(isset($_FILES['digital_file'])&&($_FILES['digital_file']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){$up=private_upload($_FILES['digital_file'],'order-'.$o['id'].'/digital');$pathFile=$up['path'];$mime=$up['mime'];$sha=$up['sha256'];}
    if($text===''&&!$pathFile){flash('error','Bitte Text oder Datei einreichen.');redirect('/auftrag/'.$o['order_no'].'/digital');}
    $q=db()->prepare("SELECT COALESCE(MAX(version_no),0)+1 FROM digital_versions WHERE order_id=?");$q->execute([$o['id']]);$vn=(int)$q->fetchColumn();db()->prepare("INSERT INTO digital_versions(order_id,version_no,file_path,text_content,mime_type,sha256,status) VALUES(?,?,?,?,?,?,'submitted')")->execute([$o['id'],$vn,$pathFile,$text?:null,$mime,$sha]);
    db()->prepare("UPDATE revision_rounds SET status='submitted' WHERE order_id=? AND status='open'")->execute([$o['id']]);db()->prepare("UPDATE orders SET status='review',updated_at=NOW() WHERE id=?")->execute([$o['id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Digitale Version V'.$vn.' wurde eingereicht und wartet auf Prüfung.']);flash('success','Digitale Version V'.$vn.' eingereicht.');redirect('/auftrag/'.$o['order_no'].'/digital');
}
if (preg_match('#^/admin/auftrag/(\\d{8})/revision$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();
    $open=db()->prepare("SELECT COUNT(*) FROM revision_rounds WHERE order_id=? AND status='open'");$open->execute([$o['id']]);if((int)$open->fetchColumn()>0){flash('error','Es ist bereits eine Revision offen.');redirect('/admin/auftrag/'.$o['order_no']);}
    $q=db()->prepare("SELECT COALESCE(MAX(round_no),0)+1 FROM revision_rounds WHERE order_id=?");$q->execute([$o['id']]);$rn=(int)$q->fetchColumn();$due=post('due_at')?:null;db()->prepare("INSERT INTO revision_rounds(order_id,round_no,due_at) VALUES(?,?,?)")->execute([$o['id'],$rn,$due]);$rid=(int)db()->lastInsertId();
    foreach(array_filter(array_map('trim',preg_split('/\\r?\\n/',post('items')))) as $item){db()->prepare("INSERT INTO revision_items(revision_round_id,description) VALUES(?,?)")->execute([$rid,$item]);}
    db()->prepare("UPDATE orders SET status='review',updated_at=NOW() WHERE id=?")->execute([$o['id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Revision '.$rn.' wurde angefordert.']);flash('success','Revision angefordert.');redirect('/admin/auftrag/'.$o['order_no']);
}


if (preg_match('#^/admin/angebot/(\\d+)$#',$path,$m)&&$method==='GET') {
    require_admin();$st=db()->prepare("SELECT * FROM offers WHERE id=?");$st->execute([(int)$m[1]]);$o=$st->fetch();if(!$o)not_found();
    $cats=db()->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order,name")->fetchAll();$op=db()->prepare("SELECT * FROM offer_options WHERE offer_id=? ORDER BY id");$op->execute([$o['id']]);$options=$op->fetchAll();
    $vers=db()->prepare("SELECT version_no,created_at FROM offer_versions WHERE offer_id=? ORDER BY version_no DESC");$vers->execute([$o['id']]);$versions=$vers->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Angebot #<?=e($o['id'])?></div><h1><?=e($o['title'])?></h1><p class="meta">Aktuelle Version: V<?=e($o['current_version'])?></p></div><a class="btn secondary" href="<?=e(url('/admin/angebote'))?>">Zurück</a></div>
    <div class="grid two"><form class="panel" method="post"><?=csrf_field()?><h2>Angebot bearbeiten</h2><label>Titel<input name="title" value="<?=e($o['title'])?>" required></label><label>Kategorie<select name="category_id"><?php foreach($cats as $cat):?><option value="<?=$cat['id']?>" <?=$cat['id']==$o['category_id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach;?></select></label><div class="form-grid"><label>Vergütung (€)<input type="number" step=".01" min="0" name="compensation" value="<?=e($o['compensation'])?>" required></label><label>Dauer Tage<input type="number" min="1" name="duration_days" value="<?=e($o['duration_days']??'')?>"></label><label>Erfüllung<select name="fulfillment_type"><?php foreach(['days'=>'Tage','units'=>'Einheiten','one_time'=>'Einmalig','digital'=>'Digital','mixed'=>'Kombiniert'] as $k=>$v):?><option value="<?=$k?>" <?=$o['fulfillment_type']===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label><label>Status<select name="status"><?php foreach(['draft'=>'Entwurf','active'=>'Aktiv','inactive'=>'Deaktiviert'] as $k=>$v):?><option value="<?=$k?>" <?=$o['status']===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label></div><label>Beschreibung<textarea name="description" required><?=e($o['description'])?></textarea></label><button class="btn">Als neue Version speichern</button></form>
    <section class="panel"><h2>Zusatzoptionen</h2><form method="post" action="<?=e(url('/admin/angebot/'.$o['id'].'/option'))?>"><?=csrf_field()?><label>Bezeichnung<input name="label" required></label><label>Aufpreis (€)<input type="number" step=".01" min="0" name="price" value="0" required></label><button class="btn">Option hinzufügen</button></form><div class="timeline" style="margin-top:18px"><?php foreach($options as $x):?><div><strong><?=e($x['label'])?></strong> · <?=money($x['price'])?> · <?=$x['active']?'aktiv':'inaktiv'?></div><?php endforeach;?></div><h3>Versionshistorie</h3><?php foreach($versions as $v):?><div class="meta">V<?=e($v['version_no'])?> · <?=e(date('d.m.Y H:i',strtotime($v['created_at'])))?></div><?php endforeach;?></section></div>
    <?php render('Angebot bearbeiten',ob_get_clean());exit;
}
if (preg_match('#^/admin/angebot/(\\d+)$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM offers WHERE id=?");$st->execute([(int)$m[1]]);$o=$st->fetch();if(!$o)not_found();
    $newVersion=(int)$o['current_version']+1;$days=post('duration_days')!==''?(int)post('duration_days'):null;$comp=(float)post('compensation');
    $snap=['title'=>post('title'),'category_id'=>(int)post('category_id'),'description'=>post('description'),'compensation'=>$comp,'duration_days'=>$days,'fulfillment_type'=>post('fulfillment_type'),'status'=>post('status')];
    db()->beginTransaction();try{
      db()->prepare("UPDATE offers SET category_id=?,title=?,description=?,compensation=?,duration_days=?,fulfillment_type=?,status=?,current_version=?,updated_at=NOW() WHERE id=?")->execute([$snap['category_id'],$snap['title'],$snap['description'],$comp,$days,$snap['fulfillment_type'],$snap['status'],$newVersion,$o['id']]);
      db()->prepare("INSERT INTO offer_versions(offer_id,version_no,snapshot_json) VALUES(?,?,?)")->execute([$o['id'],$newVersion,json_encode($snap,JSON_UNESCAPED_UNICODE)]);
      db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    flash('success','Angebot als Version V'.$newVersion.' gespeichert. Bestehende Aufträge bleiben auf ihrer ursprünglichen Version.');redirect('/admin/angebot/'.$o['id']);
}
if (preg_match('#^/admin/angebot/(\\d+)/option$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT id FROM offers WHERE id=?");$st->execute([(int)$m[1]]);if(!$st->fetchColumn())not_found();
    db()->prepare("INSERT INTO offer_options(offer_id,label,price,active) VALUES(?,?,?,1)")->execute([(int)$m[1],post('label'),max(0,(float)post('price'))]);flash('success','Zusatzoption hinzugefügt.');redirect('/admin/angebot/'.$m[1]);
}

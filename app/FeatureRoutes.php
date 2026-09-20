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
    $available=(float)($tot['available']??0)+(float)($tot['adjustment']??0)-(float)($tot['paid']??0);
    $p=db()->prepare("SELECT * FROM payout_profiles WHERE seller_id=?");$p->execute([$s['id']]);$profile=$p->fetch()?:[];
    $minimum=max(0,(float)setting_value('payout_min','10.00'));
    $bankEnabled=setting_value('payout_bank_enabled','1')==='1';
    $paypalEnabled=setting_value('payout_paypal_enabled','1')==='1';
    $processing=(string)setting_value('payout_processing_days','Nach individueller Prüfung');
    $feeLabel=function(string $method): string {
        $type=(string)setting_value('payout_'.$method.'_fee_type','none');
        $value=max(0,(float)setting_value('payout_'.$method.'_fee_value','0'));
        if($type==='fixed') return money($value);
        if($type==='percent') return rtrim(rtrim(number_format($value,2,',','.'),'0'),',').' %';
        return 'keine';
    };
    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Finanzen</div><h1>Wallet</h1></div><a class="btn secondary" href="<?=e(url('/profil'))?>">Auszahlungsdaten</a></div>
    <div class="grid"><div class="card"><div class="meta">Vorgemerkt</div><div class="stat"><?=money($tot['reserved']??0)?></div></div><div class="card"><div class="meta">Verfügbar</div><div class="stat"><?=money($available)?></div></div><div class="card"><div class="meta">Ausgezahlt</div><div class="stat"><?=money($tot['paid']??0)?></div></div></div>
    <h2>Auszahlung beantragen</h2>
    <form class="panel" method="post" action="<?=e(url('/wallet/auszahlung'))?>"><?=csrf_field()?>
      <div class="form-grid"><label>Betrag (€)<input type="number" name="amount" step=".01" min="<?=e((string)$minimum)?>" max="<?=e((string)max(0,$available))?>" required></label><label>Methode<select name="method"><?php if($bankEnabled):?><option value="bank">Banküberweisung · Gebühr <?=e($feeLabel('bank'))?></option><?php endif;?><?php if($paypalEnabled):?><option value="paypal">PayPal · Gebühr <?=e($feeLabel('paypal'))?></option><?php endif;?></select></label></div>
      <p class="meta">Mindestauszahlung: <?=money($minimum)?> · Bearbeitung: <?=e($processing)?>. Es ist nur ein offener Auszahlungsantrag gleichzeitig möglich. Zahlungsdaten werden bei Antragstellung als Snapshot gespeichert.</p><button class="btn">Auszahlung beantragen</button>
    </form>
    <h2>Auszahlungsverlauf</h2><div class="table-wrap"><table><thead><tr><th>Datum</th><th>Betrag</th><th>Netto</th><th>Methode</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($requests as $r):?><tr><td><?=e(date('d.m.Y H:i',strtotime($r['created_at'])))?></td><td><?=money($r['amount'])?></td><td><?=money($r['net_amount'])?></td><td><?=e($r['method'])?></td><td><?=e($r['status'])?></td><td><?php if($r['status']==='requested'):?><form method="post" action="<?=e(url('/wallet/auszahlung/'.$r['id'].'/zurueckziehen'))?>"><?=csrf_field()?><button class="btn secondary">Zurückziehen</button></form><?php endif;?></td></tr><?php endforeach;?>
    </tbody></table></div>
    <?php render('Wallet',ob_get_clean());exit;
}
if ($path==='/wallet/auszahlung' && $method==='POST') {
    $s=require_seller();
    $open=db()->prepare("SELECT COUNT(*) FROM payout_requests WHERE seller_id=? AND status IN('requested','review','released')");$open->execute([$s['id']]);
    if((int)$open->fetchColumn()>0){flash('error','Es besteht bereits ein offener Auszahlungsantrag.');redirect('/wallet');}

    $sum=db()->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='available' THEN amount WHEN entry_type='adjustment' THEN amount WHEN entry_type='paid' THEN -amount ELSE 0 END),0) FROM wallet_entries WHERE seller_id=?");
    $sum->execute([$s['id']]);$available=(float)$sum->fetchColumn();
    $amount=(float)post('amount');$method=post('method');
    $minimum=max(0,(float)setting_value('payout_min','10.00'));
    if($amount<$minimum||$amount>$available){flash('error','Der gewünschte Betrag ist nicht verfügbar oder unterschreitet die Mindestauszahlung von '.money($minimum).'.');redirect('/wallet');}

    $enabled=$method==='bank' ? setting_value('payout_bank_enabled','1')==='1' : ($method==='paypal' ? setting_value('payout_paypal_enabled','1')==='1' : false);
    if(!$enabled){flash('error','Diese Auszahlungsmethode ist aktuell deaktiviert.');redirect('/wallet');}

    $p=db()->prepare("SELECT * FROM payout_profiles WHERE seller_id=?");$p->execute([$s['id']]);$profile=$p->fetch();
    if(!$profile||($method==='bank'&&(!$profile['iban']||!$profile['account_holder']))||($method==='paypal'&&!$profile['paypal'])){flash('error','Bitte hinterlege zuerst vollständige Auszahlungsdaten.');redirect('/profil');}

    $feeType=(string)setting_value('payout_'.$method.'_fee_type','none');
    $feeValue=max(0,(float)setting_value('payout_'.$method.'_fee_value','0'));
    $fee=$feeType==='fixed' ? $feeValue : ($feeType==='percent' ? round($amount*$feeValue/100,2) : 0.0);
    $fee=min($amount,$fee);$net=max(0,$amount-$fee);
    if($net<=0){flash('error','Die konfigurierte Gebühr würde die Auszahlung vollständig aufzehren.');redirect('/wallet');}

    $snap=json_encode($method==='bank'
        ? ['iban'=>$profile['iban'],'bic'=>$profile['bic'],'account_holder'=>$profile['account_holder']]
        : ['paypal'=>$profile['paypal']],JSON_UNESCAPED_UNICODE);
    db()->prepare("INSERT INTO payout_requests(seller_id,amount,fee,net_amount,method,payment_snapshot_json) VALUES(?,?,?,?,?,?)")
      ->execute([$s['id'],$amount,$fee,$net,$method,$snap]);
    flash('success','Auszahlungsantrag wurde gestellt. Gebühr: '.money($fee).' · Netto: '.money($net));redirect('/wallet');
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
    $q=db()->prepare("SELECT * FROM chat_messages WHERE order_id=? ORDER BY created_at");$q->execute([$o['id']]);$messages=$q->fetchAll();$locked=!empty($o['archived_at']);
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1>Auftragschat</h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div><div class="timeline"><?php foreach($messages as $msg):?><div><strong><?=e($msg['sender_type']==='seller'?'Du':($msg['sender_type']==='admin'?'Admin':'System'))?></strong><div><?=nl2br(e($msg['message']))?></div><small class="meta"><?=e(date('d.m.Y H:i',strtotime($msg['created_at'])))?></small></div><?php endforeach;?></div><?php if(!$locked):?><form class="panel" method="post"><?=csrf_field()?><label>Nachricht<textarea name="message" required></textarea></label><button class="btn">Senden</button></form><?php endif;?><?php render('Auftragschat',ob_get_clean());exit;
}
if (preg_match('#^/auftrag/(\d{8})/chat$#',$path,$m)&&$method==='POST') {
    $s=require_seller();$st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    if(!empty($o['archived_at'])){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/auftrag/'.$o['order_no'].'/chat');}
    $msg=post('message');if($msg!=='')db()->prepare("INSERT INTO chat_messages(order_id,sender_type,sender_id,message) VALUES(?,'seller',?,?)")->execute([$o['id'],$s['id'],$msg]);redirect('/auftrag/'.$o['order_no'].'/chat');
}
if (preg_match('#^/auftrag/(\d{8})/tagesnachweis$#',$path,$m)&&$method==='POST') {
    $s=require_seller();
    $st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=? AND status='running'");$st->execute([$m[1],$s['id']]);$o=$st->fetch();
    if(!$o){flash('error','Der Auftrag ist nicht in der Durchführungsphase.');redirect('/dashboard');}

    $windowId=(int)post('window_id');
    $runId=current_run_id((int)$o['id']);
    $q=db()->prepare("SELECT * FROM evidence_windows WHERE id=? AND order_id=? AND order_run_id<=>? AND starts_at<=NOW() AND COALESCE(grace_ends_at,ends_at)>=NOW() AND status IN('planned','open','submitted')");
    $q->execute([$windowId,$o['id'],$runId]);$w=$q->fetch();
    if(!$w){flash('error','Dieses Nachweisfenster ist nicht geöffnet oder die Nachfrist ist abgelaufen.');redirect('/auftrag/'.$o['order_no']);}

    $cnt=db()->prepare("SELECT COUNT(*) FROM evidences WHERE order_id=? AND order_run_id<=>? AND evidence_type='daily' AND day_no=? AND window_key=? AND status IN('submitted','accepted')");
    $cnt->execute([$o['id'],$runId,$w['day_no'],$w['window_key']]);$submitted=(int)$cnt->fetchColumn();
    if($submitted>=(int)$w['required_count']){flash('error','Für dieses Zeitfenster wurden bereits alle Pflichtnachweise eingereicht.');redirect('/auftrag/'.$o['order_no']);}

    try{
        $up=private_upload($_FILES['evidence']??[],'order-'.$o['id'].'/daily');
        db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,day_no,window_key,source_type,source_id,file_path,mime_type,file_size,sha256,metadata_json,quality_flags_json) VALUES(?,?,?,'daily',?,?,'window',?,?,?,?,?,?,?)")
          ->execute([$o['id'],$runId,$s['id'],$w['day_no'],$w['window_key'],$w['id'],$up['path'],$up['mime'],$up['size'],$up['sha256'],upload_metadata_json($up),upload_quality_flags_json($up)]);

        $submitted++;
        if($submitted>=(int)$w['required_count']) db()->prepare("UPDATE evidence_windows SET status='submitted' WHERE id=?")->execute([$w['id']]);
        elseif($w['status']==='planned') db()->prepare("UPDATE evidence_windows SET status='open' WHERE id=?")->execute([$w['id']]);

        $late=strtotime($w['ends_at'])<time();
        log_event('evidence.daily.submitted',(int)$s['id'],(int)$o['id'],['window_id'=>(int)$w['id'],'day_no'=>(int)$w['day_no'],'window_key'=>$w['window_key'],'late'=>$late]);
        flash('success','Tagesnachweis gespeichert.'.($late?' Einreichung erfolgte innerhalb der Nachfrist.':''));
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('/auftrag/'.$o['order_no']);
}
if (preg_match('#^/auftrag/(\d{8})/beschaedigung$#',$path,$m)&&$method==='POST') {
    $s=require_seller();$st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=? AND status='running'");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    $reason=post('reason');if($reason===''){flash('error','Bitte beschreibe die Beschädigung.');redirect('/auftrag/'.$o['order_no']);}
    db()->prepare("INSERT INTO damage_cases(order_id,reason) VALUES(?,?)")->execute([$o['id'],$reason]);$caseId=(int)db()->lastInsertId();
    if(isset($_FILES['evidence'])&&($_FILES['evidence']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){$up=private_upload($_FILES['evidence'],'order-'.$o['id']);db()->prepare("INSERT INTO evidences(order_id,seller_id,evidence_type,file_path,mime_type,file_size,sha256,metadata_json,quality_flags_json) VALUES(?,?,'damage',?,?,?,?,?,?)")->execute([$o['id'],$s['id'],$up['path'],$up['mime'],$up['size'],$up['sha256'],upload_metadata_json($up),upload_quality_flags_json($up)]);}
    db()->prepare("INSERT INTO system_events(seller_id,order_id,event_type,payload_json) VALUES(?,?,'damage.reported',?)")->execute([$s['id'],$o['id'],json_encode(['damage_case_id'=>$caseId],JSON_UNESCAPED_UNICODE)]);flash('success','Beschädigung wurde gemeldet. Der Auftrag läuft bis zur Entscheidung weiter.');redirect('/auftrag/'.$o['order_no']);
}
if ($path==='/admin/verkaeuferinnen'&&$method==='GET') {
    require_admin();$rows=db()->query("SELECT s.*,COUNT(o.id) orders_count FROM sellers s LEFT JOIN orders o ON o.seller_id=s.id WHERE s.deleted_at IS NULL GROUP BY s.id ORDER BY s.created_at DESC")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Verkäuferinnen</h1></div></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>E-Mail</th><th>Verifiziert</th><th>Aufträge</th><th></th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['first_name'].' '.$r['last_name'])?></td><td><?=e($r['email'])?></td><td><?=$r['email_verified_at']?'Ja':'Nein'?></td><td><?=e($r['orders_count'])?></td><td><a href="<?=e(url('/admin/verkaeuferin/'.$r['id']))?>">Akte</a></td></tr><?php endforeach;?></tbody></table></div><?php render('Verkäuferinnen',ob_get_clean());exit;
}
if (preg_match('#^/admin/auszahlung/(\d+)/bezahlt$#',$path,$m)&&$method==='POST') {
    require_admin();
    $st=db()->prepare("SELECT * FROM payout_requests WHERE id=?");$st->execute([(int)$m[1]]);$r=$st->fetch();if(!$r)not_found();

    if($r['status']!=='paid'){
        db()->beginTransaction();
        try{
            db()->prepare("UPDATE payout_requests SET status='paid',updated_at=NOW() WHERE id=?")->execute([$r['id']]);

            $remaining=(float)$r['amount'];
            $entries=db()->prepare("
                SELECT we.order_id,
                       SUM(we.amount) available_for_order,
                       COALESCE((SELECT SUM(p.amount) FROM wallet_entries p WHERE p.order_id=we.order_id AND p.entry_type='paid'),0) paid_for_order,
                       MIN(we.created_at) first_available_at
                FROM wallet_entries we
                JOIN orders o ON o.id=we.order_id
                WHERE we.seller_id=? AND we.entry_type='available' AND we.order_id IS NOT NULL
                GROUP BY we.order_id
                ORDER BY first_available_at,we.order_id
            ");
            $entries->execute([$r['seller_id']]);

            foreach($entries->fetchAll() as $entry){
                if($remaining<=0) break;
                $unpaid=max(0,(float)$entry['available_for_order']-(float)$entry['paid_for_order']);
                if($unpaid<=0) continue;
                $allocate=min($remaining,$unpaid);
                db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'paid',?,'Auszahlung #".$r['id']."')")
                    ->execute([$r['seller_id'],$entry['order_id'],$allocate]);
                $remaining-=$allocate;
            }
            if($remaining>0.0001){
                db()->prepare("INSERT INTO wallet_entries(seller_id,entry_type,amount,description) VALUES(?,'paid',?,'Auszahlung #".$r['id']." – nicht auftragsbezogen')")
                    ->execute([$r['seller_id'],$remaining]);
            }

            $orders=db()->prepare("
                SELECT o.id,o.order_no,
                       COALESCE((SELECT SUM(w.amount) FROM wallet_entries w WHERE w.order_id=o.id AND w.entry_type='available'),0) available_amount,
                       COALESCE((SELECT SUM(w.amount) FROM wallet_entries w WHERE w.order_id=o.id AND w.entry_type='paid'),0) paid_amount
                FROM orders o
                WHERE o.seller_id=? AND o.status='completed' AND o.archived_at IS NULL
            ");
            $orders->execute([$r['seller_id']]);
            foreach($orders->fetchAll() as $o){
                $target=(float)$o['available_amount'];
                if($target>0 && (float)$o['paid_amount']+0.0001 >= $target){
                    db()->prepare("UPDATE orders SET archived_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$o['id']]);
                    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Auftrag vollständig ausgezahlt und automatisch archiviert.')")->execute([$o['id']]);
                }
            }
            db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        notify_seller((int)$r['seller_id'],'payout.paid','Auszahlung durchgeführt','Deine Auszahlung über '.money($r['net_amount']).' wurde als ausgezahlt markiert.','/wallet',null,true);
    }
    flash('success','Auszahlung als bezahlt markiert. Vollständig ausgezahlte Aufträge wurden automatisch archiviert.');
    redirect('/admin/auszahlungen');
}
if (preg_match('#^/admin/auftrag/(\d{8})/chat$#',$path,$m)&&$method==='GET') {
    require_admin();$st=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();$q=db()->prepare("SELECT * FROM chat_messages WHERE order_id=? ORDER BY created_at");$q->execute([$o['id']]);$messages=$q->fetchAll();$locked=!empty($o['archived_at']);
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Admin · <?=e($o['order_no'])?></div><h1>Auftragschat</h1></div><a class="btn secondary" href="<?=e(url('/admin/auftrag/'.$o['order_no']))?>">Zurück</a></div><div class="timeline"><?php foreach($messages as $msg):?><div><strong><?=e($msg['sender_type'])?></strong><div><?=nl2br(e($msg['message']))?></div><small class="meta"><?=e(date('d.m.Y H:i',strtotime($msg['created_at'])))?></small></div><?php endforeach;?></div><?php if(!$locked):?><form class="panel" method="post"><?=csrf_field()?><textarea name="message" required></textarea><button class="btn">Senden</button></form><?php else:?><div class="panel"><strong>Archiviert – Chat ist schreibgeschützt.</strong></div><?php endif;?><?php render('Admin Chat',ob_get_clean());exit;
}
if (preg_match('#^/admin/auftrag/(\d{8})/chat$#',$path,$m)&&$method==='POST') {
    $a=require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();if(!empty($o['archived_at'])){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no'].'/chat');}$msg=post('message');if($msg!=='')db()->prepare("INSERT INTO chat_messages(order_id,sender_type,sender_id,message) VALUES(?,'admin',?,?)")->execute([$o['id'],$a['id'],$msg]);redirect('/admin/auftrag/'.$o['order_no'].'/chat');
}
if (preg_match('#^/admin/auftrag/(\d{8})/zusatztag$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();$paid=($_POST['paid']??'')==='1';$amount=$paid?(float)post('amount'):0;
    db()->prepare("INSERT INTO extra_days(order_id,source_type,paid,amount,reason) VALUES(?,'manual',?,?,?)")->execute([$o['id'],$paid?1:0,$amount,post('reason')]);
    $extraDayId=(int)db()->lastInsertId();schedule_extra_day($extraDayId);
    if($paid&&$amount>0){db()->prepare("UPDATE orders SET total_compensation=total_compensation+?,updated_at=NOW() WHERE id=?")->execute([$amount,$o['id']]);db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Bezahlter manueller Zusatztag')")->execute([$o['seller_id'],$o['id'],$amount]);}
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Manueller Zusatztag hinzugefügt: '.($paid?('bezahlt '.money($amount)):'unbezahlt').(post('reason')!==''?' – '.post('reason'):'')]);flash('success','Zusatztag hinzugefügt.');redirect('/admin/auftrag/'.$o['order_no']);
}
if (preg_match('#^/admin/auftrag/(\d{8})/verstoss$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();db()->prepare("INSERT INTO violations(order_id,violation_type,status,reason,extension_days) VALUES(?,?,'confirmed',?,1)")->execute([$o['id'],post('violation_type','manual'),post('reason')]);$vid=(int)db()->lastInsertId();db()->prepare("INSERT INTO extra_days(order_id,source_type,source_id,paid,amount,reason) VALUES(?,'violation',?,0,0,?)")->execute([$o['id'],$vid,post('reason')]);$extraDayId=(int)db()->lastInsertId();schedule_extra_day($extraDayId);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Bestätigter Verstoß: '.post('reason').' · +1 zusätzlicher Durchführungstag']);flash('success','Verstoß bestätigt und +1 Tag angehängt.');redirect('/admin/auftrag/'.$o['order_no']);
}
if (preg_match('#^/admin/beschaedigung/(\d+)/(anerkennen|ablehnen)$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT d.*,o.order_no,o.id order_id,o.seller_id FROM damage_cases d JOIN orders o ON o.id=d.order_id WHERE d.id=?");$st->execute([(int)$m[1]]);$d=$st->fetch();if(!$d)not_found();
    if($m[2]==='ablehnen'){db()->prepare("UPDATE damage_cases SET status='rejected',decided_at=NOW() WHERE id=?")->execute([$d['id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Beschädigung nicht anerkannt. Der Auftrag wird mit demselben Artikel fortgeführt.')")->execute([$d['order_id']]);}
    else {
        db()->beginTransaction();
        try{
            $oldRunId=current_run_id((int)$d['order_id']);
            $rn=(int)db()->query("SELECT COALESCE(MAX(run_no),0)+1 FROM order_runs WHERE order_id=".(int)$d['order_id'])->fetchColumn();

            db()->prepare("UPDATE damage_cases SET status='restarted',decided_at=NOW() WHERE id=?")->execute([$d['id']]);

            if($oldRunId){
                db()->prepare("UPDATE order_runs SET status='restarted',ended_at=NOW() WHERE id=?")->execute([$oldRunId]);
                db()->prepare("UPDATE evidence_windows SET status='waived' WHERE order_id=? AND order_run_id=? AND status IN('planned','open')")
                    ->execute([$d['order_id'],$oldRunId]);
                db()->prepare("UPDATE order_days SET status='completed' WHERE order_id=? AND order_run_id=? AND status IN('planned','active')")
                    ->execute([$d['order_id'],$oldRunId]);
            }

            db()->prepare("INSERT INTO order_runs(order_id,run_no,status,restart_reason) VALUES(?,?,'precheck',?)")
                ->execute([$d['order_id'],$rn,$d['reason']]);

            db()->prepare("UPDATE orders
                           SET status='precheck',
                               planned_start_date=NULL,
                               precheck_approved_at=NULL,
                               started_at=NULL,
                               updated_at=NOW()
                           WHERE id=?")
                ->execute([$d['order_id']]);

            db()->prepare("UPDATE order_start_date_requests SET status='rejected',decided_at=NOW(),admin_note='Durch Neustart nach anerkannter Beschädigung überholt'
                           WHERE order_id=? AND status='pending'")
                ->execute([$d['order_id']]);

            db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
                ->execute([$d['order_id'],'Beschädigung anerkannt. Neuer Durchlauf '.$rn.' wurde vollständig neu angelegt. Vorabkontrolle und Startdatum müssen erneut festgelegt werden.']);

            log_event('damage.restart',(int)$d['seller_id'],(int)$d['order_id'],[
                'damage_case_id'=>(int)$d['id'],
                'old_run_id'=>$oldRunId,
                'new_run_no'=>$rn,
                'reason'=>$d['reason'],
            ]);

            db()->commit();
        }catch(Throwable $e){
            if(db()->inTransaction()) db()->rollBack();
            throw $e;
        }
    }
    flash('success','Beschädigungsvorgang entschieden.');redirect('/admin/auftrag/'.$d['order_no']);
}
if (preg_match('#^/admin/auftrag/(\d{8})/abschliessen$#',$path,$m)&&$method==='POST') {
    require_admin();
    $st=db()->prepare("SELECT o.*,f.fulfillment_type FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();
    $decision=post('decision');$reason=post('reason');

    if(in_array($decision,['accept','partial'],true)){
        if($o['status']!=='review'){
            flash('error','Eine Vergütung kann erst in der Abschlussprüfung freigegeben werden.');
            redirect('/admin/auftrag/'.$o['order_no']);
        }

        $q=db()->prepare("SELECT COUNT(*) FROM violations WHERE order_id=? AND status IN('open','reviewed')");
        $q->execute([$o['id']]);
        if((int)$q->fetchColumn()>0){
            flash('error','Vor einer Vergütungsfreigabe müssen alle offenen möglichen Verstöße entschieden sein.');
            redirect('/admin/auftrag/'.$o['order_no']);
        }

        $q=db()->prepare("SELECT COUNT(*) FROM evidences WHERE order_id=? AND status='submitted'");
        $q->execute([$o['id']]);
        if((int)$q->fetchColumn()>0){
            flash('error','Vor einer Vergütungsfreigabe müssen alle eingereichten Nachweise geprüft sein.');
            redirect('/admin/auftrag/'.$o['order_no']);
        }

        $q=db()->prepare("SELECT COUNT(*) total,
            SUM(required=1 AND status<>'completed') open_required,
            SUM(component_type='digital') digital_count,
            SUM(component_type='physical') physical_count
            FROM order_components WHERE order_id=?");
        $q->execute([$o['id']]);$componentStats=$q->fetch();
        $componentCount=(int)($componentStats['total']??0);
        if($componentCount>1 && (int)($componentStats['open_required']??0)>0){
            flash('error','Der Kombi-Auftrag kann erst abgeschlossen werden, wenn alle Pflichtbestandteile den Status „Abgeschlossen“ haben.');
            redirect('/admin/auftrag/'.$o['order_no']);
        }
        $hasDigital=$componentCount>0 ? (int)($componentStats['digital_count']??0)>0 : in_array($o['fulfillment_type'],['digital','mixed'],true);
        $hasPhysical=$componentCount>0 ? (int)($componentStats['physical_count']??0)>0 : $o['fulfillment_type']!=='digital';

        $q=db()->prepare("SELECT COUNT(*) FROM order_tasks WHERE order_id=? AND status<>'accepted'");
        $q->execute([$o['id']]);
        if((int)$q->fetchColumn()>0){
            flash('error','Vor einer Vergütungsfreigabe müssen alle Zusatzaufgaben abschließend geprüft sein.');
            redirect('/admin/auftrag/'.$o['order_no']);
        }

        if($hasDigital){
            $q=db()->prepare("SELECT * FROM digital_versions WHERE order_id=? ORDER BY version_no DESC LIMIT 1");
            $q->execute([$o['id']]);$latestDigital=$q->fetch();
            $allowedDigital=$decision==='accept' ? ['accepted'] : ['accepted','partial'];
            if(!$latestDigital || !in_array((string)$latestDigital['status'],$allowedDigital,true)){
                flash('error',$decision==='accept'
                    ? 'Die aktuelle digitale Version muss vor der vollständigen Freigabe ausdrücklich akzeptiert sein.'
                    : 'Die aktuelle digitale Version muss vor der Teilfreigabe abschließend geprüft sein.');
                redirect('/admin/auftrag/'.$o['order_no']);
            }
            $q=db()->prepare("SELECT COUNT(*) FROM revision_rounds WHERE order_id=? AND status IN('open','submitted')");
            $q->execute([$o['id']]);
            if((int)$q->fetchColumn()>0){
                flash('error','Es ist noch eine digitale Revision offen oder zur Prüfung eingereicht.');
                redirect('/admin/auftrag/'.$o['order_no']);
            }
        }
        if($hasPhysical){
            $q=db()->prepare("SELECT * FROM shipments WHERE order_id=?");
            $q->execute([$o['id']]);$shipment=$q->fetch();
            if(!$shipment || $shipment['status']!=='received'){
                flash('error','Bei physischen Aufträgen ist eine Vergütungsfreigabe erst nach bestätigtem Wareneingang möglich.');
                redirect('/admin/auftrag/'.$o['order_no']);
            }
            $shippingSnapshot=order_shipping_snapshot($o);
            if(($shippingSnapshot['cost_mode']??'seller')==='reimburse'
                && $shipment['claimed_shipping_cost']!==null
                && $shipment['approved_reimbursement']===null){
                flash('error','Bitte zuerst über die beantragte Versandkostenerstattung entscheiden.');
                redirect('/admin/auftrag/'.$o['order_no']);
            }
        }
    }

    if($decision==='accept'){
        db()->beginTransaction();
        try{
            db()->prepare("UPDATE orders SET status='completed',released_amount=total_compensation,completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$o['id']]);
            db()->prepare("UPDATE wallet_entries SET entry_type='available',description='Auftrag vollständig freigegeben' WHERE order_id=? AND entry_type='reserved'")->execute([$o['id']]);
            db()->prepare("UPDATE order_bonuses SET status='released',released_at=NOW() WHERE order_id=? AND status='reserved'")->execute([$o['id']]);
            db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Auftrag wurde vollständig akzeptiert. Die vollständige Vergütung ist im Wallet verfügbar.')")->execute([$o['id']]);
            db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        notify_seller((int)$o['seller_id'],'order.accepted_final','Auftrag vollständig akzeptiert','Auftrag '.$o['order_no'].' wurde vollständig akzeptiert. Die Vergütung ist verfügbar.','/wallet',null,true);
    }elseif($decision==='partial'){
        $amount=(float)post('partial_amount');
        if($amount<0||$amount>(float)$o['total_compensation']){flash('error','Der Teilfreigabebetrag ist ungültig.');redirect('/admin/auftrag/'.$o['order_no']);}
        db()->beginTransaction();
        try{
            db()->prepare("UPDATE orders SET status='completed',released_amount=?,completed_at=NOW(),rejection_reason=?,updated_at=NOW() WHERE id=?")->execute([$amount,$reason?:'Teilweise akzeptiert',$o['id']]);
            db()->prepare("UPDATE wallet_entries SET entry_type='cancelled',description='Durch Teilfreigabe ersetzt' WHERE order_id=? AND entry_type='reserved'")->execute([$o['id']]);
            db()->prepare("UPDATE order_bonuses SET status='cancelled',cancelled_at=NOW() WHERE order_id=? AND status='reserved'")->execute([$o['id']]);
            if($amount>0) db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'available',?,'Teilfreigabe Auftrag')")->execute([$o['seller_id'],$o['id'],$amount]);
            db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Auftrag teilweise akzeptiert. Freigegebener Betrag: '.money($amount).($reason!==''?' · '.$reason:'')]);
            db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        notify_seller((int)$o['seller_id'],'order.partial','Auftrag teilweise akzeptiert','Für Auftrag '.$o['order_no'].' wurden '.money($amount).' freigegeben.'.($reason!==''?' '.$reason:''),'/wallet',null,true);
    }elseif($decision==='reject'){
        db()->beginTransaction();
        try{
            db()->prepare("UPDATE orders SET status='rejected',released_amount=0,rejection_reason=?,completed_at=NOW(),archived_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$reason,$o['id']]);
            db()->prepare("UPDATE wallet_entries SET entry_type='cancelled',description='Auftrag endgültig abgelehnt' WHERE order_id=? AND entry_type='reserved'")->execute([$o['id']]);
            db()->prepare("UPDATE order_bonuses SET status='cancelled',cancelled_at=NOW() WHERE order_id=? AND status='reserved'")->execute([$o['id']]);
            db()->prepare("UPDATE order_components SET status='rejected',updated_at=NOW() WHERE order_id=? AND status<>'completed'")->execute([$o['id']]);
            db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Auftrag endgültig abgelehnt.'.($reason!==''?' Grund: '.$reason:'')]);
            db()->commit();
        }catch(Throwable $e){db()->rollBack();throw $e;}
        notify_seller((int)$o['seller_id'],'order.rejected','Auftrag endgültig abgelehnt','Auftrag '.$o['order_no'].' wurde endgültig abgelehnt.'.($reason!==''?' Grund: '.$reason:''),'/auftrag/'.$o['order_no'],null,true);
    }else{
        flash('error','Unbekannte Abschlussentscheidung.');redirect('/admin/auftrag/'.$o['order_no']);
    }
    flash('success','Abschlussentscheidung gespeichert.');redirect('/admin/auftrag/'.$o['order_no']);
}
if ($path==='/admin/einstellungen'&&$method==='GET') {
    require_admin();$rows=db()->query("SELECT * FROM settings ORDER BY setting_key")->fetchAll();$set=[];foreach($rows as $r)$set[$r['setting_key']]=$r['setting_value'];
    ob_start();?><div class="eyebrow">Administration</div><h1>Systemeinstellungen</h1>
    <form class="panel" method="post"><?=csrf_field()?>
      <h2>Auszahlungen</h2><div class="form-grid">
        <label>Mindestauszahlung (€)<input type="number" step=".01" min="0" name="payout_min" value="<?=e($set['payout_min']??'10.00')?>"></label>
        <label>Bearbeitungstage / Hinweis<input name="payout_processing_days" value="<?=e($set['payout_processing_days']??'Nach individueller Prüfung')?>"></label>
        <label><input type="checkbox" style="width:auto" name="payout_bank_enabled" value="1" <?=($set['payout_bank_enabled']??'1')==='1'?'checked':''?>> Banküberweisung aktiv</label>
        <label><input type="checkbox" style="width:auto" name="payout_paypal_enabled" value="1" <?=($set['payout_paypal_enabled']??'1')==='1'?'checked':''?>> PayPal aktiv</label>
        <label>Bank-Gebühr Typ<select name="payout_bank_fee_type"><?php foreach(['none'=>'Keine','fixed'=>'Festbetrag','percent'=>'Prozent'] as $k=>$v):?><option value="<?=$k?>" <?=($set['payout_bank_fee_type']??'none')===$k?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
        <label>Bank-Gebühr Wert<input type="number" step=".01" min="0" name="payout_bank_fee_value" value="<?=e($set['payout_bank_fee_value']??'0')?>"></label>
        <label>PayPal-Gebühr Typ<select name="payout_paypal_fee_type"><?php foreach(['none'=>'Keine','fixed'=>'Festbetrag','percent'=>'Prozent'] as $k=>$v):?><option value="<?=$k?>" <?=($set['payout_paypal_fee_type']??'none')===$k?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></label>
        <label>PayPal-Gebühr Wert<input type="number" step=".01" min="0" name="payout_paypal_fee_value" value="<?=e($set['payout_paypal_fee_value']??'0')?>"></label>
      </div>
      <h2>Betreiber / öffentliche Angaben</h2><div class="form-grid">
        <label>Name / Firma<input name="operator_name" value="<?=e($set['operator_name']??'')?>"></label>
        <label>Straße / Hausnummer<input name="operator_street" value="<?=e($set['operator_street']??'')?>"></label>
        <label>PLZ / Ort<input name="operator_city" value="<?=e($set['operator_city']??'')?>"></label>
        <label>Öffentliche E-Mail<input type="email" name="operator_email" value="<?=e($set['operator_email']??'')?>"></label>
        <label>Telefon<input name="operator_phone" value="<?=e($set['operator_phone']??'')?>"></label>
      </div>
      <h2>Fristen & Kommunikation</h2><div class="form-grid">
        <label>Support-E-Mail<input type="email" name="support_email" value="<?=e($set['support_email']??app_config('mail.from',''))?>"></label>
        <label>Grace Period Minuten<input type="number" min="0" name="grace_minutes" value="<?=e($set['grace_minutes']??'60')?>"></label>
        <label>Morgenfenster<input name="window_morning" value="<?=e($set['window_morning']??'06:00-10:00')?>"></label>
        <label>Mittagsfenster<input name="window_midday" value="<?=e($set['window_midday']??'12:00-16:00')?>"></label>
        <label>Abendfenster<input name="window_evening" value="<?=e($set['window_evening']??'18:00-23:59')?>"></label>
        <label>Zwischenstand alle X abgeschlossenen Tage<select name="interim_summary_interval"><?php foreach([0=>'Aus',5=>'5 Tage',7=>'7 Tage',10=>'10 Tage',14=>'14 Tage'] as $k=>$label):?><option value="<?=$k?>" <?=((string)($set['interim_summary_interval']??'7')===(string)$k)?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
      </div>
      <h2>Nachweisbilder</h2><div class="form-grid">
        <label>Mindestbreite (px)<input type="number" min="320" name="image_min_width" value="<?=e($set['image_min_width']??'720')?>"></label>
        <label>Mindesthöhe (px)<input type="number" min="320" name="image_min_height" value="<?=e($set['image_min_height']??'720')?>"></label>
        <label>Dunkelheits-Schwelle<input type="number" step=".1" min="0" max="255" name="image_dark_luminance_threshold" value="<?=e($set['image_dark_luminance_threshold']??'28')?>"></label>
        <label>Unschärfe-Schwelle<input type="number" step=".1" min="0" name="image_blur_variance_threshold" value="<?=e($set['image_blur_variance_threshold']??'45')?>"></label>
      </div><p class="meta">Zu kleine Bilder werden abgewiesen. Dunkelheit und mögliche Unschärfe werden als technische Hinweise markiert und vom Admin abschließend bewertet.</p>
      <button class="btn">Speichern</button>
    </form><?php render('Einstellungen',ob_get_clean());exit;
}
if ($path==='/admin/einstellungen'&&$method==='POST') {
    require_admin();
    $values=[
      'payout_min'=>post('payout_min','10.00'),
      'payout_processing_days'=>post('payout_processing_days'),
      'payout_bank_enabled'=>isset($_POST['payout_bank_enabled'])?'1':'0',
      'payout_paypal_enabled'=>isset($_POST['payout_paypal_enabled'])?'1':'0',
      'payout_bank_fee_type'=>post('payout_bank_fee_type','none'),
      'payout_bank_fee_value'=>post('payout_bank_fee_value','0'),
      'payout_paypal_fee_type'=>post('payout_paypal_fee_type','none'),
      'payout_paypal_fee_value'=>post('payout_paypal_fee_value','0'),
      'operator_name'=>post('operator_name'),
      'operator_street'=>post('operator_street'),
      'operator_city'=>post('operator_city'),
      'operator_email'=>post('operator_email'),
      'operator_phone'=>post('operator_phone'),
      'support_email'=>post('support_email'),
      'window_morning'=>post('window_morning','06:00-10:00'),
      'window_midday'=>post('window_midday','12:00-16:00'),
      'window_evening'=>post('window_evening','18:00-23:59'),
      'grace_minutes'=>post('grace_minutes','60'),
      'interim_summary_interval'=>post('interim_summary_interval','7'),
      'image_min_width'=>post('image_min_width','720'),
      'image_min_height'=>post('image_min_height','720'),
      'image_dark_luminance_threshold'=>post('image_dark_luminance_threshold','28'),
      'image_blur_variance_threshold'=>post('image_blur_variance_threshold','45'),
    ];
    foreach($values as $k=>$v) db()->prepare("INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$k,$v]);
    flash('success','Einstellungen gespeichert.');redirect('/admin/einstellungen');
}


if (preg_match('#^/auftrag/(\d{8})/versand$#',$path,$m)&&$method==='GET') {
    $s=require_seller();
    $st=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");
    $st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();

    if($o['status']==='shipping') ensure_order_shipping_steps((int)$o['id']);
    $q=db()->prepare("SELECT * FROM order_shipping_steps WHERE order_id=? ORDER BY sort_order,id");$q->execute([$o['id']]);$steps=$q->fetchAll();
    $q=db()->prepare("SELECT * FROM shipments WHERE order_id=?");$q->execute([$o['id']]);$ship=$q->fetch();
    $shippingSnapshot=order_shipping_snapshot($o);
    $shippingAddress=is_array($shippingSnapshot['address']??null)?$shippingSnapshot['address']:null;

    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1>Versandworkflow</h1><p class="meta">Die Schritte werden nacheinander freigeschaltet.</p></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div>

    <?php if($o['status']!=='shipping' && !$ship):?><div class="panel"><strong>Der Versand ist noch nicht freigeschaltet.</strong><p class="meta">Der Versandworkflow wird nach Abschluss der Durchführung automatisch geöffnet. Die konkrete Empfängeradresse wird vorher nicht angezeigt.</p></div><?php endif;?>
    <?php if($o['status']==='shipping' || $ship):?><section class="panel"><h2>Versandinformationen</h2>
      <?php if($shippingAddress):?><p><strong><?=e($shippingAddress['recipient_name'])?></strong><br><?=e($shippingAddress['street'])?><?php if(!empty($shippingAddress['address_extra'])):?><br><?=e($shippingAddress['address_extra'])?><?php endif;?><br><?=e($shippingAddress['postal_code'].' '.$shippingAddress['city'])?><br><?=e(($shippingAddress['country_code']??'DE')==='DE'?'Deutschland':$shippingAddress['country_code'])?></p><?php else:?><p class="meta">Für diesen Auftrag ist keine feste Empfängeradresse hinterlegt. Bitte den Admin über den Auftragschat kontaktieren.</p><?php endif;?>
      <p><span class="meta">Versandkosten</span><br><?php if(($shippingSnapshot['cost_mode']??'seller')==='fixed'):?>Fester Versandzuschuss: <?=money($shippingSnapshot['allowance']??0)?><?php elseif(($shippingSnapshot['cost_mode']??'seller')==='reimburse'):?>Volle Erstattung gegen vorgesehenen Nachweis<?php else:?>Versandkosten trägt die Verkäuferin<?php endif;?></p>
      <?php if(!empty($shippingSnapshot['preferred_carrier'])):?><p><span class="meta">Bevorzugter Versanddienstleister</span><br><?=e($shippingSnapshot['preferred_carrier'])?></p><?php endif;?>
      <?php if(!empty($shippingSnapshot['instructions'])):?><p><span class="meta">Verpackungs-/Versandhinweise</span><br><?=nl2br(e($shippingSnapshot['instructions']))?></p><?php endif;?>
    </section><?php endif;?>

    <div class="timeline">
    <?php foreach($steps as $step):?>
      <section class="panel">
        <div class="dashboard-head"><div><strong><?=e($step['title'])?></strong><p class="meta"><?=e($step['instructions']??'')?></p></div><span class="badge"><?=e($step['status'])?></span></div>
        <?php if($step['status']==='completed'):?>
          <p class="meta">Abgeschlossen <?=e($step['completed_at']?date('d.m.Y H:i',strtotime($step['completed_at'])):'')?></p>
        <?php elseif($step['status']==='open' && empty($o['archived_at'])):?>
          <form method="post" action="<?=e(url('/auftrag/'.$o['order_no'].'/versand-schritt/'.$step['id']))?>" enctype="multipart/form-data">
            <?=csrf_field()?>
            <?php if($step['requires_text']):?><label>Angabe / Notiz<textarea name="text_value" required></textarea></label><?php endif;?>
            <?php if($step['requires_checkbox']):?><label><input type="checkbox" name="confirmed" value="1" required style="width:auto"> Schritt wie beschrieben durchgeführt</label><?php endif;?>
            <?php for($i=1;$i<=(int)$step['required_photos'];$i++):?><label>Pflichtfoto <?=$i?><input data-camera-input type="file" name="evidence_<?=$i?>" required></label><?php endfor;?>
            <?php if($step['is_dispatch_step']):?>
              <div class="form-grid"><label>Versanddienstleister<input name="carrier" placeholder="z. B. DHL"></label><label>Trackingnummer<input name="tracking_number"></label></div>
              <label>Einlieferungsbeleg / Versandnachweis<?=($shippingSnapshot['cost_mode']??'seller')==='reimburse'?' (für Erstattung erforderlich)':' (falls keine Trackingnummer)'?><input data-camera-input type="file" name="dispatch_proof"></label>
              <?php if(($shippingSnapshot['cost_mode']??'seller')==='reimburse'):?><label>Tatsächliche Versandkosten (€)<input type="number" step=".01" min="0.01" name="shipping_cost" required></label><?php endif;?>
              <p class="meta"><?=($shippingSnapshot['cost_mode']??'seller')==='reimburse'?'Für die Erstattung müssen Kostenbetrag und Einlieferungs-/Kostenbeleg eingereicht werden.':'Für den finalen Versandnachweis ist mindestens eine Trackingnummer oder ein Einlieferungsnachweis erforderlich.'?></p>
            <?php endif;?>
            <button class="btn">Schritt abschließen</button>
          </form>
        <?php else:?><p class="meta">Dieser Schritt wird nach Abschluss des vorherigen Schritts freigeschaltet.</p><?php endif;?>
      </section>
    <?php endforeach;?>
    </div>

    <?php if($ship):?><section class="panel"><h2>Sendungsstatus</h2><p>Status: <strong><?=e($ship['status'])?></strong><br>Tracking: <?=e($ship['tracking_number']?:'–')?><?php if($ship['carrier']):?><br>Dienstleister: <?=e($ship['carrier'])?><?php endif;?><?php if($ship['claimed_shipping_cost']!==null):?><br>Beantragte Versandkosten: <?=money($ship['claimed_shipping_cost'])?><?php endif;?><?php if($ship['approved_reimbursement']!==null):?><br>Bestätigte Erstattung: <?=money($ship['approved_reimbursement'])?><?php elseif($ship['claimed_shipping_cost']!==null):?><br>Erstattung: in Prüfung<?php endif;?></p><?php if($ship['proof_evidence_id']):?><a href="<?=e(url('/datei/'.$ship['proof_evidence_id']))?>" target="_blank">Versandnachweis ansehen</a><?php endif;?></section><?php endif;?>

    <?php render('Versandworkflow',ob_get_clean());exit;
}

if (preg_match('#^/auftrag/(\d{8})/versand-schritt/(\d+)$#',$path,$m)&&$method==='POST') {
    $s=require_seller();
    $st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=? AND status='shipping'");
    $st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o){flash('error','Der Versandworkflow ist nicht aktiv.');redirect('/dashboard');}

    $q=db()->prepare("SELECT * FROM order_shipping_steps WHERE id=? AND order_id=? AND status='open'");
    $q->execute([(int)$m[2],$o['id']]);$step=$q->fetch();if(!$step){flash('error','Dieser Versandschritt ist nicht freigeschaltet.');redirect('/auftrag/'.$o['order_no'].'/versand');}
    $shippingSnapshot=order_shipping_snapshot($o);

    if($step['requires_text'] && post('text_value')===''){flash('error','Die erforderliche Angabe fehlt.');redirect('/auftrag/'.$o['order_no'].'/versand');}
    if($step['requires_checkbox'] && ($_POST['confirmed']??'')!=='1'){flash('error','Bitte bestätige die Durchführung des Schritts.');redirect('/auftrag/'.$o['order_no'].'/versand');}

    $uploadedIds=[];
    try{
        for($i=1;$i<=(int)$step['required_photos'];$i++){
            $key='evidence_'.$i;
            if(!isset($_FILES[$key]) || ($_FILES[$key]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Pflichtfoto '.$i.' fehlt.');
            $up=private_upload($_FILES[$key],'order-'.$o['id'].'/shipping');
            db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,source_type,source_id,file_path,mime_type,file_size,sha256,metadata_json,quality_flags_json) VALUES(?,?,?,'shipping','shipping_step',?,?,?,?,?,?,?)")
              ->execute([$o['id'],current_run_id((int)$o['id']),$s['id'],$step['id'],$up['path'],$up['mime'],$up['size'],$up['sha256'],upload_metadata_json($up),upload_quality_flags_json($up)]);
            $uploadedIds[]=(int)db()->lastInsertId();
        }

        $tracking=post('tracking_number');$carrier=post('carrier');$dispatchProofId=null;
        if($step['is_dispatch_step']){
            if(isset($_FILES['dispatch_proof']) && ($_FILES['dispatch_proof']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
                $up=private_upload($_FILES['dispatch_proof'],'order-'.$o['id'].'/shipping');
                db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,source_type,source_id,file_path,mime_type,file_size,sha256,metadata_json,quality_flags_json) VALUES(?,?,?,'shipping','shipping_step',?,?,?,?,?,?,?)")
                  ->execute([$o['id'],current_run_id((int)$o['id']),$s['id'],$step['id'],$up['path'],$up['mime'],$up['size'],$up['sha256'],upload_metadata_json($up),upload_quality_flags_json($up)]);
                $dispatchProofId=(int)db()->lastInsertId();
            }
            if($tracking==='' && !$dispatchProofId) throw new RuntimeException('Bitte Trackingnummer oder Einlieferungsnachweis angeben.');
            $shippingCost=max(0,(float)post('shipping_cost','0'));
            if(($shippingSnapshot['cost_mode']??'seller')==='reimburse' && (!$dispatchProofId || $shippingCost<=0)) throw new RuntimeException('Für die Versandkostenerstattung sind Kostenbetrag und Einlieferungs-/Kostenbeleg erforderlich.');
        }else{
            $shippingCost=0.0;
        }

        $payload=json_encode(['text'=>post('text_value'),'confirmed'=>(($_POST['confirmed']??'')==='1'),'carrier'=>$carrier,'tracking_number'=>$tracking,'shipping_cost'=>$shippingCost],JSON_UNESCAPED_UNICODE);
        db()->beginTransaction();
        db()->prepare("UPDATE order_shipping_steps SET status='completed',submission_json=?,completed_at=NOW() WHERE id=?")->execute([$payload,$step['id']]);
        unlock_next_shipping_step((int)$o['id'],(int)$step['sort_order']);

        if($step['is_dispatch_step']){
            $proof=$dispatchProofId ?: ($uploadedIds[0]??null);
            db()->prepare("INSERT INTO shipments(order_id,tracking_number,carrier,claimed_shipping_cost,proof_evidence_id,status,shipped_at) VALUES(?,?,?,?,?,'shipped',NOW())
                           ON DUPLICATE KEY UPDATE tracking_number=VALUES(tracking_number),carrier=VALUES(carrier),claimed_shipping_cost=VALUES(claimed_shipping_cost),proof_evidence_id=VALUES(proof_evidence_id),status='shipped',shipped_at=NOW()")
              ->execute([$o['id'],$tracking?:null,$carrier?:null,($shippingSnapshot['cost_mode']??'seller')==='reimburse'?$shippingCost:null,$proof]);
            db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Versand nachgewiesen – wartet auf Eingang.')")->execute([$o['id']]);
        }else{
            db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Versandschritt abgeschlossen: '.$step['title']]);
        }
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());redirect('/auftrag/'.$o['order_no'].'/versand');
    }

    flash('success','Versandschritt abgeschlossen.');redirect('/auftrag/'.$o['order_no'].'/versand');
}

if (preg_match('#^/admin/auftrag/(\d{8})/versanderstattung$#',$path,$m)&&$method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT o.*,sh.id shipment_id,sh.claimed_shipping_cost,sh.approved_reimbursement,sh.proof_evidence_id
                      FROM orders o JOIN shipments sh ON sh.order_id=o.id
                      WHERE o.order_no=?");
    $q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    if(in_array($o['status'],['completed','rejected'],true) || !empty($o['archived_at'])){
        flash('error','Bei einem abgeschlossenen oder archivierten Auftrag kann die Versandkostenerstattung nicht mehr geändert werden.');
        redirect('/admin/auftrag/'.$o['order_no']);
    }

    $shippingSnapshot=order_shipping_snapshot($o);
    if(($shippingSnapshot['cost_mode']??'seller')!=='reimburse' || $o['claimed_shipping_cost']===null){
        flash('error','Für diesen Auftrag liegt keine erstattungsfähige Versandkostenanforderung vor.');
        redirect('/admin/auftrag/'.$o['order_no']);
    }
    if($o['approved_reimbursement']!==null){
        flash('error','Über die Versandkostenerstattung wurde bereits entschieden.');
        redirect('/admin/auftrag/'.$o['order_no']);
    }

    $decision=post('decision');
    $approved=$decision==='approve' ? max(0,(float)$o['claimed_shipping_cost']) : 0.0;
    if(!in_array($decision,['approve','reject'],true)){
        flash('error','Ungültige Entscheidung.');
        redirect('/admin/auftrag/'.$o['order_no']);
    }
    if($decision==='approve' && !$o['proof_evidence_id']){
        flash('error','Ohne Versand-/Kostenbeleg kann keine Erstattung bestätigt werden.');
        redirect('/admin/auftrag/'.$o['order_no']);
    }

    db()->beginTransaction();
    try{
        db()->prepare("UPDATE shipments SET approved_reimbursement=? WHERE id=?")->execute([$approved,$o['shipment_id']]);
        if($approved>0){
            db()->prepare("UPDATE orders SET total_compensation=total_compensation+?,updated_at=NOW() WHERE id=?")->execute([$approved,$o['id']]);
            db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Versandkostenerstattung vorgemerkt')")
              ->execute([$o['seller_id'],$o['id'],$approved]);
        }
        $message=$approved>0
          ? 'Versandkostenerstattung in Höhe von '.money($approved).' bestätigt und bis zum Abschluss vorgemerkt.'
          : 'Versandkostenerstattung wurde abgelehnt.';
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],$message]);
        log_event('shipping.reimbursement_decided',(int)$o['seller_id'],(int)$o['id'],['decision'=>$decision,'claimed'=>(float)$o['claimed_shipping_cost'],'approved'=>$approved]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}

    notify_seller(
        (int)$o['seller_id'],
        'shipping.reimbursement_decided',
        $approved>0?'Versandkosten bestätigt':'Versandkostenerstattung abgelehnt',
        $approved>0
          ? 'Für Auftrag '.$o['order_no'].' wurden '.money($approved).' Versandkosten vorgemerkt. Die Freigabe erfolgt mit dem normalen Auftragsabschluss.'
          : 'Für Auftrag '.$o['order_no'].' wurde die beantragte Versandkostenerstattung abgelehnt.',
        '/auftrag/'.$o['order_no'].'/versand',
        null,
        true
    );
    flash('success',$approved>0?'Versandkostenerstattung bestätigt und vorgemerkt.':'Versandkostenerstattung abgelehnt.');
    redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/admin/auftrag/(\\d{8})/wareneingang$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();
    db()->beginTransaction();
    try{
        db()->prepare("UPDATE shipments SET status='received',received_at=NOW() WHERE order_id=?")->execute([$o['id']]);
        db()->prepare("UPDATE orders SET status='review',updated_at=NOW() WHERE id=?")->execute([$o['id']]);
        db()->prepare("UPDATE order_components SET status='review',updated_at=NOW() WHERE order_id=? AND component_type='physical' AND status IN('execution','shipping')")->execute([$o['id']]);
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Sendung ist eingegangen und befindet sich in der Abschlussprüfung.')")->execute([$o['id']]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    flash('success','Wareneingang bestätigt. Physische Kombi-Bestandteile befinden sich jetzt in der Prüfung.');redirect('/admin/auftrag/'.$o['order_no']);
}
if (preg_match('#^/auftrag/(\d{8})/digital$#',$path,$m)&&$method==='GET') {
    $s=require_seller();
    $st=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");
    $st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    $v=db()->prepare("SELECT * FROM digital_versions WHERE order_id=? ORDER BY version_no DESC");$v->execute([$o['id']]);$versions=$v->fetchAll();
    $rr=db()->prepare("SELECT r.*,COUNT(i.id) item_count FROM revision_rounds r LEFT JOIN revision_items i ON i.revision_round_id=r.id WHERE r.order_id=? GROUP BY r.id ORDER BY r.round_no DESC");$rr->execute([$o['id']]);$rounds=$rr->fetchAll();
    $ri=db()->prepare("SELECT i.*,r.round_no,r.status round_status,r.due_at FROM revision_items i JOIN revision_rounds r ON r.id=i.revision_round_id WHERE r.order_id=? ORDER BY r.round_no DESC,i.id");$ri->execute([$o['id']]);$revisionItems=$ri->fetchAll();
    $locked=!empty($o['archived_at']) || $o['status']==='rejected';

    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Digitale Abgabe · <?=e($o['order_no'])?></div><h1><?=e($o['title'])?></h1><?php if($locked):?><span class="badge">Schreibgeschützt</span><?php endif;?></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div>

    <?php if(!$locked):?>
    <form class="panel" method="post" enctype="multipart/form-data"><?=csrf_field()?>
      <h2>Neue Version einreichen</h2>
      <label>Textinhalt (optional)<textarea name="text_content"></textarea></label>
      <label>Datei (optional: Audio/Video/Bild)<input type="file" name="digital_file" accept="audio/*,video/mp4,image/jpeg,image/png,image/webp"></label>
      <p class="meta">Mindestens Text oder Datei erforderlich. Jede Einreichung erzeugt eine neue, unveränderliche Version.</p>
      <label><input type="checkbox" name="confirm_complete" value="1" required style="width:auto"> Ich habe die Abgabe geprüft und bestätige, dass sie vollständig eingereicht werden soll.</label>
      <button class="btn">Version final einreichen</button>
    </form>
    <?php else:?><div class="panel"><strong>Dieser Auftrag ist schreibgeschützt.</strong><p class="meta">Vorhandene Versionen bleiben lesbar, können aber nicht verändert oder ersetzt werden.</p></div><?php endif;?>

    <h2>Versionen</h2>
    <div class="timeline">
    <?php foreach($versions as $x):?>
      <article class="panel">
        <div class="dashboard-head"><div><strong>V<?=e($x['version_no'])?></strong> · <span class="badge"><?=e($x['status'])?></span></div><span class="meta"><?=e(date('d.m.Y H:i',strtotime($x['created_at'])))?></span></div>
        <?php if($x['text_content']):?><div style="white-space:pre-wrap"><?=e($x['text_content'])?></div><?php endif;?>
        <?php if($x['file_path']):?>
          <?php $mediaUrl=url('/digitale-datei/'.$x['id']); $mime=(string)($x['mime_type']??''); ?>
          <?php if(str_starts_with($mime,'audio/')):?><audio controls preload="metadata" style="width:100%"><source src="<?=e($mediaUrl)?>" type="<?=e($mime)?>"></audio>
          <?php elseif(str_starts_with($mime,'video/')):?><video controls preload="metadata" playsinline style="width:100%;max-height:520px;border-radius:12px"><source src="<?=e($mediaUrl)?>" type="<?=e($mime)?>"></video>
          <?php elseif(str_starts_with($mime,'image/')):?><img src="<?=e($mediaUrl)?>" alt="Digitale Version V<?=e($x['version_no'])?>" style="max-width:100%;max-height:560px;border-radius:12px">
          <?php else:?><a href="<?=e($mediaUrl)?>" target="_blank">Datei innerhalb der Plattform öffnen</a><?php endif;?>
          <p class="meta">Keine Downloadfunktion für Verkäuferinnen.</p>
        <?php endif;?>
        <?php if($x['review_note']):?><p><strong>Prüfhinweis:</strong> <?=e($x['review_note'])?></p><?php endif;?>
      </article>
    <?php endforeach;?>
    <?php if(!$versions):?><div class="empty">Noch keine digitale Version eingereicht.</div><?php endif;?>
    </div>

    <h2>Revisionen</h2><div class="table-wrap"><table><thead><tr><th>Runde</th><th>Status</th><th>Punkte</th><th>Frist</th></tr></thead><tbody><?php foreach($rounds as $r):?><tr><td>Runde <?=e($r['round_no'])?></td><td><?=e($r['status'])?></td><td><?=e($r['item_count'])?></td><td><?=e($r['due_at']?date('d.m.Y H:i',strtotime($r['due_at'])):'keine Frist')?></td></tr><?php endforeach;?></tbody></table></div>
    <?php if($revisionItems):?><h3>Änderungspunkte</h3><div class="timeline"><?php foreach($revisionItems as $item):?><div><strong>Runde <?=e($item['round_no'])?> · <?=e($item['description'])?></strong><?php if($item['location_ref']):?><br><span class="meta">Bezug: <?=e($item['location_ref'])?></span><?php endif;?><br><span class="badge"><?=e($item['status'])?></span><?= $item['due_at']?' · <span class="meta">Frist '.e(date('d.m.Y H:i',strtotime($item['due_at']))).'</span>':'' ?></div><?php endforeach;?></div><?php endif;?>
    <?php render('Digitale Abgabe',ob_get_clean());exit;
}
if (preg_match('#^/auftrag/(\d{8})/digital$#',$path,$m)&&$method==='POST') {
    $s=require_seller();
    $st=db()->prepare("SELECT * FROM orders WHERE order_no=? AND seller_id=?");
    $st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
    if(!empty($o['archived_at']) || $o['status']==='rejected'){flash('error','Dieser Auftrag ist schreibgeschützt.');redirect('/auftrag/'.$o['order_no'].'/digital');}
    if(($_POST['confirm_complete']??'')!=='1'){flash('error','Bitte bestätige die Vollständigkeitsprüfung vor der finalen Abgabe.');redirect('/auftrag/'.$o['order_no'].'/digital');}

    $text=post('text_content');$pathFile=null;$mime=null;$sha=null;
    if(isset($_FILES['digital_file'])&&($_FILES['digital_file']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){
        $up=private_upload($_FILES['digital_file'],'order-'.$o['id'].'/digital');
        $pathFile=$up['path'];$mime=$up['mime'];$sha=$up['sha256'];
    }
    if($text===''&&!$pathFile){flash('error','Bitte Text oder Datei einreichen.');redirect('/auftrag/'.$o['order_no'].'/digital');}

    $q=db()->prepare("SELECT COALESCE(MAX(version_no),0)+1 FROM digital_versions WHERE order_id=?");$q->execute([$o['id']]);$vn=(int)$q->fetchColumn();
    db()->prepare("INSERT INTO digital_versions(order_id,version_no,file_path,text_content,mime_type,sha256,status) VALUES(?,?,?,?,?,?,'submitted')")
      ->execute([$o['id'],$vn,$pathFile,$text?:null,$mime,$sha]);
    db()->prepare("UPDATE revision_rounds SET status='submitted' WHERE order_id=? AND status='open'")->execute([$o['id']]);
    db()->prepare("UPDATE orders SET status='review',updated_at=NOW() WHERE id=?")->execute([$o['id']]);
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Digitale Version V'.$vn.' wurde eingereicht und wartet auf Prüfung.']);
    log_event('digital.version_submitted',(int)$s['id'],(int)$o['id'],['version'=>$vn,'mime'=>$mime]);
    flash('success','Digitale Version V'.$vn.' eingereicht.');redirect('/auftrag/'.$o['order_no'].'/digital');
}
if (preg_match('#^/admin/auftrag/(\\d{8})/revision$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM orders WHERE order_no=?");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();
    $open=db()->prepare("SELECT COUNT(*) FROM revision_rounds WHERE order_id=? AND status IN('open','submitted')");$open->execute([$o['id']]);if((int)$open->fetchColumn()>0){flash('error','Es ist bereits eine aktive Revision offen oder zur Prüfung eingereicht.');redirect('/admin/auftrag/'.$o['order_no']);}
    $q=db()->prepare("SELECT COALESCE(MAX(round_no),0)+1 FROM revision_rounds WHERE order_id=?");$q->execute([$o['id']]);$rn=(int)$q->fetchColumn();$due=post('due_at')?:null;db()->prepare("INSERT INTO revision_rounds(order_id,round_no,due_at) VALUES(?,?,?)")->execute([$o['id'],$rn,$due]);$rid=(int)db()->lastInsertId();
    foreach(array_filter(array_map('trim',preg_split('/\\r?\\n/',post('items')))) as $item){db()->prepare("INSERT INTO revision_items(revision_round_id,description) VALUES(?,?)")->execute([$rid,$item]);}
    db()->prepare("UPDATE orders SET status='review',updated_at=NOW() WHERE id=?")->execute([$o['id']]);db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Revision '.$rn.' wurde angefordert.']);flash('success','Revision angefordert.');redirect('/admin/auftrag/'.$o['order_no']);
}


if (preg_match('#^/admin/angebot/(\\d+)$#',$path,$m)&&$method==='GET') {
    require_admin();$st=db()->prepare("SELECT * FROM offers WHERE id=?");$st->execute([(int)$m[1]]);$o=$st->fetch();if(!$o)not_found();
    $rules=offer_evidence_rules($o);
    $digitalRules=offer_digital_rules($o);
    $cats=db()->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order,name")->fetchAll();$op=db()->prepare("SELECT * FROM offer_options WHERE offer_id=? ORDER BY id");$op->execute([$o['id']]);$options=$op->fetchAll();
    $ocq=db()->prepare("SELECT oc.*,c.name category_name FROM offer_components oc JOIN categories c ON c.id=oc.category_id WHERE oc.offer_id=? ORDER BY oc.sort_order,oc.id");$ocq->execute([$o['id']]);$offerComponents=$ocq->fetchAll();
    $ss=db()->prepare("SELECT * FROM offer_shipping_steps WHERE offer_id=? ORDER BY sort_order,id");$ss->execute([$o['id']]);$shippingSteps=$ss->fetchAll();
    $tp=db()->prepare("SELECT * FROM offer_task_plans WHERE offer_id=? ORDER BY sort_order,id");$tp->execute([$o['id']]);$taskPlans=$tp->fetchAll();
    $taskTemplates=db()->query("SELECT * FROM task_library WHERE active=1 ORDER BY title")->fetchAll();
    $shippingAddresses=db()->query("SELECT * FROM shipping_addresses WHERE active=1 OR id=".(int)($o['shipping_address_id']??0)." ORDER BY active DESC,label")->fetchAll();
    $shippingRules=json_decode($o['shipping_rules_json']?:'{}',true)?:[];
    $vers=db()->prepare("SELECT version_no,created_at FROM offer_versions WHERE offer_id=? ORDER BY version_no DESC");$vers->execute([$o['id']]);$versions=$vers->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Angebot #<?=e($o['id'])?></div><h1><?=e($o['title'])?></h1><p class="meta">Aktuelle Version: V<?=e($o['current_version'])?></p></div><a class="btn secondary" href="<?=e(url('/admin/angebote'))?>">Zurück</a></div>
    <div class="grid two"><form class="panel" method="post"><?=csrf_field()?><h2>Angebot bearbeiten</h2><label>Titel<input name="title" value="<?=e($o['title'])?>" required></label><label>Kategorie<select name="category_id"><?php foreach($cats as $cat):?><option value="<?=$cat['id']?>" <?=$cat['id']==$o['category_id']?'selected':''?>><?=e($cat['name'])?></option><?php endforeach;?></select></label><div class="form-grid"><label>Vergütung (€)<input type="number" step=".01" min="0" name="compensation" value="<?=e($o['compensation'])?>" required></label><label>Dauer Tage<input type="number" min="1" name="duration_days" value="<?=e($o['duration_days']??'')?>"></label><label>Erfüllung<select name="fulfillment_type"><?php foreach(['days'=>'Tage','units'=>'Einheiten','one_time'=>'Einmalig','digital'=>'Digital','mixed'=>'Kombiniert'] as $k=>$v):?><option value="<?=$k?>" <?=$o['fulfillment_type']===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label><label>Status<select name="status"><?php foreach(['draft'=>'Entwurf','active'=>'Aktiv','inactive'=>'Deaktiviert'] as $k=>$v):?><option value="<?=$k?>" <?=$o['status']===$k?'selected':''?>><?=$v?></option><?php endforeach;?></select></label></div><label>Beschreibung<textarea name="description" required><?=e($o['description'])?></textarea></label><h3>Nachweisplan</h3><div class="form-grid"><label>Vorab-Pflichtfotos<input type="number" min="1" max="50" name="precheck_required_count" value="<?=e($rules['precheck_required_count']??1)?>" required></label><label>Morgen<input type="number" min="0" max="20" name="morning_count" value="<?=e($rules['daily']['morning']??1)?>" required></label><label>Mittag<input type="number" min="0" max="20" name="midday_count" value="<?=e($rules['daily']['midday']??1)?>" required></label><label>Abend<input type="number" min="0" max="20" name="evening_count" value="<?=e($rules['daily']['evening']??1)?>" required></label></div>
<h3>Digitale Abgaberegeln</h3>
<p class="meta">Relevant für digitale und kombinierte Angebote. Erlaubte Formate können frei kombiniert und einzeln als Pflicht markiert werden.</p>
<div class="form-grid">
<label><input type="checkbox" style="width:auto" name="digital_allow_text" value="1" <?=$digitalRules['allowed']['text']?'checked':''?>> Text erlaubt</label>
<label><input type="checkbox" style="width:auto" name="digital_require_text" value="1" <?=$digitalRules['required']['text']?'checked':''?>> Text verpflichtend</label>
<label><input type="checkbox" style="width:auto" name="digital_allow_audio" value="1" <?=$digitalRules['allowed']['audio']?'checked':''?>> Audio erlaubt</label>
<label><input type="checkbox" style="width:auto" name="digital_require_audio" value="1" <?=$digitalRules['required']['audio']?'checked':''?>> Audio verpflichtend</label>
<label><input type="checkbox" style="width:auto" name="digital_allow_video" value="1" <?=$digitalRules['allowed']['video']?'checked':''?>> Video erlaubt</label>
<label><input type="checkbox" style="width:auto" name="digital_require_video" value="1" <?=$digitalRules['required']['video']?'checked':''?>> Video verpflichtend</label>
<label>Text Mindestzeichen<input type="number" min="0" name="digital_text_min_chars" value="<?=e($digitalRules['text']['min_chars'])?>"></label>
<label>Text Maximalzeichen (0 = unbegrenzt)<input type="number" min="0" name="digital_text_max_chars" value="<?=e($digitalRules['text']['max_chars'])?>"></label>
<label>Max. Größe je Audio/Video (MB)<input type="number" min="1" max="500" name="digital_max_file_mb" value="<?=e($digitalRules['media']['max_file_mb'])?>"></label>
<label>Erstabgabe innerhalb (Stunden)<input type="number" min="1" name="digital_deadline_hours" value="<?=e($digitalRules['deadline']['hours_after_acceptance'])?>"></label>
<label>Nachfrist Erstabgabe (Minuten)<input type="number" min="0" name="digital_grace_minutes" value="<?=e($digitalRules['deadline']['grace_minutes'])?>"></label>
<label>Fristverstoß Erstabgabe<select name="digital_violation_effect"><option value="log_only" <?=$digitalRules['deadline']['violation_effect']==='log_only'?'selected':''?>>Nur dokumentieren</option><option value="extension_day" <?=$digitalRules['deadline']['violation_effect']==='extension_day'?'selected':''?>>Bestätigter Verstoß +1 Durchführungstag</option></select></label>
<label>Revisionsfrist (Stunden)<input type="number" min="1" name="digital_revision_deadline_hours" value="<?=e($digitalRules['revision']['deadline_hours'])?>"></label>
<label>Nachfrist Revision (Minuten)<input type="number" min="0" name="digital_revision_grace_minutes" value="<?=e($digitalRules['revision']['grace_minutes'])?>"></label>
<label>Fristverstoß Revision<select name="digital_revision_violation_effect"><option value="log_only" <?=$digitalRules['revision']['violation_effect']==='log_only'?'selected':''?>>Nur dokumentieren</option><option value="extension_day" <?=$digitalRules['revision']['violation_effect']==='extension_day'?'selected':''?>>Bestätigter Verstoß +1 Durchführungstag</option></select></label>
</div>
<h3>Versandbedingungen</h3><div class="form-grid">
<label>Empfängeradresse<select name="shipping_address_id"><option value="">Keine feste Adresse</option><?php foreach($shippingAddresses as $addr):?><option value="<?=$addr['id']?>" <?=((int)($o['shipping_address_id']??0)===(int)$addr['id'])?'selected':''?>><?=e($addr['label'].' · '.$addr['recipient_name'].' · '.$addr['postal_code'].' '.$addr['city'])?></option><?php endforeach;?></select></label>
<label>Kostenmodell<select name="shipping_cost_mode"><option value="seller" <?=$o['shipping_cost_mode']==='seller'?'selected':''?>>Verkäuferin trägt Versand</option><option value="fixed" <?=$o['shipping_cost_mode']==='fixed'?'selected':''?>>Fester Versandzuschuss</option><option value="reimburse" <?=$o['shipping_cost_mode']==='reimburse'?'selected':''?>>Volle Erstattung gegen Nachweis</option></select></label>
<label>Fester Versandzuschuss (€)<input type="number" step=".01" min="0" name="shipping_allowance" value="<?=e($o['shipping_allowance']??'0')?>"></label>
<label>Bevorzugter Versanddienstleister<input name="preferred_carrier" value="<?=e($o['preferred_carrier']??'')?>"></label>
</div><label>Allgemeine Verpackungs-/Versandhinweise<textarea name="shipping_instructions"><?=e($shippingRules['instructions']??'')?></textarea></label>
<p class="meta"><a href="<?=e(url('/admin/versandadressen'))?>">Empfängeradressen verwalten</a>. Die konkrete Adresse wird Verkäuferinnen erst in der Versandphase angezeigt.</p>
<button class="btn">Als neue Version speichern</button></form>
    <section class="panel"><h2>Zusatzoptionen</h2><form method="post" action="<?=e(url('/admin/angebot/'.$o['id'].'/option'))?>"><?=csrf_field()?><label>Bezeichnung<input name="label" required></label><label>Aufpreis (€)<input type="number" step=".01" min="0" name="price" value="0" required></label><button class="btn">Option hinzufügen</button></form><div class="timeline" style="margin-top:18px"><?php foreach($options as $x):?><div><strong><?=e($x['label'])?></strong> · <?=money($x['price'])?> · <?=$x['active']?'aktiv':'inaktiv'?></div><?php endforeach;?></div>
    <hr><h3>Kombi-Bestandteile</h3>
    <p class="meta">Die Hauptkategorie ist der primäre Bestandteil. Zusätzliche Bestandteile blockieren ihre Kategorien ebenfalls und erhöhen den Auftragswert um ihre jeweilige Vergütung.</p>
    <form method="post" action="<?=e(url('/admin/angebot/'.$o['id'].'/bestandteil'))?>"><?=csrf_field()?>
      <div class="form-grid">
        <label>Kategorie<select name="category_id"><?php foreach($cats as $cat):?><option value="<?=$cat['id']?>"><?=e($cat['name'])?></option><?php endforeach;?></select></label>
        <label>Titel<input name="title" required placeholder="z. B. zusätzliches Paar Schuhe"></label>
        <label>Art<select name="component_type"><option value="physical">Physisch</option><option value="digital">Digital</option></select></label>
        <label>Zusätzliche Vergütung (€)<input type="number" step=".01" min="0" name="compensation" value="0"></label>
        <label>Dauer Tage<input type="number" min="1" name="duration_days"></label>
        <label>Sortierung<input type="number" name="sort_order" value="<?=e((string)((count($offerComponents)+1)*10))?>"></label>
      </div>
      <label><input type="checkbox" style="width:auto" name="required" value="1" checked> Pflichtbestandteil</label>
      <button class="btn secondary">Bestandteil hinzufügen</button>
    </form>
    <div class="timeline" style="margin-top:14px"><?php foreach($offerComponents as $component):?><div><strong><?=e($component['title'])?></strong> · <?=e($component['category_name'])?> · <?=e($component['component_type'])?> · <?=money($component['compensation'])?> · <?=$component['required']?'Pflicht':'optional'?> · <?=$component['active']?'aktiv':'inaktiv'?><form method="post" action="<?=e(url('/admin/angebotsbestandteil/'.$component['id'].'/umschalten'))?>" style="margin-top:6px"><?=csrf_field()?><button class="btn secondary"><?=$component['active']?'Deaktivieren':'Aktivieren'?></button></form></div><?php endforeach;?><?php if(!$offerComponents):?><div class="meta">Keine zusätzlichen Kombi-Bestandteile.</div><?php endif;?></div>
    <hr><h3>Vorgeplante Zusatzaufgaben</h3>
    <form method="post" action="<?=e(url('/admin/angebot/'.$o['id'].'/aufgabenplan'))?>"><?=csrf_field()?>
      <label>Aus Bibliothek (optional)<select name="template_id"><option value="">Eigene Aufgabe</option><?php foreach($taskTemplates as $t):?><option value="<?=$t['id']?>"><?=e($t['title'])?> · <?=e($t['default_required_photos']??0)?> Foto(s) · <?=money($t['default_compensation'])?></option><?php endforeach;?></select></label>
      <div class="form-grid">
        <label>Titel / Überschreibung<input name="title" placeholder="bei Vorlage optional"></label>
        <label>Antworttyp<select name="response_type"><option value="text">Freitext</option><option value="number">Zahl</option><option value="scale10">Skala 1–10</option><option value="boolean">Ja/Nein</option></select></label>
        <label>Pflichtfotos<input type="number" min="0" max="20" name="required_photos" placeholder="Vorlagenwert übernehmen"></label>
        <label>Vergütung je Ausführung (€)<input type="number" step=".01" min="0" name="compensation" placeholder="Vorlagenwert übernehmen"></label>
        <label>Planung<select name="schedule_type"><option value="day">Bestimmter Tag</option><option value="interval">Intervall</option></select></label>
        <label>Tag (bei bestimmtem Tag)<input type="number" min="1" name="day_no" value="1"></label>
        <label>Starttag (Intervall)<input type="number" min="1" name="start_day" value="1"></label>
        <label>Alle X Tage<input type="number" min="1" name="interval_days" value="1"></label>
        <label>Fällig um<input type="time" name="due_time" value="20:00"></label>
        <label>Sortierung<input type="number" name="sort_order" value="<?=e((string)((count($taskPlans)+1)*10))?>"></label>
      </div>
      <label>Beschreibung / Überschreibung<textarea name="description"></textarea></label>
      <label><input type="checkbox" style="width:auto" name="violation_enabled" value="1" checked> Nichterfüllung kann als ein Verstoß gewertet werden</label>
      <button class="btn secondary">Aufgabe vorplanen</button>
    </form>
    <div class="timeline" style="margin-top:14px"><?php foreach($taskPlans as $tp):?><div><strong><?=e($tp['sort_order'].' · '.$tp['title'])?></strong><br><span class="meta"><?php if($tp['schedule_type']==='interval'):?>ab Tag <?=e($tp['start_day'])?> alle <?=e($tp['interval_days'])?> Tage<?php else:?>Tag <?=e($tp['day_no'])?><?php endif;?> · fällig <?=e($tp['due_time']?substr($tp['due_time'],0,5):'23:59')?> · <?=e($tp['required_photos'])?> Foto(s) · <?=money($tp['compensation'])?> je Ausführung · <?=$tp['active']?'aktiv':'inaktiv'?></span><form method="post" action="<?=e(url('/admin/aufgabenplan/'.$tp['id'].'/loeschen'))?>" style="margin-top:6px"><?=csrf_field()?><button class="btn secondary">Entfernen</button></form></div><?php endforeach;?><?php if(!$taskPlans):?><div class="meta">Noch keine Aufgabe fest eingeplant.</div><?php endif;?></div>
    <h3>Versand-/Endworkflow</h3>
    <form method="post" action="<?=e(url('/admin/angebot/'.$o['id'].'/versandschritt'))?>">
      <?=csrf_field()?>
      <div class="form-grid"><label>Reihenfolge<input type="number" name="sort_order" value="<?=e((string)((count($shippingSteps)+1)*10))?>"></label><label>Titel<input name="title" required></label><label>Pflichtfotos<input type="number" name="required_photos" min="0" max="20" value="0"></label><label>Frist ab Freischaltung (Stunden)<input type="number" name="deadline_hours" min="1"></label></div>
      <label>Anweisung<textarea name="instructions"></textarea></label>
      <label><input type="checkbox" style="width:auto" name="requires_text" value="1"> Textangabe erforderlich</label>
      <label><input type="checkbox" style="width:auto" name="requires_checkbox" value="1"> Bestätigung erforderlich</label>
      <label><input type="checkbox" style="width:auto" name="is_dispatch_step" value="1"> Finaler Versand-/Einlieferungsschritt (Tracking oder Einlieferungsnachweis erforderlich)</label>
      <button class="btn secondary">Versandschritt hinzufügen</button>
    </form>
    <div class="timeline" style="margin-top:14px"><?php foreach($shippingSteps as $s):?><div><strong><?=e($s['sort_order'].' · '.$s['title'])?></strong> · <?=e($s['required_photos'])?> Foto(s)<?=$s['is_dispatch_step']?' · Versandnachweis':''?><form method="post" action="<?=e(url('/admin/versandschritt/'.$s['id'].'/loeschen'))?>" style="margin-top:6px"><?=csrf_field()?><button class="btn secondary">Entfernen</button></form></div><?php endforeach;?><?php if(!$shippingSteps):?><div class="meta">Keine eigene Vorlage hinterlegt – es wird beim Auftrag der Standardworkflow verwendet.</div><?php endif;?></div>
    <hr><h3>Als Angebotsvorlage speichern</h3><form method="post" action="<?=e(url('/admin/angebot/'.$o['id'].'/als-vorlage'))?>"><?=csrf_field()?><label>Vorlagenname<input name="template_name" value="<?=e($o['title'])?>" required></label><button class="btn secondary">Vorlage speichern</button></form>
    <p class="meta"><a href="<?=e(url('/admin/angebotsvorlagen'))?>">Gespeicherte Angebotsvorlagen verwalten</a></p>
    <h3>Versionshistorie</h3><?php foreach($versions as $v):?><div class="meta">V<?=e($v['version_no'])?> · <?=e(date('d.m.Y H:i',strtotime($v['created_at'])))?></div><?php endforeach;?></section></div>
    <?php render('Angebot bearbeiten',ob_get_clean());exit;
}
if (preg_match('#^/admin/angebot/(\\d+)$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT * FROM offers WHERE id=?");$st->execute([(int)$m[1]]);$o=$st->fetch();if(!$o)not_found();
    $newVersion=(int)$o['current_version']+1;$days=post('duration_days')!==''?(int)post('duration_days'):null;$comp=max(0,(float)post('compensation'));
    $rules=['precheck_required_count'=>max(1,(int)post('precheck_required_count','1')),'daily'=>['morning'=>max(0,(int)post('morning_count','1')),'midday'=>max(0,(int)post('midday_count','1')),'evening'=>max(0,(int)post('evening_count','1'))]];
    $rulesJson=json_encode($rules,JSON_UNESCAPED_UNICODE);
    $digitalRules=normalize_digital_rules([
      'allowed'=>[
        'text'=>isset($_POST['digital_allow_text']),
        'audio'=>isset($_POST['digital_allow_audio']),
        'video'=>isset($_POST['digital_allow_video']),
      ],
      'required'=>[
        'text'=>isset($_POST['digital_require_text']),
        'audio'=>isset($_POST['digital_require_audio']),
        'video'=>isset($_POST['digital_require_video']),
      ],
      'text'=>[
        'min_chars'=>max(0,(int)post('digital_text_min_chars','0')),
        'max_chars'=>max(0,(int)post('digital_text_max_chars','0')),
      ],
      'media'=>['max_file_mb'=>max(1,(int)post('digital_max_file_mb','50'))],
      'deadline'=>[
        'hours_after_acceptance'=>max(1,(int)post('digital_deadline_hours','72')),
        'grace_minutes'=>max(0,(int)post('digital_grace_minutes','60')),
        'violation_effect'=>post('digital_violation_effect','log_only'),
      ],
      'revision'=>[
        'deadline_hours'=>max(1,(int)post('digital_revision_deadline_hours','48')),
        'grace_minutes'=>max(0,(int)post('digital_revision_grace_minutes','60')),
        'violation_effect'=>post('digital_revision_violation_effect','log_only'),
      ],
    ]);
    foreach(['text','audio','video'] as $format){
      if($digitalRules['required'][$format] && !$digitalRules['allowed'][$format]){
        flash('error','Ein verpflichtendes Digitalformat muss zugleich erlaubt sein.');
        redirect('/admin/angebot/'.$o['id']);
      }
    }
    if($digitalRules['text']['max_chars']>0 && $digitalRules['text']['max_chars']<$digitalRules['text']['min_chars']){
      flash('error','Die maximale Textlänge darf nicht unter der Mindestlänge liegen.');
      redirect('/admin/angebot/'.$o['id']);
    }
    $digitalRulesJson=json_encode($digitalRules,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $shippingAddressId=post('shipping_address_id')!==''?(int)post('shipping_address_id'):null;
    $shippingCostMode=in_array(post('shipping_cost_mode'),['seller','fixed','reimburse'],true)?post('shipping_cost_mode'):'seller';
    $shippingAllowance=$shippingCostMode==='fixed'?max(0,(float)post('shipping_allowance')):0.0;
    $preferredCarrier=post('preferred_carrier')?:null;
    $shippingRules=['instructions'=>post('shipping_instructions')?:null];
    $shippingRulesJson=json_encode($shippingRules,JSON_UNESCAPED_UNICODE);
    $snap=['title'=>post('title'),'category_id'=>(int)post('category_id'),'description'=>post('description'),'compensation'=>$comp,'duration_days'=>$days,'fulfillment_type'=>post('fulfillment_type'),'status'=>post('status'),'evidence_rules'=>$rules,'digital_rules'=>$digitalRules,'shipping'=>['address_id'=>$shippingAddressId,'cost_mode'=>$shippingCostMode,'allowance'=>$shippingAllowance,'preferred_carrier'=>$preferredCarrier,'instructions'=>$shippingRules['instructions']],'components'=>offer_component_definitions($o)];
    db()->beginTransaction();try{
      db()->prepare("UPDATE offers SET category_id=?,title=?,description=?,compensation=?,duration_days=?,fulfillment_type=?,evidence_rules_json=?,digital_rules_json=?,shipping_rules_json=?,shipping_address_id=?,shipping_cost_mode=?,shipping_allowance=?,preferred_carrier=?,status=?,current_version=?,updated_at=NOW() WHERE id=?")->execute([$snap['category_id'],$snap['title'],$snap['description'],$comp,$days,$snap['fulfillment_type'],$rulesJson,$digitalRulesJson,$shippingRulesJson,$shippingAddressId,$shippingCostMode,$shippingAllowance,$preferredCarrier,$snap['status'],$newVersion,$o['id']]);
      db()->prepare("INSERT INTO offer_versions(offer_id,version_no,snapshot_json) VALUES(?,?,?)")->execute([$o['id'],$newVersion,json_encode($snap,JSON_UNESCAPED_UNICODE)]);
      db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    flash('success','Angebot als Version V'.$newVersion.' gespeichert. Bestehende Aufträge bleiben auf ihrer ursprünglichen Version.');redirect('/admin/angebot/'.$o['id']);
}
if (preg_match('#^/admin/angebot/(\\d+)/option$#',$path,$m)&&$method==='POST') {
    require_admin();$st=db()->prepare("SELECT id FROM offers WHERE id=?");$st->execute([(int)$m[1]]);if(!$st->fetchColumn())not_found();
    db()->prepare("INSERT INTO offer_options(offer_id,label,price,active) VALUES(?,?,?,1)")->execute([(int)$m[1],post('label'),max(0,(float)post('price'))]);$version=bump_offer_version((int)$m[1],'option_added');flash('success','Zusatzoption hinzugefügt. Angebot ist jetzt Version V'.$version.'.');redirect('/admin/angebot/'.$m[1]);
}



if (preg_match('#^/admin/angebot/(\d+)/aufgabenplan$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM offers WHERE id=?");$q->execute([(int)$m[1]]);$offer=$q->fetch();if(!$offer)not_found();

    $template=null;$templateId=post('template_id')!==''?(int)post('template_id'):null;
    if($templateId){
        $q=db()->prepare("SELECT * FROM task_library WHERE id=? AND active=1");$q->execute([$templateId]);$template=$q->fetch();
        if(!$template){flash('error','Aufgabenvorlage wurde nicht gefunden.');redirect('/admin/angebot/'.$offer['id']);}
    }

    $title=post('title')!==''?post('title'):($template['title']??'');
    $description=post('description')!==''?post('description'):($template['description']??null);
    if($title===''){flash('error','Bitte einen Titel oder eine Aufgabenvorlage auswählen.');redirect('/admin/angebot/'.$offer['id']);}

    $fields=$template['fields_json']??json_encode(['response_type'=>post('response_type','text')],JSON_UNESCAPED_UNICODE);
    $required=post('required_photos')!==''?max(0,(int)post('required_photos')):max(0,(int)($template['default_required_photos']??0));
    $comp=post('compensation')!==''?max(0,(float)post('compensation')):max(0,(float)($template['default_compensation']??0));
    $violation=isset($_POST['violation_enabled'])?1:(int)($template['violation_enabled']??0);
    $schedule=in_array(post('schedule_type'),['day','interval'],true)?post('schedule_type'):'day';
    $duration=max(1,(int)($offer['duration_days']?:1));
    $dayNo=$schedule==='day'?max(1,(int)post('day_no','1')):null;
    $startDay=$schedule==='interval'?max(1,(int)post('start_day','1')):1;
    $interval=$schedule==='interval'?max(1,(int)post('interval_days','1')):null;
    if($dayNo!==null && $dayNo>$duration){flash('error','Der geplante Aufgabentag liegt außerhalb der Angebotsdauer.');redirect('/admin/angebot/'.$offer['id']);}
    if($schedule==='interval' && $startDay>$duration){flash('error','Der Starttag des Intervalls liegt außerhalb der Angebotsdauer.');redirect('/admin/angebot/'.$offer['id']);}
    $due=post('due_time')!==''?post('due_time').':00':null;

    db()->prepare("INSERT INTO offer_task_plans(offer_id,task_library_id,sort_order,title,description,fields_json,required_photos,compensation,violation_enabled,schedule_type,day_no,start_day,interval_days,due_time,active) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
      ->execute([$offer['id'],$templateId,(int)post('sort_order','0'),$title,$description,$fields,$required,$comp,$violation,$schedule,$dayNo,$startDay,$interval,$due]);
    $version=bump_offer_version((int)$offer['id'],'task_plan_added');
    flash('success','Aufgabe wurde für zukünftige Annahmen vorgeplant. Angebot ist jetzt Version V'.$version.'.');redirect('/admin/angebot/'.$offer['id']);
}

if (preg_match('#^/admin/aufgabenplan/(\d+)/loeschen$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM offer_task_plans WHERE id=?");$q->execute([(int)$m[1]]);$plan=$q->fetch();if(!$plan)not_found();
    db()->prepare("DELETE FROM offer_task_plans WHERE id=?")->execute([$plan['id']]);
    $version=bump_offer_version((int)$plan['offer_id'],'task_plan_removed');
    flash('success','Vorgeplante Aufgabe entfernt. Bereits angenommene Aufträge behalten ihren Snapshot. Angebot ist jetzt Version V'.$version.'.');redirect('/admin/angebot/'.$plan['offer_id']);
}

/* ---------- V1 completion: precheck, evidence review, offer versioning, notifications ---------- */

if (preg_match('#^/admin/nachweis/(\\d+)/ablehnen$#',$path,$m) && $method==='POST') {
    require_admin();
    $reason=post('reason');
    if($reason===''){flash('error','Bitte einen Beanstandungsgrund angeben.');redirect('/admin/auftraege');}
    $q=db()->prepare("SELECT e.*,o.order_no,o.seller_id FROM evidences e JOIN orders o ON o.id=e.order_id WHERE e.id=?");
    $q->execute([(int)$m[1]]);$ev=$q->fetch();if(!$ev)not_found();
    db()->prepare("UPDATE evidences SET status='rejected',rejection_reason=?,reviewed_at=NOW() WHERE id=?")->execute([$reason,$ev['id']]);
    notify_seller((int)$ev['seller_id'],'evidence.rejected','Nachweis beanstandet','Ein Nachweis im Auftrag '.$ev['order_no'].' wurde beanstandet: '.$reason,'/auftrag/'.$ev['order_no'],null,true);
    log_event('evidence.rejected',(int)$ev['seller_id'],(int)$ev['order_id'],['evidence_id'=>(int)$ev['id'],'reason'=>$reason]);
    flash('success','Nachweis wurde beanstandet.');redirect('/admin/auftrag/'.$ev['order_no']);
}

if (preg_match('#^/admin/auftrag/(\d{8})/vorabkontrolle-freigeben$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=? AND status='precheck'");
    $q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();

    if(empty($o['planned_start_date'])){
        flash('error','Die Verkäuferin muss zuerst ein Startdatum festlegen.');
        redirect('/admin/auftrag/'.$o['order_no']);
    }

    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    $today=new DateTimeImmutable('today',$tz);
    $planned=new DateTimeImmutable($o['planned_start_date'].' 00:00:00',$tz);
    if($planned <= $today){
        flash('error','Das geplante Startdatum ist bereits erreicht oder vergangen. Vor der Gesamtfreigabe muss ein neues zukünftiges Startdatum genehmigt werden.');
        redirect('/admin/auftrag/'.$o['order_no']);
    }

    $runId=current_run_id((int)$o['id']);
    $rules=offer_evidence_rules((int)$o['id']);
    $required=max(1,(int)$rules['precheck_required_count']);
    $q=db()->prepare("SELECT COUNT(*) total,SUM(status='accepted') accepted_count,SUM(status<>'accepted') open_count FROM evidences WHERE order_id=? AND order_run_id<=>? AND evidence_type='precheck'");
    $q->execute([$o['id'],$runId]);$stats=$q->fetch();
    $accepted=(int)($stats['accepted_count']??0);$open=(int)($stats['open_count']??0);
    if($accepted<$required || $open>0){
        flash('error','Die Vorabkontrolle kann erst freigegeben werden, wenn alle '.$required.' Pflichtnachweise des aktuellen Durchlaufs vorhanden und akzeptiert sind.');
        redirect('/admin/auftrag/'.$o['order_no']);
    }

    db()->beginTransaction();
    try{
        db()->prepare("UPDATE orders SET precheck_approved_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$o['id']]);
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
            ->execute([$o['id'],'Vorabkontrolle vollständig freigegeben. Geplanter Start: '.date('d.m.Y',strtotime($o['planned_start_date'])).'.']);
        log_event('precheck.approved',(int)$o['seller_id'],(int)$o['id'],['planned_start_date'=>$o['planned_start_date']]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}

    notify_seller(
        (int)$o['seller_id'],
        'precheck.approved',
        'Vorabkontrolle freigegeben',
        'Die Vorabkontrolle für Auftrag '.$o['order_no'].' ist vollständig freigegeben. Der Auftrag startet automatisch am '.date('d.m.Y',strtotime($o['planned_start_date'])).'.',
        '/auftrag/'.$o['order_no'],
        'precheck-approved-'.$o['id'],
        true
    );
    flash('success','Vorabkontrolle vollständig freigegeben. Automatischer Start am '.date('d.m.Y',strtotime($o['planned_start_date'])).'.');
    redirect('/admin/auftrag/'.$o['order_no']);
}

if ($path==='/benachrichtigungen' && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT * FROM notifications WHERE seller_id=? ORDER BY created_at DESC LIMIT 100");$q->execute([$s['id']]);$rows=$q->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Konto</div><h1>Benachrichtigungen</h1></div><form method="post" action="<?=e(url('/benachrichtigungen/alle-gelesen'))?>"><?=csrf_field()?><button class="btn secondary">Alle als gelesen markieren</button></form></div>
    <div class="timeline"><?php foreach($rows as $n):?><div class="<?=$n['read_at']?'':'panel'?>"><strong><?=e($n['title'])?></strong><p><?=e($n['body']??'')?></p><small class="meta"><?=e(date('d.m.Y H:i',strtotime($n['created_at'])))?></small><?php if($n['link']):?> · <a href="<?=e(url($n['link']))?>">Öffnen</a><?php endif;?></div><?php endforeach;?><?php if(!$rows):?><div class="empty">Keine Benachrichtigungen vorhanden.</div><?php endif;?></div>
    <?php render('Benachrichtigungen',ob_get_clean());exit;
}
if ($path==='/benachrichtigungen/alle-gelesen' && $method==='POST') {
    $s=require_seller();db()->prepare("UPDATE notifications SET read_at=NOW() WHERE seller_id=? AND read_at IS NULL")->execute([$s['id']]);redirect('/benachrichtigungen');
}
if ($path==='/email-bestaetigung-neu' && $method==='POST') {
    $s=require_seller();
    if($s['email_verified_at']){flash('success','Die E-Mail-Adresse ist bereits bestätigt.');redirect('/dashboard');}
    if(!rate_limit_consume('email-verification-resend',$s['email'],5,3600,3600)){flash('error','Zu viele Bestätigungs-E-Mails angefordert. Bitte später erneut versuchen.');redirect('/dashboard');}
    db()->prepare("DELETE FROM email_verifications WHERE seller_id=?")->execute([$s['id']]);
    [$raw,$hash]=make_token();
    db()->prepare("INSERT INTO email_verifications(seller_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))")->execute([$s['id'],$hash]);
    send_app_mail($s['email'],'E-Mail bestätigen','<p>Bitte bestätige deine E-Mail:</p><p><a href="'.e(url('/email-bestaetigen?token='.$raw)).'">E-Mail bestätigen</a></p>');
    flash('success','Bestätigungs-E-Mail wurde erneut versendet.');redirect('/dashboard');
}

if (preg_match('#^/admin/angebot/(\\d+)/duplizieren$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM offers WHERE id=?");$q->execute([(int)$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $title=$o['title'].' – Kopie';$slug=preg_replace('/[^a-z0-9-]/','',strtolower(str_replace(' ','-',$title))).'-'.substr(bin2hex(random_bytes(3)),0,6);
    db()->beginTransaction();try{
        db()->prepare("INSERT INTO offers(category_id,title,slug,description,compensation,duration_days,fulfillment_type,evidence_rules_json,shipping_rules_json,shipping_address_id,shipping_cost_mode,shipping_allowance,preferred_carrier,status,current_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',1)")
            ->execute([$o['category_id'],$title,$slug,$o['description'],$o['compensation'],$o['duration_days'],$o['fulfillment_type'],$o['evidence_rules_json'],$o['shipping_rules_json'],$o['shipping_address_id'],$o['shipping_cost_mode'],$o['shipping_allowance'],$o['preferred_carrier']]);
        $id=(int)db()->lastInsertId();
        $snap=json_encode(['category_id'=>(int)$o['category_id'],'title'=>$title,'description'=>$o['description'],'compensation'=>(float)$o['compensation'],'duration_days'=>$o['duration_days'],'fulfillment_type'=>$o['fulfillment_type'],'status'=>'draft','shipping'=>build_shipping_snapshot($o)],JSON_UNESCAPED_UNICODE);
        db()->prepare("INSERT INTO offer_versions(offer_id,version_no,snapshot_json) VALUES(?,1,?)")->execute([$id,$snap]);
        $opt=db()->prepare("INSERT INTO offer_options(offer_id,label,price,requirements_json,active) SELECT ?,label,price,requirements_json,active FROM offer_options WHERE offer_id=?");$opt->execute([$id,$o['id']]);
        $shipSteps=db()->prepare("INSERT INTO offer_shipping_steps(offer_id,sort_order,title,instructions,required_photos,requires_text,requires_checkbox,is_dispatch_step,deadline_hours,active) SELECT ?,sort_order,title,instructions,required_photos,requires_text,requires_checkbox,is_dispatch_step,deadline_hours,active FROM offer_shipping_steps WHERE offer_id=?");$shipSteps->execute([$id,$o['id']]);
        $taskPlans=db()->prepare("INSERT INTO offer_task_plans(offer_id,task_library_id,sort_order,title,description,fields_json,required_photos,compensation,violation_enabled,schedule_type,day_no,start_day,interval_days,due_time,active) SELECT ?,task_library_id,sort_order,title,description,fields_json,required_photos,compensation,violation_enabled,schedule_type,day_no,start_day,interval_days,due_time,active FROM offer_task_plans WHERE offer_id=?");$taskPlans->execute([$id,$o['id']]);
        db()->commit();
        bump_offer_version($id,'duplicated_configuration');
    }catch(Throwable $e){db()->rollBack();throw $e;}
    flash('success','Angebot wurde als Entwurf dupliziert.');redirect('/admin/angebot/'.$id);
}


/* ---------- V1 decisions and spontaneous evidence ---------- */

if ($path==='/admin/entscheidungen' && $method==='GET') {
    require_admin();
    $pre=db()->query("SELECT e.id,e.order_id,e.created_at,o.order_no,CONCAT(s.first_name,' ',s.last_name) seller_name
      FROM evidences e JOIN orders o ON o.id=e.order_id JOIN sellers s ON s.id=o.seller_id
      WHERE e.evidence_type='precheck' AND e.status='submitted' ORDER BY e.created_at")->fetchAll();
    $viol=db()->query("SELECT v.*,o.order_no,CONCAT(s.first_name,' ',s.last_name) seller_name
      FROM violations v JOIN orders o ON o.id=v.order_id JOIN sellers s ON s.id=o.seller_id
      WHERE v.status IN('open','reviewed') ORDER BY v.created_at")->fetchAll();
    $damage=db()->query("SELECT d.*,o.order_no,CONCAT(s.first_name,' ',s.last_name) seller_name
      FROM damage_cases d JOIN orders o ON o.id=d.order_id JOIN sellers s ON s.id=o.seller_id
      WHERE d.status IN('reported','evidence_requested','review') ORDER BY d.created_at")->fetchAll();
    $revisions=db()->query("SELECT r.*,o.order_no,CONCAT(s.first_name,' ',s.last_name) seller_name
      FROM revision_rounds r JOIN orders o ON o.id=r.order_id JOIN sellers s ON s.id=o.seller_id
      WHERE r.status='submitted' ORDER BY r.created_at")->fetchAll();
    $payouts=db()->query("SELECT p.*,CONCAT(s.first_name,' ',s.last_name) seller_name
      FROM payout_requests p JOIN sellers s ON s.id=p.seller_id
      WHERE p.status IN('requested','review','released') ORDER BY p.created_at")->fetchAll();

    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Offene Entscheidungen</h1></div></div>
    <div class="grid">
      <div class="card"><div class="meta">Vorabnachweise</div><div class="stat"><?=count($pre)?></div></div>
      <div class="card"><div class="meta">Verstöße</div><div class="stat"><?=count($viol)?></div></div>
      <div class="card"><div class="meta">Beschädigungen</div><div class="stat"><?=count($damage)?></div></div>
    </div>
    <h2>Verstöße prüfen</h2>
    <div class="table-wrap"><table><thead><tr><th>Auftrag</th><th>Verkäuferin</th><th>Grund</th><th>Entscheidung</th></tr></thead><tbody>
    <?php foreach($viol as $v):?><tr><td><a href="<?=e(url('/admin/auftrag/'.$v['order_no']))?>"><?=e($v['order_no'])?></a></td><td><?=e($v['seller_name'])?></td><td><?=e($v['reason']??$v['violation_type'])?></td><td><div class="actions"><form method="post" action="<?=e(url('/admin/verstoss/'.$v['id'].'/bestaetigen'))?>"><?=csrf_field()?><button class="btn danger">Bestätigen (+1 Tag)</button></form><form method="post" action="<?=e(url('/admin/verstoss/'.$v['id'].'/verwerfen'))?>"><?=csrf_field()?><button class="btn secondary">Verwerfen</button></form></div></td></tr><?php endforeach;?>
    <?php if(!$viol):?><tr><td colspan="4">Keine offenen Verstöße.</td></tr><?php endif;?></tbody></table></div>
    <h2>Vorabkontrollen</h2><div class="table-wrap"><table><tbody><?php foreach($pre as $x):?><tr><td><?=e($x['order_no'])?></td><td><?=e($x['seller_name'])?></td><td><?=e(date('d.m.Y H:i',strtotime($x['created_at'])))?></td><td><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>">Prüfen</a></td></tr><?php endforeach;?></tbody></table></div>
    <h2>Beschädigungen</h2><div class="table-wrap"><table><tbody><?php foreach($damage as $d):?><tr><td><?=e($d['order_no'])?></td><td><?=e($d['seller_name'])?></td><td><?=e($d['reason'])?></td><td><a href="<?=e(url('/admin/auftrag/'.$d['order_no']))?>">Auftrag öffnen</a></td></tr><?php endforeach;?></tbody></table></div>
    <h2>Digitale Revisionen</h2><div class="table-wrap"><table><tbody><?php foreach($revisions as $r):?><tr><td><?=e($r['order_no'])?></td><td><?=e($r['seller_name'])?></td><td>Runde <?=e($r['round_no'])?></td><td><a href="<?=e(url('/admin/auftrag/'.$r['order_no']))?>">Prüfen</a></td></tr><?php endforeach;?></tbody></table></div>
    <h2>Auszahlungen</h2><div class="table-wrap"><table><tbody><?php foreach($payouts as $p):?><tr><td><?=e($p['seller_name'])?></td><td><?=money($p['amount'])?></td><td><?=e($p['status'])?></td><td><a href="<?=e(url('/admin/auszahlungen'))?>">Öffnen</a></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Offene Entscheidungen',ob_get_clean());exit;
}

if (preg_match('#^/admin/verstoss/(\d+)/(bestaetigen|verwerfen)$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT v.*,o.order_no,o.seller_id FROM violations v JOIN orders o ON o.id=v.order_id WHERE v.id=?");
    $q->execute([(int)$m[1]]);$v=$q->fetch();if(!$v)not_found();
    if($m[2]==='bestaetigen'){
        db()->beginTransaction();
        try{
            db()->prepare("UPDATE violations SET status='confirmed',reviewed_at=NOW() WHERE id=? AND status IN('open','reviewed')")->execute([$v['id']]);
            $q=db()->prepare("SELECT id FROM extra_days WHERE source_type='violation' AND source_id=? AND status='provisional' LIMIT 1");
            $q->execute([$v['id']]);$extraId=$q->fetchColumn();
            if($extraId){
                db()->prepare("UPDATE extra_days SET status='confirmed' WHERE id=?")->execute([$extraId]);
            }else{
                db()->prepare("INSERT INTO extra_days(order_id,source_type,source_id,status,paid,amount,reason) VALUES(?,'violation',?,'confirmed',0,0,?)")->execute([$v['order_id'],$v['id'],$v['reason']]);
                $extraId=(int)db()->lastInsertId();
            }
            db()->commit();
            schedule_extra_day((int)$extraId);
        }catch(Throwable $e){db()->rollBack();throw $e;}
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$v['order_id'],'Verstoß bestätigt: '.($v['reason']?:$v['violation_type']).' · +1 zusätzlicher Durchführungstag.']);
        notify_seller((int)$v['seller_id'],'violation.confirmed','Verstoß bestätigt','Im Auftrag '.$v['order_no'].' wurde ein Verstoß bestätigt. Ein zusätzlicher Durchführungstag wurde angehängt.','/auftrag/'.$v['order_no'],'violation-confirmed-'.$v['id'],true);
        flash('success','Verstoß bestätigt; der Zusatztag ist jetzt verbindlich terminiert.');
    }else{
        db()->prepare("UPDATE violations SET status='discarded',reviewed_at=NOW() WHERE id=? AND status IN('open','reviewed')")->execute([$v['id']]);
        db()->prepare("UPDATE extra_days SET status='cancelled' WHERE source_type='violation' AND source_id=? AND status='provisional'")->execute([$v['id']]);
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$v['order_id'],'Möglicher Verstoß wurde nach Prüfung verworfen.']);
        notify_seller((int)$v['seller_id'],'violation.discarded','Verstoß verworfen','Der mögliche Verstoß im Auftrag '.$v['order_no'].' wurde verworfen.','/auftrag/'.$v['order_no'],'violation-discarded-'.$v['id'],false);
        flash('success','Verstoß verworfen; der provisorische Zusatztag wurde storniert.');
    }
    redirect('/admin/entscheidungen');
}

if (preg_match('#^/admin/auftrag/(\d{8})/spontan$#',$path,$m) && $method==='POST') {
    require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=? AND status='running'");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $required=max(1,min(20,(int)post('required_count','1')));
    $raw=post('due_at');if($raw===''){flash('error','Bitte eine Frist angeben.');redirect('/admin/auftrag/'.$o['order_no']);}
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));$due=new DateTimeImmutable($raw,$tz);
    if($due<=new DateTimeImmutable('now',$tz)){flash('error','Die Frist muss in der Zukunft liegen.');redirect('/admin/auftrag/'.$o['order_no']);}
    $grace=$due->modify('+'.max(0,(int)setting_value('grace_minutes','60')).' minutes');
    db()->prepare("INSERT INTO spontaneous_requests(order_id,instructions,required_count,due_at,grace_ends_at,status) VALUES(?,?,?,?,?,'requested')")
      ->execute([$o['id'],post('instructions'),$required,$due->format('Y-m-d H:i:s'),$grace->format('Y-m-d H:i:s')]);
    $id=(int)db()->lastInsertId();
    notify_seller((int)$o['seller_id'],'spontaneous.request','Spontaner Fotowunsch','Für Auftrag '.$o['order_no'].' wurden '.$required.' zusätzliche Foto(s) angefordert. Frist: '.$due->format('d.m.Y H:i').' Uhr.','/auftrag/'.$o['order_no'].'/spontan/'.$id,'spontaneous-request-'.$id,true);
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$o['id'],'Spontane Fotoanforderung: '.$required.' Foto(s), Frist '.$due->format('d.m.Y H:i').' Uhr.']);
    flash('success','Spontane Fotoanforderung erstellt.');redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/auftrag/(\d{8})/spontan/(\d+)$#',$path,$m) && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT r.*,o.order_no,o.id order_id FROM spontaneous_requests r JOIN orders o ON o.id=r.order_id WHERE o.order_no=? AND r.id=? AND o.seller_id=?");
    $q->execute([$m[1],(int)$m[2],$s['id']]);$r=$q->fetch();if(!$r)not_found();
    if($r['status']==='requested')db()->prepare("UPDATE spontaneous_requests SET status='seen' WHERE id=?")->execute([$r['id']]);
    $cnt=db()->prepare("SELECT COUNT(*) FROM evidences WHERE order_id=? AND evidence_type='spontaneous' AND source_type='spontaneous' AND source_id=? AND status IN('submitted','accepted')");
    $cnt->execute([$r['order_id'],$r['id']]);$submitted=(int)$cnt->fetchColumn();
    $grace=new DateTimeImmutable($r['grace_ends_at'],new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));$expired=new DateTimeImmutable('now',$grace->getTimezone())>$grace;
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Spontaner Nachweis · <?=e($r['order_no'])?></div><h1>Zusätzliche Fotoanforderung</h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$r['order_no']))?>">Zum Auftrag</a></div>
    <div class="grid two"><section class="panel"><h2>Anforderung</h2><p><?=nl2br(e($r['instructions']))?></p><p><strong><?=e($submitted)?> / <?=e($r['required_count'])?></strong> Fotos eingereicht</p><p class="meta">Reguläre Frist: <?=e(date('d.m.Y H:i',strtotime($r['due_at'])))?><br>Nachfrist bis: <?=e(date('d.m.Y H:i',strtotime($r['grace_ends_at'])))?></p></section>
    <section class="panel"><h2>Live-Foto einreichen</h2><?php if(!$expired && $submitted<(int)$r['required_count']):?><form method="post" enctype="multipart/form-data"><?=csrf_field()?><label>Foto<input data-camera-input type="file" name="evidence" required></label><button class="btn">Foto einreichen</button></form><?php elseif($submitted>=(int)$r['required_count']):?><p class="badge ok">Anforderung vollständig eingereicht.</p><?php else:?><p class="badge bad">Nachfrist abgelaufen.</p><?php endif;?></section></div>
    <?php render('Spontaner Nachweis',ob_get_clean());exit;
}

if (preg_match('#^/auftrag/(\d{8})/spontan/(\d+)$#',$path,$m) && $method==='POST') {
    $s=require_seller();
    $q=db()->prepare("SELECT r.*,o.id order_id,o.order_no FROM spontaneous_requests r JOIN orders o ON o.id=r.order_id WHERE o.order_no=? AND r.id=? AND o.seller_id=? AND o.status='running'");
    $q->execute([$m[1],(int)$m[2],$s['id']]);$r=$q->fetch();if(!$r)not_found();
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    if(new DateTimeImmutable('now',$tz)>new DateTimeImmutable($r['grace_ends_at'],$tz)){flash('error','Die Nachfrist ist abgelaufen.');redirect('/auftrag/'.$r['order_no'].'/spontan/'.$r['id']);}
    try{
        $up=private_upload($_FILES['evidence']??[],'order-'.$r['order_id'].'/spontaneous');
        db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,source_type,source_id,file_path,mime_type,file_size,sha256,metadata_json,quality_flags_json) VALUES(?,?,?,'spontaneous','spontaneous',?,?,?,?,?,?,?)")
          ->execute([$r['order_id'],current_run_id((int)$r['order_id']),$s['id'],$r['id'],$up['path'],$up['mime'],$up['size'],$up['sha256'],upload_metadata_json($up),upload_quality_flags_json($up)]);
        $cnt=db()->prepare("SELECT COUNT(*) FROM evidences WHERE order_id=? AND evidence_type='spontaneous' AND source_type='spontaneous' AND source_id=? AND status IN('submitted','accepted')");
        $cnt->execute([$r['order_id'],$r['id']]);$submitted=(int)$cnt->fetchColumn();
        if($submitted >= (int)$r['required_count'])db()->prepare("UPDATE spontaneous_requests SET status='uploaded' WHERE id=?")->execute([$r['id']]);
        flash('success','Spontanes Foto wurde eingereicht.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('/auftrag/'.$r['order_no'].'/spontan/'.$r['id']);
}


/* ---------- V1 today search tasks and assignments ---------- */

if ($path==='/heute' && $method==='GET') {
    $s=require_seller();
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    $now=new DateTimeImmutable('now',$tz);$today=$now->format('Y-m-d');

    $q=db()->prepare("SELECT w.*,o.order_no,f.title,
      (SELECT COUNT(*) FROM evidences e WHERE e.order_id=w.order_id AND e.order_run_id<=>w.order_run_id AND e.evidence_type='daily' AND e.day_no=w.day_no AND e.window_key=w.window_key AND e.status IN('submitted','accepted')) submitted_count
      FROM evidence_windows w JOIN orders o ON o.id=w.order_id JOIN offers f ON f.id=o.offer_id
      WHERE o.seller_id=? AND o.status='running' AND DATE(w.starts_at)=? ORDER BY w.starts_at");
    $q->execute([$s['id'],$today]);$windows=$q->fetchAll();

    $q=db()->prepare("SELECT r.*,o.order_no FROM spontaneous_requests r JOIN orders o ON o.id=r.order_id
      WHERE o.seller_id=? AND o.status='running' AND r.status NOT IN('reviewed','missed') ORDER BY r.due_at");
    $q->execute([$s['id']]);$spontaneous=$q->fetchAll();

    $q=db()->prepare("SELECT r.*,o.order_no FROM evidence_retake_requests r JOIN orders o ON o.id=r.order_id
      WHERE r.seller_id=? AND o.status IN('precheck','running','review') AND r.status='requested' AND r.grace_ends_at>=NOW() ORDER BY r.due_at");
    $q->execute([$s['id']]);$retakes=$q->fetchAll();

    $q=db()->prepare("SELECT t.*,o.order_no FROM order_tasks t JOIN orders o ON o.id=t.order_id
      WHERE o.seller_id=? AND o.status IN('running','review') AND t.status='open' ORDER BY COALESCE(t.due_at,'9999-12-31')");
    $q->execute([$s['id']]);$tasks=$q->fetchAll();

    $q=db()->prepare("SELECT o.order_no,f.title FROM orders o JOIN offers f ON f.id=o.offer_id
      WHERE o.seller_id=? AND o.status='shipping' ORDER BY o.updated_at");
    $q->execute([$s['id']]);$shipping=$q->fetchAll();

    $nowItems=[];$nextItems=[];$laterItems=[];
    foreach($windows as $w){
        $start=new DateTimeImmutable($w['starts_at'],$tz);$end=new DateTimeImmutable($w['ends_at'],$tz);$grace=new DateTimeImmutable($w['grace_ends_at'],$tz);
        $remaining=max(0,(int)$w['required_count']-(int)$w['submitted_count']);
        if($remaining<=0)continue;
        $item=['kind'=>'window','title'=>$w['title'].' · '.window_label($w['window_key']),'text'=>'Tag '.$w['day_no'].' · '.$remaining.' Nachweis(e) offen · bis '.$end->format('H:i').' Uhr','link'=>'/auftrag/'.$w['order_no']];
        if($now >= $start && $now <= $grace)$nowItems[]=$item;elseif($start>$now && $start<=$now->modify('+2 hours'))$nextItems[]=$item;else $laterItems[]=$item;
    }
    foreach($spontaneous as $r){
        $due=new DateTimeImmutable($r['due_at'],$tz);
        $item=['kind'=>'spontaneous','title'=>'Spontane Fotoanforderung · '.$r['order_no'],'text'=>$r['instructions'].' · Frist '.$due->format('H:i').' Uhr','link'=>'/auftrag/'.$r['order_no'].'/spontan/'.$r['id']];
        if($due<=$now->modify('+1 hour'))$nowItems[]=$item;else $nextItems[]=$item;
    }
    foreach($retakes as $r){
        $due=new DateTimeImmutable($r['due_at'],$tz);
        $item=['kind'=>'retake','title'=>'Neuaufnahme erforderlich · '.$r['order_no'],'text'=>$r['instructions'].' · Frist '.$due->format('d.m. H:i').' Uhr','link'=>'/auftrag/'.$r['order_no'].'/retake/'.$r['id']];
        if($due<=$now->modify('+1 hour'))$nowItems[]=$item;else $nextItems[]=$item;
    }
    foreach($tasks as $t){
        $due=$t['due_at']?new DateTimeImmutable($t['due_at'],$tz):null;
        $item=['kind'=>'task','title'=>'Zusatzaufgabe · '.$t['order_no'],'text'=>$t['title'].($due?' · Frist '.$due->format('d.m. H:i').' Uhr':''),'link'=>'/auftrag/'.$t['order_no'].'/aufgabe/'.$t['id']];
        if($due && $due<=$now->modify('+1 hour'))$nowItems[]=$item;else $nextItems[]=$item;
    }
    foreach($shipping as $x)$nextItems[]=['kind'=>'shipping','title'=>'Versand · '.$x['order_no'],'text'=>$x['title'].' · Versandworkflow offen','link'=>'/auftrag/'.$x['order_no'].'/versand'];

    $renderItems=function(array $items): string {
        ob_start();?><div class="timeline"><?php foreach($items as $item):?><div><strong><?=e($item['title'])?></strong><p class="meta"><?=e($item['text'])?></p><a href="<?=e(url($item['link']))?>">Jetzt öffnen →</a></div><?php endforeach;?><?php if(!$items):?><div class="empty">Nichts offen.</div><?php endif;?></div><?php return ob_get_clean();
    };
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Heute · <?=e($now->format('d.m.Y'))?></div><h1>Dein Tagesplan</h1></div><a class="btn secondary" href="<?=e(url('/dashboard'))?>">Alle Aufträge</a></div>
    <div class="grid"><section class="panel"><h2>Jetzt erledigen</h2><?=$renderItems($nowItems)?></section><section class="panel"><h2>Als Nächstes</h2><?=$renderItems($nextItems)?></section><section class="panel"><h2>Heute später</h2><?=$renderItems($laterItems)?></section></div>
    <?php render('Heute',ob_get_clean());exit;
}

if ($path==='/admin/suche' && $method==='GET') {
    require_admin();$q=trim((string)($_GET['q']??''));$sellers=$orders=$offers=$shipments=$payouts=[];
    if($q!==''){
        $like='%'.$q.'%';
        $st=db()->prepare("SELECT id,first_name,last_name,email,phone FROM sellers WHERE deleted_at IS NULL AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?) LIMIT 25");$st->execute([$like,$like,$like,$like]);$sellers=$st->fetchAll();
        $st=db()->prepare("SELECT o.order_no,o.status,f.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id WHERE o.order_no LIKE ? OR f.title LIKE ? LIMIT 25");$st->execute([$like,$like]);$orders=$st->fetchAll();
        $st=db()->prepare("SELECT id,title,status,compensation FROM offers WHERE title LIKE ? OR description LIKE ? LIMIT 25");$st->execute([$like,$like]);$offers=$st->fetchAll();
        $st=db()->prepare("SELECT sh.*,o.order_no FROM shipments sh JOIN orders o ON o.id=sh.order_id WHERE sh.tracking_number LIKE ? LIMIT 25");$st->execute([$like]);$shipments=$st->fetchAll();
        if(is_numeric(str_replace(',','.',$q))){$amount=(float)str_replace(',','.',$q);$st=db()->prepare("SELECT p.*,CONCAT(s.first_name,' ',s.last_name) seller_name FROM payout_requests p JOIN sellers s ON s.id=p.seller_id WHERE p.amount=? LIMIT 25");$st->execute([$amount]);$payouts=$st->fetchAll();}
    }
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Globale Suche</h1></div></div><form class="panel" method="get"><label>Suche nach Name, E-Mail, Telefon, Auftrag, Angebot oder Tracking<input name="q" value="<?=e($q)?>" autofocus></label><button class="btn">Suchen</button></form>
    <?php if($q!==''):?><h2>Verkäuferinnen</h2><div class="table-wrap"><table><tbody><?php foreach($sellers as $x):?><tr><td><?=e($x['first_name'].' '.$x['last_name'])?></td><td><?=e($x['email'])?></td><td><?=e($x['phone'])?></td><td><a href="<?=e(url('/admin/verkaeuferin/'.$x['id']))?>">Akte</a></td></tr><?php endforeach;?></tbody></table></div>
    <h2>Aufträge</h2><div class="table-wrap"><table><tbody><?php foreach($orders as $x):?><tr><td><?=e($x['order_no'])?></td><td><?=e($x['seller_name'])?></td><td><?=e($x['title'])?></td><td><?=e($x['status'])?></td><td><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>">Öffnen</a></td></tr><?php endforeach;?></tbody></table></div>
    <h2>Angebote</h2><div class="table-wrap"><table><tbody><?php foreach($offers as $x):?><tr><td><?=e($x['title'])?></td><td><?=e($x['status'])?></td><td><?=money($x['compensation'])?></td><td><a href="<?=e(url('/admin/angebot/'.$x['id']))?>">Bearbeiten</a></td></tr><?php endforeach;?></tbody></table></div>
    <?php if($shipments):?><h2>Tracking</h2><div class="table-wrap"><table><tbody><?php foreach($shipments as $x):?><tr><td><?=e($x['tracking_number'])?></td><td><?=e($x['order_no'])?></td><td><a href="<?=e(url('/admin/auftrag/'.$x['order_no']))?>">Auftrag</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
    <?php endif;?><?php render('Globale Suche',ob_get_clean());exit;
}

if ($path==='/admin/aufgabenbibliothek' && $method==='GET') {
    require_admin();$rows=db()->query("SELECT * FROM task_library ORDER BY active DESC,title")->fetchAll();
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Aufgabenbibliothek</h1></div></div>
    <form class="panel" method="post"><?=csrf_field()?><label>Titel<input name="title" required></label><label>Beschreibung<textarea name="description"></textarea></label><div class="form-grid"><label>Antworttyp<select name="response_type"><option value="text">Freitext</option><option value="number">Zahl</option><option value="scale10">Skala 1–10</option><option value="boolean">Ja/Nein</option></select></label><label>Pflichtfotos<input type="number" min="0" max="20" name="default_required_photos" value="0"></label><label>Standardvergütung (€)<input type="number" step=".01" min="0" name="default_compensation" value="0"></label></div><label><input style="width:auto" type="checkbox" name="violation_enabled" value="1" checked> Nichterfüllung kann Verstoß auslösen</label><button class="btn">Vorlage speichern</button></form>
    <h2>Vorlagen</h2><div class="table-wrap"><table><thead><tr><th>Titel</th><th>Typ</th><th>Pflichtfotos</th><th>Vergütung</th><th>Status</th></tr></thead><tbody><?php foreach($rows as $x):$fields=json_decode($x['fields_json']??'{}',true)?:[];?><tr><td><?=e($x['title'])?></td><td><?=e($fields['response_type']??'text')?></td><td><?=e($x['default_required_photos']??0)?></td><td><?=money($x['default_compensation'])?></td><td><?=$x['active']?'Aktiv':'Inaktiv'?></td></tr><?php endforeach;?></tbody></table></div>
    <?php render('Aufgabenbibliothek',ob_get_clean());exit;
}
if ($path==='/admin/aufgabenbibliothek' && $method==='POST') {
    require_admin();$fields=json_encode(['response_type'=>post('response_type','text')],JSON_UNESCAPED_UNICODE);
    db()->prepare("INSERT INTO task_library(title,description,fields_json,default_required_photos,default_compensation,violation_enabled,active) VALUES(?,?,?,?,?,?,1)")
      ->execute([post('title'),post('description'),$fields,max(0,(int)post('default_required_photos','0')),max(0,(float)post('default_compensation')),($_POST['violation_enabled']??'')==='1'?1:0]);
    flash('success','Aufgabenvorlage gespeichert.');redirect('/admin/aufgabenbibliothek');
}

if (preg_match('#^/admin/auftrag/(\d{8})/aufgabe$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT * FROM orders WHERE order_no=? AND status IN('running','review')");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $fields=json_encode(['response_type'=>post('response_type','text')],JSON_UNESCAPED_UNICODE);$comp=max(0,(float)post('compensation'));$requiredPhotos=max(0,(int)post('required_photos','0'));
    db()->prepare("INSERT INTO order_tasks(order_id,title,description,due_at,fields_json,required_photos,compensation,violation_enabled) VALUES(?,?,?,?,?,?,?,?)")
      ->execute([$o['id'],post('title'),post('description'),post('due_at')?:null,$fields,$requiredPhotos,$comp,($_POST['violation_enabled']??'')==='1'?1:0]);
    $taskId=(int)db()->lastInsertId();
    if($comp>0){db()->prepare("UPDATE orders SET total_compensation=total_compensation+? WHERE id=?")->execute([$comp,$o['id']]);db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Vergütung Zusatzaufgabe')")->execute([$o['seller_id'],$o['id'],$comp]);}
    notify_seller((int)$o['seller_id'],'task.created','Neue Zusatzaufgabe','Für Auftrag '.$o['order_no'].' wurde eine Zusatzaufgabe hinzugefügt: '.post('title'),'/auftrag/'.$o['order_no'].'/aufgabe/'.$taskId,'task-created-'.$taskId,true);
    flash('success','Zusatzaufgabe hinzugefügt.');redirect('/admin/auftrag/'.$o['order_no']);
}
if (preg_match('#^/admin/auftrag/(\d{8})/aufgabe-aus-vorlage$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT * FROM orders WHERE order_no=? AND status IN('running','review')");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $q=db()->prepare("SELECT * FROM task_library WHERE id=? AND active=1");$q->execute([(int)post('template_id')]);$t=$q->fetch();if(!$t)not_found();
    $comp=(float)$t['default_compensation'];$requiredPhotos=max(0,(int)($t['default_required_photos']??0));db()->prepare("INSERT INTO order_tasks(order_id,title,description,due_at,fields_json,required_photos,compensation,violation_enabled) VALUES(?,?,?,?,?,?,?,?)")
      ->execute([$o['id'],$t['title'],$t['description'],post('due_at')?:null,$t['fields_json'],$requiredPhotos,$comp,$t['violation_enabled']]);
    $taskId=(int)db()->lastInsertId();
    if($comp>0){db()->prepare("UPDATE orders SET total_compensation=total_compensation+? WHERE id=?")->execute([$comp,$o['id']]);db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Vergütung Zusatzaufgabe')")->execute([$o['seller_id'],$o['id'],$comp]);}
    notify_seller((int)$o['seller_id'],'task.created','Neue Zusatzaufgabe','Für Auftrag '.$o['order_no'].' wurde eine Zusatzaufgabe hinzugefügt: '.$t['title'],'/auftrag/'.$o['order_no'].'/aufgabe/'.$taskId,'task-created-'.$taskId,true);
    flash('success','Aufgabe aus Vorlage hinzugefügt.');redirect('/admin/auftrag/'.$o['order_no']);
}

if (preg_match('#^/auftrag/(\d{8})/aufgabe/(\d+)$#',$path,$m) && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT t.*,o.order_no,o.status order_status,o.archived_at FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE o.order_no=? AND t.id=? AND o.seller_id=?");
    $q->execute([$m[1],(int)$m[2],$s['id']]);$t=$q->fetch();if(!$t)not_found();
    $fields=json_decode($t['fields_json']??'{}',true)?:[];$type=$fields['response_type']??'text';
    $q=db()->prepare("SELECT * FROM evidences WHERE source_type='task' AND source_id=? ORDER BY created_at");
    $q->execute([$t['id']]);$taskEvidence=$q->fetchAll();
    $validPhotos=count(array_filter($taskEvidence,fn($e)=>in_array($e['status'],['submitted','accepted'],true)));
    $requiredPhotos=max(0,(int)($t['required_photos']??0));
    $canWork=in_array($t['status'],['open','rejected'],true) && empty($t['archived_at']);

    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Zusatzaufgabe · <?=e($t['order_no'])?></div><h1><?=e($t['title'])?></h1></div><a class="btn secondary" href="<?=e(url('/auftrag/'.$t['order_no']))?>">Zum Auftrag</a></div>
    <div class="grid two">
      <section class="panel"><h2>Aufgabe</h2><p><?=nl2br(e($t['description']??''))?></p><p class="meta">Frist: <?=e($t['due_at']?date('d.m.Y H:i',strtotime($t['due_at'])):'keine feste Frist')?><?php if((float)$t['compensation']>0):?><br>Vergütung: <?=money($t['compensation'])?><?php endif;?><?php if($t['planned_day_no']):?><br>Geplant für Durchführungstag <?=e($t['planned_day_no'])?><?php endif;?></p>
      <?php if($requiredPhotos>0):?><h3>Pflichtfotos</h3><p><strong><?=e($validPhotos)?> / <?=e($requiredPhotos)?></strong> gültig eingereicht</p><div class="progress"><span style="width:<?=e((string)min(100,round(($validPhotos/$requiredPhotos)*100)))?>%"></span></div>
      <div class="timeline" style="margin-top:12px"><?php foreach($taskEvidence as $ev):?><div>Foto <?=e($ev['id'])?> · <span class="badge"><?=e($ev['status'])?></span> · <?=e(date('d.m.Y H:i',strtotime($ev['created_at'])))?><?php if($ev['status']==='rejected'&&$ev['rejection_reason']):?><br><span class="meta"><?=e($ev['rejection_reason'])?></span><?php endif;?></div><?php endforeach;?></div>
      <?php if($canWork && $validPhotos<$requiredPhotos):?><form method="post" action="<?=e(url('/auftrag/'.$t['order_no'].'/aufgabe/'.$t['id'].'/foto'))?>" enctype="multipart/form-data" style="margin-top:12px"><?=csrf_field()?><label>Nächstes Pflichtfoto<input data-camera-input type="file" name="evidence" accept="image/*" capture="environment" required></label><button class="btn secondary">Foto einreichen</button></form><?php endif;?>
      <?php endif;?></section>
      <form class="panel" method="post"><?=csrf_field()?><h2>Antwort & Abschluss</h2>
        <?php if($type==='number'):?><input type="number" step="any" name="value" required>
        <?php elseif($type==='scale10'):?><select name="value"><?php for($n=1;$n<=10;$n++):?><option value="<?=$n?>"><?=$n?></option><?php endfor;?></select>
        <?php elseif($type==='boolean'):?><select name="value"><option value="Ja">Ja</option><option value="Nein">Nein</option></select>
        <?php else:?><textarea name="value" required></textarea><?php endif;?>
        <?php if($requiredPhotos>0 && $validPhotos<$requiredPhotos):?><p class="meta">Die Aufgabe kann erst final eingereicht werden, wenn alle <?=e($requiredPhotos)?> Pflichtfotos vorhanden sind.</p><?php endif;?>
        <button class="btn" <?=(!$canWork||$validPhotos<$requiredPhotos)?'disabled':''?>>Aufgabe final einreichen</button>
      </form>
    </div>
    <?php render('Zusatzaufgabe',ob_get_clean());exit;
}
if (preg_match('#^/auftrag/(\d{8})/aufgabe/(\d+)/foto$#',$path,$m) && $method==='POST') {
    $s=require_seller();
    $q=db()->prepare("SELECT t.*,o.order_no,o.archived_at FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE o.order_no=? AND t.id=? AND o.seller_id=?");
    $q->execute([$m[1],(int)$m[2],$s['id']]);$t=$q->fetch();if(!$t)not_found();
    if(!in_array($t['status'],['open','rejected'],true)||$t['archived_at']){flash('error','Für diese Aufgabe können keine weiteren Fotos eingereicht werden.');redirect('/auftrag/'.$t['order_no'].'/aufgabe/'.$t['id']);}
    $required=max(0,(int)($t['required_photos']??0));
    $q=db()->prepare("SELECT COUNT(*) FROM evidences WHERE source_type='task' AND source_id=? AND status IN('submitted','accepted')");
    $q->execute([$t['id']]);if((int)$q->fetchColumn()>=$required){flash('error','Alle geforderten Pflichtfotos sind bereits vorhanden.');redirect('/auftrag/'.$t['order_no'].'/aufgabe/'.$t['id']);}
    try{
      $up=private_upload($_FILES['evidence']??[],'order-'.$t['order_id'].'/tasks');
      $late=$t['due_at']&&strtotime($t['due_at'])<time()?1:0;
      db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,source_type,source_id,file_path,mime_type,file_size,sha256,metadata_json,quality_flags_json,is_late) VALUES(?,?,?,'task','task',?,?,?,?,?,?,?,?)")
        ->execute([$t['order_id'],current_run_id((int)$t['order_id']),$s['id'],$t['id'],$up['path'],$up['mime'],$up['size'],$up['sha256'],upload_metadata_json($up),upload_quality_flags_json($up),$late]);
      flash('success','Pflichtfoto wurde eingereicht.');
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('/auftrag/'.$t['order_no'].'/aufgabe/'.$t['id']);
}

if (preg_match('#^/auftrag/(\d{8})/aufgabe/(\d+)$#',$path,$m) && $method==='POST') {
    $s=require_seller();$q=db()->prepare("SELECT t.*,o.seller_id,o.order_no FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE o.order_no=? AND t.id=? AND o.seller_id=? AND t.status IN('open','rejected')");$q->execute([$m[1],(int)$m[2],$s['id']]);$t=$q->fetch();if(!$t)not_found();
    $required=max(0,(int)($t['required_photos']??0));$pc=db()->prepare("SELECT COUNT(*) FROM evidences WHERE source_type='task' AND source_id=? AND status IN('submitted','accepted')");$pc->execute([$t['id']]);
    if((int)$pc->fetchColumn()<$required){flash('error','Bitte reiche zuerst alle Pflichtfotos ein.');redirect('/auftrag/'.$t['order_no'].'/aufgabe/'.$t['id']);}
    $payload=json_encode(['value'=>post('value')],JSON_UNESCAPED_UNICODE);db()->prepare("UPDATE order_tasks SET submission_json=?,status='submitted',submitted_at=NOW() WHERE id=?")->execute([$payload,$t['id']]);
    flash('success','Zusatzaufgabe wurde eingereicht.');redirect('/auftrag/'.$t['order_no']);
}
if (preg_match('#^/admin/aufgabe/(\d+)/(akzeptieren|ablehnen)$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT t.*,o.order_no,o.seller_id FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE t.id=?");$q->execute([(int)$m[1]]);$t=$q->fetch();if(!$t)not_found();
    if($m[2]==='akzeptieren'){db()->prepare("UPDATE order_tasks SET status='accepted' WHERE id=?")->execute([$t['id']]);notify_seller((int)$t['seller_id'],'task.accepted','Zusatzaufgabe akzeptiert','Die Zusatzaufgabe „'.$t['title'].'“ wurde akzeptiert.','/auftrag/'.$t['order_no'],null,false);}
    else{db()->prepare("UPDATE order_tasks SET status='rejected' WHERE id=?")->execute([$t['id']]);if((int)$t['violation_enabled']===1){$source='task:'.$t['id'];$ins=db()->prepare("INSERT IGNORE INTO violations(order_id,violation_type,status,reason,source_key,extension_days) VALUES(?,'task_incomplete','open',?,?,1)");$ins->execute([$t['order_id'],'Zusatzaufgabe nicht erfüllt: '.$t['title'],$source]);if($ins->rowCount()){ $vid=(int)db()->lastInsertId();db()->prepare("INSERT INTO extra_days(order_id,source_type,source_id,status,paid,amount,reason) VALUES(?,'violation',?,'provisional',0,0,?)")->execute([$t['order_id'],$vid,'Zusatzaufgabe nicht erfüllt: '.$t['title']]); }}notify_seller((int)$t['seller_id'],'task.rejected','Zusatzaufgabe beanstandet','Die Zusatzaufgabe „'.$t['title'].'“ wurde beanstandet.','/auftrag/'.$t['order_no'],null,true);}
    flash('success','Aufgabe geprüft.');redirect('/admin/auftrag/'.$t['order_no']);
}
if (preg_match('#^/admin/spontan/(\d+)/abschliessen$#',$path,$m) && $method==='POST') {
    require_admin();$q=db()->prepare("SELECT r.*,o.order_no FROM spontaneous_requests r JOIN orders o ON o.id=r.order_id WHERE r.id=?");$q->execute([(int)$m[1]]);$r=$q->fetch();if(!$r)not_found();db()->prepare("UPDATE spontaneous_requests SET status='reviewed' WHERE id=?")->execute([$r['id']]);flash('success','Spontane Anforderung als geprüft abgeschlossen.');redirect('/admin/auftrag/'.$r['order_no']);
}

if ($path==='/admin/einzelangebote' && $method==='GET') {
    require_admin();
    $sellers=db()->query("SELECT id,first_name,last_name,email FROM sellers WHERE deleted_at IS NULL ORDER BY last_name,first_name")->fetchAll();
    $cats=db()->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order,name")->fetchAll();
    $rows=db()->query("SELECT a.*,f.title,f.compensation,f.fulfillment_type,CONCAT(s.first_name,' ',s.last_name) seller_name FROM offer_assignments a JOIN offers f ON f.id=a.offer_id JOIN sellers s ON s.id=a.seller_id ORDER BY a.created_at DESC")->fetchAll();
    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Individuelle Angebote</h1><p class="meta">Private Angebote sind ausschließlich der ausgewählten Verkäuferin sichtbar.</p></div></div>
    <form class="panel" method="post"><?=csrf_field()?>
      <div class="form-grid">
        <label>Verkäuferin<select name="seller_id" required><?php foreach($sellers as $x):?><option value="<?=$x['id']?>"><?=e($x['last_name'].', '.$x['first_name'].' · '.$x['email'])?></option><?php endforeach;?></select></label>
        <label>Kategorie<select name="category_id" required><?php foreach($cats as $cat):?><option value="<?=$cat['id']?>"><?=e($cat['name'])?></option><?php endforeach;?></select></label>
        <label>Titel<input name="title" required></label>
        <label>Vergütung (€)<input type="number" step=".01" min="0" name="compensation" required></label>
        <label>Erfüllungsart<select name="fulfillment_type"><option value="days">Tage</option><option value="units">Einheiten</option><option value="one_time">Einmalig</option><option value="digital">Digital</option><option value="mixed">Kombiniert</option></select></label>
        <label>Dauer in Tagen<input type="number" min="1" name="duration_days"></label>
        <label>Annahmefrist<input type="datetime-local" name="acceptance_deadline" required></label>
        <label>Vorab-Pflichtfotos<input type="number" min="1" max="50" name="precheck_required_count" value="1"></label>
        <label>Morgen-Fotos<input type="number" min="0" max="20" name="morning_count" value="1"></label>
        <label>Mittag-Fotos<input type="number" min="0" max="20" name="midday_count" value="1"></label>
        <label>Abend-Fotos<input type="number" min="0" max="20" name="evening_count" value="1"></label>
      </div>
      <label>Beschreibung / individuelle Bedingungen<textarea name="description" required></textarea></label>
      <button class="btn">Privates Einzelangebot senden</button>
    </form>
    <h2>Zuweisungen</h2>
    <div class="table-wrap"><table><thead><tr><th>Verkäuferin</th><th>Angebot</th><th>Vergütung</th><th>Frist</th><th>Status</th></tr></thead><tbody>
    <?php foreach($rows as $r):?><tr><td><?=e($r['seller_name'])?></td><td><?=e($r['title'])?></td><td><?=money($r['compensation'])?></td><td><?=e(date('d.m.Y H:i',strtotime($r['acceptance_deadline'])))?></td><td><?=e($r['status'])?></td></tr><?php endforeach;?>
    </tbody></table></div>
    <?php render('Individuelle Angebote',ob_get_clean());exit;
}
if ($path==='/admin/einzelangebote' && $method==='POST') {
    require_admin();
    $sellerId=(int)post('seller_id');$categoryId=(int)post('category_id');$title=post('title');$description=post('description');
    $comp=max(0,(float)post('compensation'));$type=post('fulfillment_type','days');$days=post('duration_days')!==''?(int)post('duration_days'):null;$deadlineRaw=post('acceptance_deadline');
    if($title===''||$description===''||$sellerId<1||$categoryId<1||$deadlineRaw===''){flash('error','Bitte alle Pflichtfelder ausfüllen.');redirect('/admin/einzelangebote');}
    $deadline=new DateTimeImmutable($deadlineRaw,new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
    if($deadline<=new DateTimeImmutable('now',$deadline->getTimezone())){flash('error','Die Annahmefrist muss in der Zukunft liegen.');redirect('/admin/einzelangebote');}
    $rules=['precheck_required_count'=>max(1,(int)post('precheck_required_count','1')),'daily'=>['morning'=>max(0,(int)post('morning_count','1')),'midday'=>max(0,(int)post('midday_count','1')),'evening'=>max(0,(int)post('evening_count','1'))]];
    $slug='privat-'.date('YmdHis').'-'.substr(bin2hex(random_bytes(6)),0,10);
    db()->beginTransaction();
    try{
        db()->prepare("INSERT INTO offers(category_id,title,slug,description,compensation,duration_days,fulfillment_type,evidence_rules_json,status,visibility,current_version) VALUES(?,?,?,?,?,?,?,?,'active','private',1)")
          ->execute([$categoryId,$title,$slug,$description,$comp,$days,$type,json_encode($rules,JSON_UNESCAPED_UNICODE)]);
        $offerId=(int)db()->lastInsertId();
        $snapshot=['title'=>$title,'category_id'=>$categoryId,'description'=>$description,'compensation'=>$comp,'duration_days'=>$days,'fulfillment_type'=>$type,'evidence_rules'=>$rules,'visibility'=>'private'];
        db()->prepare("INSERT INTO offer_versions(offer_id,version_no,snapshot_json) VALUES(?,1,?)")->execute([$offerId,json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);
        db()->prepare("INSERT INTO offer_assignments(offer_id,seller_id,acceptance_deadline,status) VALUES(?,?,?,'assigned')")->execute([$offerId,$sellerId,$deadline->format('Y-m-d H:i:s')]);
        $assignmentId=(int)db()->lastInsertId();
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    notify_seller($sellerId,'offer.assignment','Individuelles Angebot','Dir wurde das individuelle Angebot „'.$title.'“ zugewiesen. Annahmefrist: '.$deadline->format('d.m.Y H:i').'.','/individuelle-angebote','assignment-'.$assignmentId.'-created',true);
    flash('success','Individuelles Angebot wurde privat zugewiesen.');redirect('/admin/einzelangebote');
}
if ($path==='/individuelle-angebote' && $method==='GET') {
    $s=require_seller();
    $q=db()->prepare("SELECT a.id assignment_id,a.acceptance_deadline,a.status assignment_status,a.decline_reason,a.created_at,f.* FROM offer_assignments a JOIN offers f ON f.id=a.offer_id WHERE a.seller_id=? ORDER BY a.created_at DESC");
    $q->execute([$s['id']]);$rows=$q->fetchAll();
    $fq=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=?");$fq->execute([$s['id']]);$isFirstOrder=(int)$fq->fetchColumn()===0;
    ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Privat</div><h1>Individuelle Angebote</h1></div></div><div class="grid">
    <?php foreach($rows as $r): $rRules=offer_evidence_rules($r);$rDaily=array_sum($rRules['daily']);$rTasks=offer_task_plan_summary((int)$r['offer_id'],$r['duration_days']!==null?(int)$r['duration_days']:1);$rShipping=build_shipping_snapshot($r);$rAllowance=($rShipping['cost_mode']??'seller')==='fixed'?(float)($rShipping['allowance']??0):0.0;$rComponentExtra=offer_component_extra_total($r);$rTotal=(float)$r['compensation']+$rComponentExtra+(float)$rTasks['compensation']+$rAllowance;?><article class="card"><span class="badge"><?=e($r['assignment_status'])?></span><h2><?=e($r['title'])?></h2><p><?=nl2br(e($r['description']))?></p><div class="price"><?=money($rTotal)?></div><p class="meta"><?=e($r['duration_days']?$r['duration_days'].' Tage':ucfirst($r['fulfillment_type']))?> · Annahmefrist <?=e(date('d.m.Y H:i',strtotime($r['acceptance_deadline'])))?></p>
      <div class="timeline"><div>Vorabnachweise: <strong><?=e($rRules['precheck_required_count'])?></strong></div><div>Regel-Nachweise: <strong><?=e($rDaily)?> pro Tag</strong></div><div>Geplante Zusatzaufgaben: <strong><?=e($rTasks['executions'])?></strong></div><div>Versand-/Endschritte: <strong><?=e(count($rShipping['steps']??[]))?></strong></div><?php if($rComponentExtra>0):?><div>Kombi-/Zusatzbestandteile: <strong>+<?=money($rComponentExtra)?></strong></div><?php endif;?><?php if($rTasks['compensation']>0):?><div>Geplante Aufgaben: <strong>+<?=money($rTasks['compensation'])?></strong></div><?php endif;?><?php if($rAllowance>0):?><div>Versandzuschuss: <strong>+<?=money($rAllowance)?></strong></div><?php endif;?></div>
      <p class="meta">Spontane Nachweise, Neuaufnahmen und bestätigte Verstöße können zusätzlichen Aufwand bzw. unbezahlte Zusatztage verursachen.</p>
      <?php if($r['assignment_status']==='assigned'&&strtotime($r['acceptance_deadline'])>time()):?><div class="grid two"><form method="post" action="<?=e(url('/individuelle-angebote/'.$r['assignment_id'].'/annehmen'))?>"><?=csrf_field()?>
        <?php if($isFirstOrder):?><label><input type="checkbox" style="width:auto" name="confirm_briefing" value="1" required> Pflicht-Kurzbriefing gelesen und verstanden</label><?php endif;?>
        <label><input type="checkbox" style="width:auto" name="confirm_personal" value="1" required> Ich erfülle den Auftrag persönlich und mit eigenen Artikeln/Inhalten.</label>
        <label><input type="checkbox" style="width:auto" name="confirm_effort" value="1" required> Ich habe Aufwand, Nachweise, Aufgaben, Bestandteile und Versand geprüft.</label>
        <label><input type="checkbox" style="width:auto" name="confirm_rules" value="1" required> Ich akzeptiere die Bedingungen dieser Angebotsversion.</label>
        <label><input type="checkbox" style="width:auto" name="confirm_violation" value="1" required> Mir sind mögliche unbezahlte Zusatztage bei bestätigten Verstößen bekannt.</label>
        <button class="btn">Verbindlich annehmen</button></form><form method="post" action="<?=e(url('/individuelle-angebote/'.$r['assignment_id'].'/ablehnen'))?>"><?=csrf_field()?><label>Ablehnungsgrund<input name="reason" required></label><button class="btn secondary">Ablehnen</button></form></div><?php endif;?>
      <?php if($r['assignment_status']==='declined'&&$r['decline_reason']):?><p class="meta">Ablehnungsgrund: <?=e($r['decline_reason'])?></p><?php endif;?>
    </article><?php endforeach;?><?php if(!$rows):?><div class="empty">Keine individuellen Angebote.</div><?php endif;?></div><?php render('Individuelle Angebote',ob_get_clean());exit;
}
if (preg_match('#^/individuelle-angebote/(\d+)/(annehmen|ablehnen)$#',$path,$m) && $method==='POST') {
    $s=require_seller();
    $q=db()->prepare("SELECT a.id assignment_id,a.acceptance_deadline,a.status assignment_status,a.offer_id,f.* FROM offer_assignments a JOIN offers f ON f.id=a.offer_id WHERE a.id=? AND a.seller_id=? AND a.status='assigned'");
    $q->execute([(int)$m[1],$s['id']]);$a=$q->fetch();if(!$a)not_found();
    if(strtotime($a['acceptance_deadline'])<=time()){db()->prepare("UPDATE offer_assignments SET status='expired',updated_at=NOW() WHERE id=?")->execute([$a['assignment_id']]);flash('error','Die Annahmefrist ist abgelaufen.');redirect('/individuelle-angebote');}
    if($m[2]==='ablehnen'){
        $reason=post('reason');if($reason===''){flash('error','Bitte einen Ablehnungsgrund angeben.');redirect('/individuelle-angebote');}
        db()->prepare("UPDATE offer_assignments SET status='declined',decline_reason=?,updated_at=NOW() WHERE id=?")->execute([$reason,$a['assignment_id']]);
        log_event('private_offer.declined',(int)$s['id'],null,['assignment_id'=>(int)$a['assignment_id'],'reason'=>$reason]);
        flash('success','Individuelles Angebot abgelehnt.');redirect('/individuelle-angebote');
    }
    foreach(['confirm_personal','confirm_effort','confirm_rules','confirm_violation'] as $requiredConfirm){
        if(($_POST[$requiredConfirm]??'')!=='1'){flash('error','Bitte bestätige alle Pflichtpunkte vor der Annahme.');redirect('/individuelle-angebote');}
    }
    $firstQ=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=?");$firstQ->execute([$s['id']]);$isFirstOrder=(int)$firstQ->fetchColumn()===0;
    if($isFirstOrder && ($_POST['confirm_briefing']??'')!=='1'){flash('error','Vor dem ersten Auftrag muss das Kurzbriefing bestätigt werden.');redirect('/individuelle-angebote');}
    if(!$s['email_verified_at']){flash('error','Bitte bestätige zuerst deine E-Mail-Adresse.');redirect('/individuelle-angebote');}
    $blockedCategories=offer_blocked_category_ids($a);
    if(seller_has_category_conflict((int)$s['id'],$blockedCategories)){flash('error','Mindestens eine in diesem individuellen Angebot enthaltene Kategorie ist bereits durch einen aktiven Auftrag belegt.');redirect('/individuelle-angebote');}

    $shippingSnapshot=build_shipping_snapshot($a);
    $shippingAllowance=$shippingSnapshot['cost_mode']==='fixed' ? max(0,(float)$shippingSnapshot['allowance']) : 0.0;
    $componentExtra=offer_component_extra_total($a);
    $taskSummary=offer_task_plan_summary((int)$a['offer_id'],$a['duration_days']!==null?(int)$a['duration_days']:1);
    $plannedTaskCompensation=(float)$taskSummary['compensation'];
    $acceptanceRules=offer_evidence_rules($a);
    $total=(float)$a['compensation']+$componentExtra+$plannedTaskCompensation+$shippingAllowance;
    $no=order_number();
    db()->beginTransaction();
    try{
        db()->prepare("INSERT INTO orders(order_no,seller_id,offer_id,offer_version,status,base_compensation,total_compensation,duration_days,shipping_snapshot_json) VALUES(?,?,?,?, 'precheck',?,?,?,?)")
          ->execute([$no,$s['id'],$a['offer_id'],$a['current_version'],$a['compensation'],$total,$a['duration_days'],json_encode($shippingSnapshot,JSON_UNESCAPED_UNICODE)]);
        $oid=(int)db()->lastInsertId();
        $confirmationPayload=[
          'order_no'=>$no,'accepted_at'=>date(DATE_ATOM),'private_offer'=>true,
          'personal_fulfillment'=>true,'effort_reviewed'=>true,'rules_accepted'=>true,'violation_consequences_acknowledged'=>true,'first_order_briefing'=>$isFirstOrder,
          'offer_id'=>(int)$a['offer_id'],'offer_version'=>(int)$a['current_version'],'title'=>(string)$a['title'],'description'=>(string)$a['description'],
          'category_id'=>(int)$a['category_id'],'fulfillment_type'=>(string)$a['fulfillment_type'],'duration_days'=>$a['duration_days']!==null?(int)$a['duration_days']:null,
          'base_compensation'=>(float)$a['compensation'],'component_extra_compensation'=>$componentExtra,'options_total'=>0.0,'selected_options'=>[],
          'evidence_rules'=>$acceptanceRules,'precheck_photos'=>(int)$acceptanceRules['precheck_required_count'],'daily_photos_per_day'=>array_sum($acceptanceRules['daily']),
          'planned_task_executions'=>(int)$taskSummary['executions'],'planned_task_photos'=>(int)$taskSummary['required_photos'],'planned_task_compensation'=>$plannedTaskCompensation,
          'shipping_step_count'=>count($shippingSnapshot['steps']??[]),'shipping_cost_mode'=>$shippingSnapshot['cost_mode']??'seller','shipping_allowance'=>$shippingAllowance,
          'preferred_carrier'=>$shippingSnapshot['preferred_carrier']??null,'shipping_instructions'=>$shippingSnapshot['instructions']??null,'total_compensation'=>$total,
        ];
        db()->prepare("INSERT INTO order_acceptance_confirmations(order_id,seller_id,offer_version,payload_json) VALUES(?,?,?,?)")
          ->execute([$oid,$s['id'],$a['current_version'],json_encode($confirmationPayload,JSON_UNESCAPED_UNICODE)]);
        db()->prepare("INSERT INTO order_runs(order_id,run_no,status) VALUES(?,1,'precheck')")->execute([$oid]);
        snapshot_order_components($oid,$a);
        snapshot_offer_task_plans((int)$a['offer_id'],$oid);
        db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Individueller Auftragswert vorgemerkt')")->execute([$s['id'],$oid,$total]);
        if(in_array($a['fulfillment_type'],['digital','mixed'],true)){
            db()->prepare("INSERT INTO rights_acceptances(order_id,seller_id,terms_version,payload_json) VALUES(?,?,?,?)")
              ->execute([$oid,$s['id'],'v1',json_encode(['scope'=>'technical_processing_and_order_terms','private_offer'=>true],JSON_UNESCAPED_UNICODE)]);
        }
        db()->prepare("UPDATE offer_assignments SET status='accepted',updated_at=NOW() WHERE id=?")->execute([$a['assignment_id']]);
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")->execute([$oid,'Individuelles Angebot wurde angenommen. Auftrag '.$no.' wurde angelegt.']);
        log_event('private_offer.accepted',(int)$s['id'],$oid,['assignment_id'=>(int)$a['assignment_id'],'shipping_allowance'=>$shippingAllowance,'component_extra_compensation'=>$componentExtra,'planned_task_compensation'=>$plannedTaskCompensation,'planned_task_executions'=>$taskSummary['executions'],'blocked_category_ids'=>$blockedCategories,'total'=>$total]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}
    $shippingText=($shippingSnapshot['cost_mode']??'seller')==='fixed'
      ? 'Fester Versandzuschuss: '.money($shippingAllowance)
      : ((($shippingSnapshot['cost_mode']??'seller')==='reimburse')?'Versandkosten: Erstattung gegen Nachweis':'Versandkosten trägt die Verkäuferin');
    send_app_mail(
      (string)$s['email'],
      'Auftragsbestätigung '.$no,
      '<h1>Auftragsbestätigung '.$no.'</h1><p>Du hast das individuelle Angebot <strong>'.e($a['title']).'</strong> verbindlich angenommen.</p>'.
      '<p>Angebotsversion: <strong>V'.e($a['current_version']).'</strong><br>Grundvergütung: <strong>'.money($a['compensation']).'</strong><br>'.
      ($componentExtra>0?'Kombi-/Zusatzbestandteile: <strong>+'.money($componentExtra).'</strong><br>':'').
      ($plannedTaskCompensation>0?'Geplante Aufgaben: <strong>+'.money($plannedTaskCompensation).'</strong><br>':'').
      ($shippingAllowance>0?'Versandzuschuss: <strong>+'.money($shippingAllowance).'</strong><br>':'').
      'Gesamtwert bei Annahme: <strong>'.money($total).'</strong></p>'.
      '<p>Dauer: '.($a['duration_days']!==null?e($a['duration_days']).' Tage':'individueller Umfang').'<br>Vorabnachweise: '.e($acceptanceRules['precheck_required_count']).' Foto(s)<br>Regel-Nachweise pro Tag: '.e(array_sum($acceptanceRules['daily'])).' Foto(s)<br>Geplante Zusatzaufgaben: '.e($taskSummary['executions']).'<br>Versand: '.e($shippingText).'</p>'.
      '<p><a href="'.e(url('/auftrag/'.$no)).'">Auftragsbestätigung in der Plattform öffnen</a></p>'
    );
    flash('success','Individuelles Angebot angenommen. Auftrag '.$no.' wurde erstellt. Gesamtwert: '.money($total).'. Eine Auftragsbestätigung wurde per E-Mail versendet.');redirect('/auftrag/'.$no);
}



if (preg_match('#^/auftrag/(\d{8})/optionen$#',$path,$m) && $method==='POST') {
    $s=require_seller();
    $q=db()->prepare("SELECT o.*,f.id offer_id FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");
    $q->execute([$m[1],$s['id']]);$o=$q->fetch();if(!$o)not_found();
    if($o['status']!=='precheck' || $o['started_at']){flash('error','Zusatzoptionen können von dir nur vor dem tatsächlichen Auftragsstart geändert werden.');redirect('/auftrag/'.$o['order_no']);}

    $requested=array_values(array_unique(array_map('intval',(array)($_POST['option_ids']??[]))));
    $selected=[];
    if($requested){
        $ph=implode(',',array_fill(0,count($requested),'?'));
        $args=array_merge([$o['offer_id']],$requested);
        $q=db()->prepare("SELECT * FROM offer_options WHERE offer_id=? AND active=1 AND id IN ($ph) ORDER BY id");
        $q->execute($args);$selected=$q->fetchAll();
        if(count($selected)!==count($requested)){flash('error','Mindestens eine ausgewählte Option ist nicht mehr verfügbar.');redirect('/auftrag/'.$o['order_no']);}
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
            db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Optionsänderung vor Auftragsstart')")
              ->execute([$s['id'],$o['id'],$delta]);
        }
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
          ->execute([$o['id'],'Zusatzoptionen vor Auftragsstart aktualisiert. Änderung des Auftragswerts: '.money($delta).'.']);
        log_event('order.options_changed',(int)$s['id'],(int)$o['id'],['option_ids'=>$requested,'old_options_total'=>$oldTotal,'new_options_total'=>$newTotal,'delta'=>$delta]);
        db()->commit();
    }catch(Throwable $e){db()->rollBack();throw $e;}

    flash('success','Zusatzoptionen gespeichert. Neuer Auftragswert: '.money((float)$o['total_compensation']+$delta).'.');
    redirect('/auftrag/'.$o['order_no']);
}

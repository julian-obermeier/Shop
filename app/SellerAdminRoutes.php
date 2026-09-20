<?php
declare(strict_types=1);

/**
 * Administrative seller record and account lifecycle.
 * Loaded before FeatureRoutes.php so this module owns /admin/verkaeuferinnen*.
 */

if ($path==='/admin/verkaeuferinnen' && $method==='GET') {
    require_admin();

    $status=(string)($_GET['status']??'all');
    if(!in_array($status,['all','active','deleted'],true)) $status='all';

    $sql="SELECT s.*,COUNT(o.id) orders_count
          FROM sellers s
          LEFT JOIN orders o ON o.seller_id=s.id";
    if($status==='active') $sql.=" WHERE s.deleted_at IS NULL";
    elseif($status==='deleted') $sql.=" WHERE s.deleted_at IS NOT NULL";
    $sql.=" GROUP BY s.id ORDER BY s.created_at DESC";
    $rows=db()->query($sql)->fetchAll();

    ob_start();?>
    <div class="dashboard-head">
      <div><div class="eyebrow">Administration</div><h1>Verkäuferinnen</h1><p class="meta">Aktive, deaktivierte und anonymisierte Konten mit vollständiger Akte.</p></div>
      <div class="actions">
        <a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen?status=all'))?>">Alle</a>
        <a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen?status=active'))?>">Aktiv</a>
        <a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen?status=deleted'))?>">Deaktiviert</a>
      </div>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>E-Mail</th><th>Status</th><th>Verifiziert</th><th>Aufträge</th><th></th></tr></thead>
      <tbody>
      <?php foreach($rows as $r): $anon=str_ends_with((string)$r['email'],'@invalid.local');?>
        <tr>
          <td><?=e($r['first_name'].' '.$r['last_name'])?></td>
          <td><?=e($r['email'])?></td>
          <td><span class="badge <?=$r['deleted_at']?'bad':''?>"><?=e($anon?'Anonymisiert':($r['deleted_at']?'Deaktiviert':'Aktiv'))?></span></td>
          <td><?=$r['email_verified_at']?'Ja':'Nein'?></td>
          <td><?=e($r['orders_count'])?></td>
          <td><a href="<?=e(url('/admin/verkaeuferin/'.$r['id']))?>">Akte</a></td>
        </tr>
      <?php endforeach;?>
      <?php if(!$rows):?><tr><td colspan="6">Keine Konten in dieser Auswahl.</td></tr><?php endif;?>
      </tbody>
    </table></div>
    <?php render('Verkäuferinnen',ob_get_clean());exit;
}

if (preg_match('#^/admin/verkaeuferin/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();
    $id=(int)$m[1];

    $q=db()->prepare("SELECT * FROM sellers WHERE id=?");
    $q->execute([$id]);
    $s=$q->fetch();
    if(!$s) not_found();

    $pq=db()->prepare("SELECT * FROM payout_profiles WHERE seller_id=?");
    $pq->execute([$id]);
    $pay=$pq->fetch()?:[];

    $oq=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? ORDER BY o.created_at DESC");
    $oq->execute([$id]);
    $orders=$oq->fetchAll();

    $aq=db()->prepare("SELECT a.*,f.title FROM offer_assignments a JOIN offers f ON f.id=a.offer_id WHERE a.seller_id=? ORDER BY a.created_at DESC");
    $aq->execute([$id]);
    $assignments=$aq->fetchAll();

    $rq=db()->prepare("SELECT * FROM payout_requests WHERE seller_id=? ORDER BY created_at DESC");
    $rq->execute([$id]);
    $payouts=$rq->fetchAll();

    $wq=db()->prepare("SELECT entry_type,COALESCE(SUM(amount),0) total FROM wallet_entries WHERE seller_id=? GROUP BY entry_type");
    $wq->execute([$id]);
    $wallet=[];
    foreach($wq->fetchAll() as $wr) $wallet[$wr['entry_type']]=(float)$wr['total'];

    $eq=db()->prepare("SELECT * FROM system_events WHERE seller_id=? ORDER BY created_at DESC LIMIT 100");
    $eq->execute([$id]);
    $events=$eq->fetchAll();

    $lq=db()->prepare("SELECT * FROM seller_legal_acceptances WHERE seller_id=? ORDER BY created_at DESC");
    $lq->execute([$id]);
    $legal=$lq->fetchAll();

    $activeQ=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status NOT IN('completed','rejected','archived')");
    $activeQ->execute([$id]);
    $activeOrders=(int)$activeQ->fetchColumn();

    $openPayQ=db()->prepare("SELECT COUNT(*) FROM payout_requests WHERE seller_id=? AND status IN('requested','review','released')");
    $openPayQ->execute([$id]);
    $openPayouts=(int)$openPayQ->fetchColumn();

    $anon=str_ends_with((string)$s['email'],'@invalid.local');
    $available=(float)($wallet['available']??0)+(float)($wallet['adjustment']??0)-(float)($wallet['paid']??0);

    ob_start();?>
    <div class="dashboard-head">
      <div>
        <div class="eyebrow">Verkäuferinnenakte #<?=e($s['id'])?></div>
        <h1><?=e($s['first_name'].' '.$s['last_name'])?></h1>
        <p class="meta"><?=e($anon?'Anonymisiert':($s['deleted_at']?'Deaktiviert':'Aktiv'))?> · registriert <?=e(date('d.m.Y H:i',strtotime($s['created_at'])))?></p>
      </div>
      <a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen'))?>">Zur Übersicht</a>
    </div>

    <div class="grid">
      <div class="card"><div class="meta">Aufträge gesamt</div><div class="stat"><?=count($orders)?></div></div>
      <div class="card"><div class="meta">Aktive Aufträge</div><div class="stat"><?=$activeOrders?></div></div>
      <div class="card"><div class="meta">Verfügbarer Wallet-Saldo</div><div class="stat"><?=money($available)?></div></div>
      <div class="card"><div class="meta">Offene Auszahlungen</div><div class="stat"><?=$openPayouts?></div></div>
    </div>

    <?php if(!$anon):?>
    <form class="panel" method="post" action="<?=e(url('/admin/verkaeuferin/'.$s['id']))?>">
      <?=csrf_field()?>
      <h2>Stammdaten bearbeiten</h2>
      <div class="form-grid">
        <label>Vorname<input name="first_name" value="<?=e($s['first_name'])?>" required></label>
        <label>Nachname<input name="last_name" value="<?=e($s['last_name'])?>" required></label>
        <label>Geburtsdatum<input type="date" name="birth_date" value="<?=e($s['birth_date'])?>" required></label>
        <label>E-Mail<input type="email" name="email" value="<?=e($s['email'])?>" required></label>
        <label>Telefon<input name="phone" value="<?=e($s['phone'])?>" required></label>
        <label>Straße<input name="street" value="<?=e($s['street'])?>" required></label>
        <label>PLZ<input name="postal_code" value="<?=e($s['postal_code'])?>" required></label>
        <label>Ort<input name="city" value="<?=e($s['city'])?>" required></label>
      </div>
      <h3>Auszahlungsprofil</h3>
      <div class="form-grid">
        <label>Kontoinhaber<input name="account_holder" value="<?=e($pay['account_holder']??'')?>"></label>
        <label>IBAN<input name="iban" value="<?=e($pay['iban']??'')?>"></label>
        <label>BIC<input name="bic" value="<?=e($pay['bic']??'')?>"></label>
        <label>PayPal<input name="paypal" value="<?=e($pay['paypal']??'')?>"></label>
        <label>Bevorzugte Methode
          <select name="preferred_method">
            <option value="">Keine Vorgabe</option>
            <option value="bank" <?=($pay['preferred_method']??'')==='bank'?'selected':''?>>Bank</option>
            <option value="paypal" <?=($pay['preferred_method']??'')==='paypal'?'selected':''?>>PayPal</option>
          </select>
        </label>
      </div>
      <button class="btn">Akte speichern</button>
    </form>
    <?php else:?>
      <div class="panel"><strong>Dieses Konto wurde anonymisiert.</strong><p class="meta">Personenbezogene Stammdaten und Auszahlungssnapshots wurden entfernt. Historische Auftrags- und Abrechnungsdaten bleiben unter der internen Konto-ID erhalten.</p></div>
    <?php endif;?>

    <div class="grid two">
      <section class="panel">
        <h2>Kontolebenszyklus</h2>
        <p>E-Mail bestätigt: <strong><?=$s['email_verified_at']?'Ja':'Nein'?></strong><br>Deaktiviert: <strong><?=$s['deleted_at']?e(date('d.m.Y H:i',strtotime($s['deleted_at']))):'Nein'?></strong></p>

        <?php if(!$anon && !$s['deleted_at']):?>
        <form method="post" action="<?=e(url('/admin/verkaeuferin/'.$s['id'].'/deaktivieren'))?>">
          <?=csrf_field()?><label>DEAKTIVIEREN eingeben<input name="confirm" required></label>
          <button class="btn secondary" <?=$activeOrders||$openPayouts?'disabled':''?>>Konto deaktivieren</button>
        </form>
        <?php endif;?>

        <?php if(!$anon && $s['deleted_at']):?>
        <form method="post" action="<?=e(url('/admin/verkaeuferin/'.$s['id'].'/reaktivieren'))?>">
          <?=csrf_field()?><button class="btn">Konto reaktivieren</button>
        </form>
        <?php endif;?>

        <?php if(!$anon):?>
        <hr>
        <form method="post" action="<?=e(url('/admin/verkaeuferin/'.$s['id'].'/anonymisieren'))?>">
          <?=csrf_field()?>
          <p class="meta">Nur ohne aktive Aufträge und offene Auszahlungen. Entfernt Kontaktdaten, Login, Auszahlungsprofil und gespeicherte Auszahlungssnapshots.</p>
          <label>ANONYMISIEREN eingeben<input name="confirm" required></label>
          <button class="btn secondary" <?=$activeOrders||$openPayouts?'disabled':''?>>Konto anonymisieren</button>
        </form>
        <?php endif;?>

        <hr>
        <form method="post" action="<?=e(url('/admin/verkaeuferin/'.$s['id'].'/loeschen'))?>">
          <?=csrf_field()?>
          <p class="meta">Physische Löschung ist nur ohne referenzierte Auftrags-, Nachweis-, Wallet-, Auszahlungs- oder Rechtehistorie möglich.</p>
          <label>LOESCHEN eingeben<input name="confirm" required></label>
          <button class="btn secondary">Konto physisch löschen</button>
        </form>
      </section>

      <section class="panel">
        <h2>Rechtliche Bestätigungen</h2>
        <?php foreach($legal as $lr):?>
          <p><strong><?=e($lr['context'])?></strong> · <?=e($lr['rules_version'])?><br><span class="meta"><?=e(date('d.m.Y H:i',strtotime($lr['created_at'])))?></span></p>
        <?php endforeach;?>
        <?php if(!$legal):?><p class="meta">Keine Bestätigungen gespeichert.</p><?php endif;?>
      </section>
    </div>

    <h2>Aufträge</h2>
    <div class="table-wrap"><table><thead><tr><th>Nr.</th><th>Angebot</th><th>Status</th><th>Wert</th><th>Erstellt</th></tr></thead><tbody>
      <?php foreach($orders as $o):?><tr><td><a href="<?=e(url('/admin/auftrag/'.$o['order_no']))?>"><?=e($o['order_no'])?></a></td><td><?=e($o['title'])?></td><td><?=e($o['status'])?></td><td><?=money($o['total_compensation'])?></td><td><?=e(date('d.m.Y H:i',strtotime($o['created_at'])))?></td></tr><?php endforeach;?>
      <?php if(!$orders):?><tr><td colspan="5">Keine Aufträge.</td></tr><?php endif;?>
    </tbody></table></div>

    <h2>Individuelle Angebote</h2>
    <div class="table-wrap"><table><thead><tr><th>Angebot</th><th>Status</th><th>Annahmefrist</th><th>Erstellt</th></tr></thead><tbody>
      <?php foreach($assignments as $a):?><tr><td><?=e($a['title'])?></td><td><?=e($a['status'])?></td><td><?=e(date('d.m.Y H:i',strtotime($a['acceptance_deadline'])))?></td><td><?=e(date('d.m.Y H:i',strtotime($a['created_at'])))?></td></tr><?php endforeach;?>
      <?php if(!$assignments):?><tr><td colspan="4">Keine individuellen Angebote.</td></tr><?php endif;?>
    </tbody></table></div>

    <h2>Auszahlungen</h2>
    <div class="table-wrap"><table><thead><tr><th>Datum</th><th>Brutto</th><th>Gebühr</th><th>Netto</th><th>Methode</th><th>Status</th></tr></thead><tbody>
      <?php foreach($payouts as $p):?><tr><td><?=e(date('d.m.Y H:i',strtotime($p['created_at'])))?></td><td><?=money($p['amount'])?></td><td><?=money($p['fee'])?></td><td><?=money($p['net_amount'])?></td><td><?=e($p['method'])?></td><td><?=e($p['status'])?></td></tr><?php endforeach;?>
      <?php if(!$payouts):?><tr><td colspan="6">Keine Auszahlungen.</td></tr><?php endif;?>
    </tbody></table></div>

    <h2>Letzte Systemereignisse</h2>
    <div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Ereignis</th><th>Auftrag-ID</th></tr></thead><tbody>
      <?php foreach($events as $ev):?><tr><td><?=e(date('d.m.Y H:i',strtotime($ev['created_at'])))?></td><td><?=e($ev['event_type'])?></td><td><?=e($ev['order_id']??'–')?></td></tr><?php endforeach;?>
      <?php if(!$events):?><tr><td colspan="3">Keine Ereignisse.</td></tr><?php endif;?>
    </tbody></table></div>

    <?php render('Verkäuferinnenakte',ob_get_clean());exit;
}

if (preg_match('#^/admin/verkaeuferin/(\d+)$#',$path,$m) && $method==='POST') {
    require_admin();
    $id=(int)$m[1];

    $q=db()->prepare("SELECT * FROM sellers WHERE id=?");
    $q->execute([$id]);
    $s=$q->fetch();
    if(!$s) not_found();

    if(str_ends_with((string)$s['email'],'@invalid.local')){
        flash('error','Ein anonymisiertes Konto kann nicht wieder mit personenbezogenen Daten befüllt werden.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    $email=strtolower(post('email'));
    $birth=post('birth_date');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)){
        flash('error','Ungültige E-Mail-Adresse.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    try{$age=$birth?date_diff(new DateTime($birth),new DateTime('today'))->y:0;}catch(Throwable){$age=0;}
    if($age<18){
        flash('error','Das Geburtsdatum muss eine volljährige Person ergeben.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    $dup=db()->prepare("SELECT COUNT(*) FROM sellers WHERE email=? AND id<>?");
    $dup->execute([$email,$id]);
    if((int)$dup->fetchColumn()>0){
        flash('error','Diese E-Mail-Adresse wird bereits verwendet.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    $changedEmail=!hash_equals(strtolower((string)$s['email']),$email);
    $preferred=in_array(post('preferred_method'),['bank','paypal'],true)?post('preferred_method'):null;

    db()->beginTransaction();
    try{
        db()->prepare("UPDATE sellers SET first_name=?,last_name=?,birth_date=?,street=?,postal_code=?,city=?,phone=?,email=?,email_verified_at=".($changedEmail?'NULL':'email_verified_at').",updated_at=NOW() WHERE id=?")
          ->execute([post('first_name'),post('last_name'),$birth,post('street'),post('postal_code'),post('city'),post('phone'),$email,$id]);

        db()->prepare("INSERT INTO payout_profiles(seller_id,iban,bic,account_holder,paypal,preferred_method) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE iban=VALUES(iban),bic=VALUES(bic),account_holder=VALUES(account_holder),paypal=VALUES(paypal),preferred_method=VALUES(preferred_method)")
          ->execute([$id,post('iban'),post('bic'),post('account_holder'),post('paypal'),$preferred]);

        if($changedEmail){
            db()->prepare("DELETE FROM email_verifications WHERE seller_id=?")->execute([$id]);
            [$raw,$hash]=make_token();
            db()->prepare("INSERT INTO email_verifications(seller_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))")->execute([$id,$hash]);
        }

        log_event('seller.admin_updated',$id,null,['email_changed'=>$changedEmail]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    if($changedEmail){
        send_app_mail($email,'Neue E-Mail-Adresse bestätigen','<p>Deine E-Mail-Adresse wurde administrativ geändert. Bitte bestätige sie:</p><p><a href="'.e(url('/email-bestaetigen?token='.$raw)).'">E-Mail bestätigen</a></p>');
    }

    flash('success','Verkäuferinnenakte gespeichert.'.($changedEmail?' Die neue E-Mail muss bestätigt werden.':''));
    redirect('/admin/verkaeuferin/'.$id);
}

if (preg_match('#^/admin/verkaeuferin/(\d+)/deaktivieren$#',$path,$m) && $method==='POST') {
    require_admin();
    $id=(int)$m[1];

    if(post('confirm')!=='DEAKTIVIEREN'){
        flash('error','Bestätigung stimmt nicht.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    $q=db()->prepare("SELECT id FROM sellers WHERE id=?");
    $q->execute([$id]);
    if(!$q->fetch()) not_found();

    $a=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status NOT IN('completed','rejected','archived')");
    $a->execute([$id]);
    $p=db()->prepare("SELECT COUNT(*) FROM payout_requests WHERE seller_id=? AND status IN('requested','review','released')");
    $p->execute([$id]);

    if((int)$a->fetchColumn()>0 || (int)$p->fetchColumn()>0){
        flash('error','Das Konto kann erst deaktiviert werden, wenn keine aktiven Aufträge und offenen Auszahlungen mehr bestehen.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    db()->prepare("UPDATE sellers SET deleted_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);
    db()->prepare("DELETE FROM email_verifications WHERE seller_id=?")->execute([$id]);
    db()->prepare("DELETE FROM password_resets WHERE seller_id=?")->execute([$id]);
    log_event('seller.deactivated',$id,null,[]);

    flash('success','Konto deaktiviert. Eine Anmeldung ist nicht mehr möglich.');
    redirect('/admin/verkaeuferin/'.$id);
}

if (preg_match('#^/admin/verkaeuferin/(\d+)/reaktivieren$#',$path,$m) && $method==='POST') {
    require_admin();
    $id=(int)$m[1];

    $q=db()->prepare("SELECT * FROM sellers WHERE id=?");
    $q->execute([$id]);
    $s=$q->fetch();
    if(!$s) not_found();

    if(str_ends_with((string)$s['email'],'@invalid.local')){
        flash('error','Ein anonymisiertes Konto kann nicht reaktiviert werden.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    db()->prepare("UPDATE sellers SET deleted_at=NULL,updated_at=NOW() WHERE id=?")->execute([$id]);
    log_event('seller.reactivated',$id,null,[]);
    flash('success','Konto reaktiviert.');
    redirect('/admin/verkaeuferin/'.$id);
}

if (preg_match('#^/admin/verkaeuferin/(\d+)/anonymisieren$#',$path,$m) && $method==='POST') {
    require_admin();
    $id=(int)$m[1];

    if(post('confirm')!=='ANONYMISIEREN'){
        flash('error','Bestätigung stimmt nicht.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    $q=db()->prepare("SELECT id FROM sellers WHERE id=?");
    $q->execute([$id]);
    if(!$q->fetch()) not_found();

    $a=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=? AND status NOT IN('completed','rejected','archived')");
    $a->execute([$id]);
    $p=db()->prepare("SELECT COUNT(*) FROM payout_requests WHERE seller_id=? AND status IN('requested','review','released')");
    $p->execute([$id]);

    if((int)$a->fetchColumn()>0 || (int)$p->fetchColumn()>0){
        flash('error','Anonymisierung ist erst ohne aktive Aufträge und offene Auszahlungen möglich.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    $anonEmail='deleted+'.$id.'+'.bin2hex(random_bytes(8)).'@invalid.local';

    db()->beginTransaction();
    try{
        db()->prepare("UPDATE payout_requests SET payment_snapshot_json=? WHERE seller_id=?")->execute(['{}',$id]);
        db()->prepare("DELETE FROM payout_profiles WHERE seller_id=?")->execute([$id]);
        db()->prepare("DELETE FROM email_verifications WHERE seller_id=?")->execute([$id]);
        db()->prepare("DELETE FROM password_resets WHERE seller_id=?")->execute([$id]);
        db()->prepare("DELETE FROM notifications WHERE seller_id=?")->execute([$id]);
        db()->prepare("UPDATE system_events SET seller_id=NULL WHERE seller_id=?")->execute([$id]);

        db()->prepare("UPDATE sellers SET first_name='Gelöscht',last_name=?,birth_date='1900-01-01',street='-',postal_code='00000',city='-',phone='-',email=?,password_hash=?,email_verified_at=NULL,deleted_at=COALESCE(deleted_at,NOW()),updated_at=NOW() WHERE id=?")
          ->execute(['Konto #'.$id,$anonEmail,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$id]);

        log_event('seller.anonymized',null,null,['seller_id'=>$id]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    flash('success','Konto anonymisiert. Personenbezogene Stammdaten und Auszahlungssnapshots wurden entfernt.');
    redirect('/admin/verkaeuferin/'.$id);
}

if (preg_match('#^/admin/verkaeuferin/(\d+)/loeschen$#',$path,$m) && $method==='POST') {
    require_admin();
    $id=(int)$m[1];

    if(post('confirm')!=='LOESCHEN'){
        flash('error','Bestätigung stimmt nicht.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    $q=db()->prepare("SELECT id FROM sellers WHERE id=?");
    $q->execute([$id]);
    if(!$q->fetch()) not_found();

    $restricted=[
        'orders',
        'order_acceptance_confirmations',
        'evidences',
        'order_item_proposals',
        'evidence_retake_requests',
        'wallet_entries',
        'payout_requests',
        'rights_acceptances',
    ];
    $refs=0;
    foreach($restricted as $table){
        $st=db()->prepare("SELECT COUNT(*) FROM ".$table." WHERE seller_id=?");
        $st->execute([$id]);
        $refs+=(int)$st->fetchColumn();
    }

    if($refs>0){
        flash('error','Physische Löschung ist wegen vorhandener Historie nicht möglich. Nutze stattdessen die Anonymisierung, sobald alle laufenden Vorgänge abgeschlossen sind.');
        redirect('/admin/verkaeuferin/'.$id);
    }

    db()->beginTransaction();
    try{
        db()->prepare("DELETE FROM notifications WHERE seller_id=?")->execute([$id]);
        db()->prepare("DELETE FROM system_events WHERE seller_id=?")->execute([$id]);
        db()->prepare("DELETE FROM sellers WHERE id=?")->execute([$id]);
        log_event('seller.deleted',null,null,['seller_id'=>$id]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    flash('success','Konto physisch gelöscht.');
    redirect('/admin/verkaeuferinnen');
}

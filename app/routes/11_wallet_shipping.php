<?php
declare(strict_types=1);

// ADMIN SETTINGS: SHIPPING ADDRESS
if ($path==='/admin/settings' && $method==='GET') {
    require_admin();
    $a=shipping_address();
    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Einstellungen</span><h1>Versandadresse</h1><p>Diese Adresse wird Verkäuferinnen erst in der Versandphase ihres Auftrags angezeigt.</p></div></div>
    <section class="panel narrow">
        <form method="post">
            <label>Kennwort<input name="keyword" value="<?=e($a['keyword'])?>" required></label>
            <label>Empfänger / Name<input name="name" value="<?=e($a['name'])?>" required></label>
            <label>Straße + Hausnummer<input name="street" value="<?=e($a['street'])?>" required></label>
            <div class="form-grid">
                <label>PLZ<input name="postal_code" value="<?=e($a['postal_code'])?>" required></label>
                <label>Ort<input name="city" value="<?=e($a['city'])?>" required></label>
            </div>
            <label>Land<input name="country" value="<?=e($a['country']?:'Deutschland')?>" required></label>
            <label>Post Filiale / Zusatz<textarea name="extra" rows="4"><?=e($a['extra'])?></textarea></label>
            <button class="btn">Versandadresse speichern</button>
        </form>
    </section>
    <?php render('Versandadresse',ob_get_clean());exit;
}
if ($path==='/admin/settings' && $method==='POST') {
    require_admin();
    foreach(['keyword','name','street','postal_code','city','country'] as $k){
        if(post($k)===''){flash('error','Bitte die Versandadresse vollständig ausfüllen.');redirect('/admin/settings');}
    }
    foreach(['keyword','name','street','postal_code','city','country','extra'] as $k)set_app_setting('shipping.'.$k,post($k));
    $a=shipping_address();
    db()->prepare("UPDATE order_shipments SET address_keyword=?,address_name=?,street=?,postal_code=?,city=?,country=?,extra=?,updated_at=NOW()
        WHERE status='pending'")
        ->execute([$a['keyword'],$a['name'],$a['street'],$a['postal_code'],$a['city'],$a['country'],$a['extra']?:null]);
    flash('success','Versandadresse gespeichert. Alle offenen Versandvorgänge wurden auf die aktuelle Adresse gesetzt.');redirect('/admin/settings');
}

// ADMIN WALLETS
if ($path==='/admin/wallets' && $method==='GET') {
    require_admin();
    $rows=db()->query("SELECT s.id,s.first_name,s.last_name,s.email,
        COALESCE(SUM(CASE WHEN w.status='reserved' THEN w.amount ELSE 0 END),0) reserved,
        COALESCE(SUM(CASE WHEN w.status='available' THEN w.amount ELSE 0 END),0) available,
        COALESCE(SUM(CASE WHEN w.status='paid' THEN w.amount ELSE 0 END),0) paid,
        p.payout_method
        FROM sellers s
        LEFT JOIN seller_wallet_entries w ON w.seller_id=s.id
        LEFT JOIN seller_payout_profiles p ON p.seller_id=s.id
        GROUP BY s.id,p.payout_method
        ORDER BY available DESC,reserved DESC,s.first_name,s.last_name")->fetchAll();
    $totals=db()->query("SELECT
        COALESCE(SUM(CASE WHEN status='reserved' THEN amount ELSE 0 END),0) reserved,
        COALESCE(SUM(CASE WHEN status='available' THEN amount ELSE 0 END),0) available,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) paid
        FROM seller_wallet_entries")->fetch()?:[];
    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Wallets</span><h1>Auszahlungen</h1><p>Vorgemerkte, auszahlbare und bereits ausgezahlte Vergütungen.</p></div></div>
    <div class="stats compact">
        <div class="stat"><span>Vorgemerkt</span><strong><?=money($totals['reserved']??0)?></strong></div>
        <div class="stat"><span>Auszahlbar</span><strong><?=money($totals['available']??0)?></strong></div>
        <div class="stat"><span>Ausgezahlt</span><strong><?=money($totals['paid']??0)?></strong></div>
    </div>
    <div class="list">
    <?php foreach($rows as $r):?>
        <a class="list-row" href="<?=e(url('/admin/wallets/'.$r['id']))?>">
            <div><strong><?=e($r['first_name'].' '.$r['last_name'])?></strong><span><?=e($r['email'])?> · <?=e(payout_method_label($r['payout_method']))?></span></div>
            <div class="wallet-inline"><span>Vorgemerkt <?=money($r['reserved'])?></span><strong>Auszahlbar <?=money($r['available'])?></strong></div>
        </a>
    <?php endforeach;?>
    <?php if(!$rows):?><div class="empty">Keine Verkäuferinnen vorhanden.</div><?php endif;?>
    </div>
    <?php render('Wallets',ob_get_clean());exit;
}

if (preg_match('#^/admin/wallets/(\d+)$#',$path,$m) && $method==='GET') {
    require_admin();$sellerId=(int)$m[1];
    $q=db()->prepare('SELECT * FROM sellers WHERE id=?');$q->execute([$sellerId]);$seller=$q->fetch();if(!$seller)not_found();
    $summary=wallet_summary($sellerId);$profile=payout_profile($sellerId);
    $q=db()->prepare("SELECT w.*,o.order_no,o.title_snapshot,pbe.payout_batch_id
        FROM seller_wallet_entries w
        JOIN orders o ON o.id=w.order_id
        LEFT JOIN payout_batch_entries pbe ON pbe.wallet_entry_id=w.id
        WHERE w.seller_id=? ORDER BY FIELD(w.status,'available','reserved','paid','cancelled'),w.created_at DESC");
    $q->execute([$sellerId]);$entries=$q->fetchAll();
    $q=db()->prepare("SELECT * FROM payout_batches WHERE seller_id=? ORDER BY paid_at DESC,id DESC");
    $q->execute([$sellerId]);$batches=$q->fetchAll();

    $profileReady=$profile['payout_method']==='paypal'
        ? filter_var((string)$profile['paypal_email'],FILTER_VALIDATE_EMAIL)
        : ($profile['payout_method']==='bank' && trim((string)$profile['bank_holder'])!=='' && trim((string)$profile['bank_iban'])!=='');
    $available=array_values(array_filter($entries,static fn($w)=>$w['status']==='available'));

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Wallet</span><h1><?=e($seller['first_name'].' '.$seller['last_name'])?></h1><p><?=e($seller['email'])?></p></div>
        <div class="head-actions"><a class="btn ghost" href="<?=e(url('/admin/seller/'.$sellerId))?>">Akte</a><a class="btn ghost" href="<?=e(url('/admin/wallets'))?>">Alle Wallets</a></div>
    </div>
    <div class="stats compact">
        <div class="stat"><span>Vorgemerkt</span><strong><?=money($summary['reserved'])?></strong></div>
        <div class="stat"><span>Auszahlbar</span><strong><?=money($summary['available'])?></strong></div>
        <div class="stat"><span>Ausgezahlt</span><strong><?=money($summary['paid'])?></strong></div>
    </div>

    <section class="panel">
        <div class="section-head"><h2>Auszahlungsweg</h2><span class="badge">verschlüsselt gespeichert</span></div>
        <?php if($profile['payout_method']==='paypal'):?>
            <p><strong>PayPal</strong><br><?=e(mask_email($profile['paypal_email']))?></p>
            <details class="sensitive-details"><summary>Vollständige Adresse anzeigen</summary><code><?=e($profile['paypal_email'])?></code></details>
        <?php elseif($profile['payout_method']==='bank'):?>
            <p><strong>Banküberweisung</strong><br><?=e($profile['bank_holder'])?><br>IBAN <?=e(mask_iban($profile['bank_iban']))?><?php if($profile['bank_bic']):?><br>BIC <?=e(substr((string)$profile['bank_bic'],0,3).'••••')?></p><?php else:?></p><?php endif;?>
            <details class="sensitive-details"><summary>Vollständige Bankdaten anzeigen</summary><p><?=e($profile['bank_holder'])?><br>IBAN <?=e($profile['bank_iban'])?><?php if($profile['bank_bic']):?><br>BIC <?=e($profile['bank_bic'])?><?php endif;?></p></details>
        <?php else:?><div class="notice warning">Die Verkäuferin hat noch keine Auszahlungsdaten hinterlegt.</div><?php endif;?>
    </section>

    <?php if($available):?>
    <section class="panel payout-panel">
        <div class="section-head"><h2>Neue Auszahlung dokumentieren</h2><span class="muted">Nur ausgewählte Buchungen werden bezahlt.</span></div>
        <form method="post" action="<?=e(url('/admin/wallets/'.$sellerId.'/pay'))?>">
            <label class="check select-all-row"><input type="checkbox" data-select-all=".payout-entry-checkbox"><span>Alle auszahlbaren Buchungen auswählen</span></label>
            <div class="payout-entry-list">
            <?php foreach($available as $w):?>
                <label class="payout-entry">
                    <input class="payout-entry-checkbox" type="checkbox" name="entry_ids[]" value="<?=e($w['id'])?>">
                    <span><strong><?=e($w['order_no'].' · '.$w['title_snapshot'])?></strong><small>Freigegeben <?=e($w['available_at']?date('d.m.Y H:i',strtotime($w['available_at'])).' Uhr':'–')?></small></span>
                    <b><?=money($w['amount'])?></b>
                </label>
            <?php endforeach;?>
            </div>
            <div class="form-grid">
                <label>Auszahlungsdatum<input type="date" name="payout_date" max="<?=e(date('Y-m-d'))?>" value="<?=e(date('Y-m-d'))?>" required></label>
                <label>Referenz / Transaktions-ID <span class="muted">(optional)</span><input name="reference" maxlength="190"></label>
            </div>
            <label>Interne Notiz <span class="muted">(optional)</span><textarea name="note" rows="3" maxlength="1000"></textarea></label>
            <button class="btn" <?=!$profileReady?'disabled':''?>>Ausgewählte Buchungen als ausgezahlt markieren</button>
            <?php if(!$profileReady):?><p class="field-hint">Die Auszahlung bleibt gesperrt, bis gültige Auszahlungsdaten hinterlegt sind.</p><?php endif;?>
        </form>
    </section>
    <?php endif;?>

    <section class="panel">
        <div class="section-head"><h2>Auszahlungshistorie</h2><span class="muted"><?=count($batches)?> Vorgang/Vorgänge</span></div>
        <div class="list"><?php foreach($batches as $p):?><div class="list-row static"><div><strong><?=e(payout_batch_number((int)$p['id'],$p['paid_at']))?></strong><span><?=e(date('d.m.Y H:i',strtotime($p['paid_at'])))?> Uhr · <?=e(payout_method_label($p['payout_method']))?><?php if($p['reference']):?> · <?=e($p['reference'])?><?php endif;?></span></div><div class="payout-history-actions"><strong><?=money($p['amount'])?></strong><a class="btn ghost" href="<?=e(url('/admin/payout/'.$p['id'].'/receipt.pdf'))?>">PDF</a></div></div><?php endforeach;?><?php if(!$batches):?><div class="empty">Noch keine dokumentierten Auszahlungen.</div><?php endif;?></div>
    </section>

    <section class="panel"><h2>Buchungen</h2><div class="list">
    <?php foreach($entries as $w):?><a class="list-row" href="<?=e(url('/admin/order/'.$w['order_id']))?>"><div><strong><?=e($w['order_no'].' · '.$w['title_snapshot'])?></strong><span><?=e(wallet_status_label($w['status']))?><?php if($w['paid_at']):?> · <?=e(date('d.m.Y H:i',strtotime($w['paid_at'])))?><?php endif;?><?php if($w['payout_batch_id']):?> · <?=e(payout_batch_number((int)$w['payout_batch_id'],$w['paid_at']))?><?php endif;?></span></div><strong><?=money($w['amount'])?></strong></a><?php endforeach;?>
    </div></section>
    <?php render('Wallet '.$seller['first_name'],ob_get_clean());exit;
}

if (preg_match('#^/admin/wallets/(\d+)/pay$#',$path,$m) && $method==='POST') {
    $a=require_admin();$sellerId=(int)$m[1];$profile=payout_profile($sellerId);
    if(!in_array($profile['payout_method'],['paypal','bank'],true)){flash('error','Es sind keine gültigen Auszahlungsdaten hinterlegt.');redirect('/admin/wallets/'.$sellerId);}
    if($profile['payout_method']==='paypal'&&!filter_var((string)$profile['paypal_email'],FILTER_VALIDATE_EMAIL)){flash('error','Die PayPal-Adresse ist ungültig.');redirect('/admin/wallets/'.$sellerId);}
    if($profile['payout_method']==='bank'&&(!$profile['bank_holder']||!$profile['bank_iban'])){flash('error','Die Bankdaten sind unvollständig.');redirect('/admin/wallets/'.$sellerId);}

    $raw=$_POST['entry_ids']??[];$ids=[];
    if(is_array($raw))foreach($raw as $x){$n=(int)$x;if($n>0)$ids[$n]=$n;}
    $ids=array_values($ids);
    if(!$ids){flash('error','Bitte mindestens eine auszahlbare Buchung auswählen.');redirect('/admin/wallets/'.$sellerId);}
    $date=post('payout_date');$paidDate=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$paidDate||$paidDate->format('Y-m-d')!==$date||$paidDate>new DateTimeImmutable('today')){flash('error','Bitte ein gültiges Auszahlungsdatum bis heute wählen.');redirect('/admin/wallets/'.$sellerId);}
    $paidAt=$date.' '.date('H:i:s');$reference=post('reference')?:null;$note=post('note')?:null;
    $marks=implode(',',array_fill(0,count($ids),'?'));

    db()->beginTransaction();
    try{
        $params=array_merge([$sellerId],$ids);
        $q=db()->prepare("SELECT * FROM seller_wallet_entries WHERE seller_id=? AND status='available' AND id IN ($marks) FOR UPDATE");
        $q->execute($params);$entries=$q->fetchAll();
        if(count($entries)!==count($ids))throw new RuntimeException('Mindestens eine ausgewählte Buchung ist nicht mehr auszahlbar.');
        $amount=array_sum(array_map(static fn($w)=>(float)$w['amount'],$entries));
        db()->prepare("INSERT INTO payout_batches(seller_id,admin_id,payout_method,amount,reference,note,paid_at) VALUES(?,?,?,?,?,?,?)")
            ->execute([$sellerId,$a['id'],$profile['payout_method'],$amount,$reference,$note,$paidAt]);
        $batchId=(int)db()->lastInsertId();
        $link=db()->prepare("INSERT INTO payout_batch_entries(payout_batch_id,wallet_entry_id,amount) VALUES(?,?,?)");
        $upd=db()->prepare("UPDATE seller_wallet_entries SET status='paid',payout_method=?,payout_reference=?,paid_at=?,updated_at=NOW() WHERE id=?");
        foreach($entries as $w){
            $link->execute([$batchId,$w['id'],$w['amount']]);
            $upd->execute([$profile['payout_method'],$reference,$paidAt,$w['id']]);
        }
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());redirect('/admin/wallets/'.$sellerId);
    }
    foreach($entries as $w)log_event(null,(int)$w['order_id'],'wallet.paid',['batch_id'=>$batchId,'method'=>$profile['payout_method'],'reference'=>$reference]);
    notify_seller($sellerId,'payout','Auszahlung dokumentiert',payout_batch_number($batchId,$paidAt).' · '.money($amount).' wurden als ausgezahlt bestätigt.','/seller/wallet','payout:'.$batchId);
    flash('success',money($amount).' wurden als Auszahlung '.payout_batch_number($batchId,$paidAt).' dokumentiert.');
    redirect('/admin/wallets/'.$sellerId);
}

if (preg_match('#^/admin/payout/(\d+)/receipt\.pdf$#',$path,$m) && $method==='GET') {
    require_admin();$batchId=(int)$m[1];
    $q=db()->prepare("SELECT p.*,s.first_name,s.last_name FROM payout_batches p JOIN sellers s ON s.id=p.seller_id WHERE p.id=?");$q->execute([$batchId]);$p=$q->fetch();if(!$p)not_found();
    $q=db()->prepare("SELECT e.amount,o.order_no,o.title_snapshot FROM payout_batch_entries e JOIN seller_wallet_entries w ON w.id=e.wallet_entry_id JOIN orders o ON o.id=w.order_id WHERE e.payout_batch_id=? ORDER BY e.wallet_entry_id");
    $q->execute([$batchId]);$items=$q->fetchAll();
    $lines=['Verkäuferin: '.$p['first_name'].' '.$p['last_name'],'Auszahlung: '.payout_batch_number($batchId,$p['paid_at']),'Datum: '.date('d.m.Y H:i',strtotime($p['paid_at'])).' Uhr','Methode: '.payout_method_label($p['payout_method']),'Gesamtbetrag: '.money($p['amount'])];
    if($p['reference'])$lines[]='Referenz: '.$p['reference'];if($p['note'])$lines[]='Notiz: '.$p['note'];$lines[]='';$lines[]='Enthaltene Buchungen:';
    foreach($items as $x)$lines[]=$x['order_no'].' - '.$x['title_snapshot'].' - '.money($x['amount']);
    simple_pdf_download(payout_batch_number($batchId,$p['paid_at']).'.pdf','Auszahlungsbeleg',$lines);
}

// SELLER WALLET
if ($path==='/seller/wallet' && $method==='GET') {
    $s=require_seller();$summary=wallet_summary((int)$s['id']);$profile=payout_profile((int)$s['id']);
    $q=db()->prepare("SELECT w.*,o.order_no,o.title_snapshot,pbe.payout_batch_id FROM seller_wallet_entries w JOIN orders o ON o.id=w.order_id LEFT JOIN payout_batch_entries pbe ON pbe.wallet_entry_id=w.id WHERE w.seller_id=? ORDER BY w.created_at DESC");
    $q->execute([$s['id']]);$entries=$q->fetchAll();
    $q=db()->prepare("SELECT * FROM payout_batches WHERE seller_id=? ORDER BY paid_at DESC,id DESC");$q->execute([$s['id']]);$batches=$q->fetchAll();
    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Wallet</span><h1>Meine Vergütung</h1><p>Vorgemerkt bis zum vollständigen Abschluss inklusive Versand.</p></div></div>
    <div class="stats compact">
        <div class="stat"><span>Vorgemerkt</span><strong><?=money($summary['reserved'])?></strong></div>
        <div class="stat"><span>Auszahlbar</span><strong><?=money($summary['available'])?></strong></div>
        <div class="stat"><span>Ausgezahlt</span><strong><?=money($summary['paid'])?></strong></div>
    </div>
    <div class="grid two">
        <section class="panel">
            <div class="section-head"><h2>Auszahlungsdaten</h2><span class="badge">verschlüsselt</span></div>
            <form method="post" action="<?=e(url('/seller/wallet/profile'))?>">
                <label>Auszahlung per<select name="payout_method" required><option value="">Bitte wählen</option><option value="paypal" <?=$profile['payout_method']==='paypal'?'selected':''?>>PayPal</option><option value="bank" <?=$profile['payout_method']==='bank'?'selected':''?>>Banküberweisung</option></select></label>
                <label>PayPal-E-Mail<input type="email" name="paypal_email" value="<?=e($profile['paypal_email']??'')?>"></label>
                <label>Kontoinhaber<input name="bank_holder" value="<?=e($profile['bank_holder']??'')?>"></label>
                <label>IBAN<input name="bank_iban" value="<?=e($profile['bank_iban']??'')?>" autocomplete="off"></label>
                <label>BIC (optional)<input name="bank_bic" value="<?=e($profile['bank_bic']??'')?>" autocomplete="off"></label>
                <button class="btn">Auszahlungsdaten verschlüsselt speichern</button>
                <p class="field-hint">Die Zahlungsdaten werden verschlüsselt gespeichert und nicht im Klartext in der Datenbank abgelegt.</p>
            </form>
        </section>
        <section class="panel"><h2>So funktioniert die Wallet</h2><div class="wallet-help"><div><strong>1. Vorgemerkt</strong><span>Bei Annahme eines Angebots wird die Vergütung reserviert.</span></div><div><strong>2. Auszahlbar</strong><span>Nach Durchführung und bestätigtem Versand wird sie freigegeben.</span></div><div><strong>3. Ausgezahlt</strong><span>Die Plattform dokumentiert die externe Zahlung positionsweise oder gesammelt.</span></div></div></section>
    </div>

    <section class="panel"><div class="section-head"><h2>Auszahlungshistorie</h2><span class="muted"><?=count($batches)?> Auszahlung(en)</span></div><div class="list">
        <?php foreach($batches as $p):?><div class="list-row static"><div><strong><?=e(payout_batch_number((int)$p['id'],$p['paid_at']))?></strong><span><?=e(date('d.m.Y H:i',strtotime($p['paid_at'])))?> Uhr · <?=e(payout_method_label($p['payout_method']))?><?php if($p['reference']):?> · Referenz <?=e($p['reference'])?><?php endif;?></span></div><div class="payout-history-actions"><strong><?=money($p['amount'])?></strong><a class="btn ghost" href="<?=e(url('/seller/payout/'.$p['id'].'/receipt.pdf'))?>">PDF</a></div></div><?php endforeach;?>
        <?php if(!$batches):?><div class="empty">Noch keine Auszahlung dokumentiert.</div><?php endif;?>
    </div></section>

    <section class="panel"><h2>Buchungen</h2><div class="list">
        <?php foreach($entries as $w):?><a class="list-row" href="<?=e(url('/seller/order/'.$w['order_id']))?>"><div><strong><?=e($w['order_no'].' · '.$w['title_snapshot'])?></strong><span><?=e(wallet_status_label($w['status']))?><?php if($w['payout_batch_id']):?> · <?=e(payout_batch_number((int)$w['payout_batch_id'],$w['paid_at']))?><?php endif;?></span></div><strong><?=money($w['amount'])?></strong></a><?php endforeach;?>
        <?php if(!$entries):?><div class="empty">Noch keine Wallet-Buchungen vorhanden.</div><?php endif;?>
    </div></section>
    <?php render('Wallet',ob_get_clean());exit;
}

if ($path==='/seller/wallet/profile' && $method==='POST') {
    $s=require_seller();$method=post('payout_method');
    if(is_seller_impersonation()){flash('error','Während der Verkäuferinnen-Vorschau können keine Auszahlungsdaten geändert werden.');redirect('/seller/wallet');}
    if(!in_array($method,['paypal','bank'],true)){flash('error','Bitte PayPal oder Banküberweisung auswählen.');redirect('/seller/wallet');}
    $paypal=strtolower(post('paypal_email'));$holder=post('bank_holder');$iban=strtoupper(str_replace(' ','',post('bank_iban')));$bic=strtoupper(str_replace(' ','',post('bank_bic')));
    if($method==='paypal'&&!filter_var($paypal,FILTER_VALIDATE_EMAIL)){flash('error','Bitte eine gültige PayPal-E-Mail-Adresse angeben.');redirect('/seller/wallet');}
    if($method==='bank'&&($holder===''||!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/',$iban))){flash('error','Bitte Kontoinhaber und eine gültige IBAN angeben.');redirect('/seller/wallet');}
    db()->prepare("INSERT INTO seller_payout_profiles(seller_id,payout_method,paypal_email_enc,bank_holder_enc,bank_iban_enc,bank_bic_enc,paypal_email,bank_holder,bank_iban,bank_bic)
        VALUES(?,?,?,?,?,?,NULL,NULL,NULL,NULL)
        ON DUPLICATE KEY UPDATE payout_method=VALUES(payout_method),paypal_email_enc=VALUES(paypal_email_enc),bank_holder_enc=VALUES(bank_holder_enc),
        bank_iban_enc=VALUES(bank_iban_enc),bank_bic_enc=VALUES(bank_bic_enc),paypal_email=NULL,bank_holder=NULL,bank_iban=NULL,bank_bic=NULL,updated_at=NOW()")
        ->execute([$s['id'],$method,secure_encrypt($paypal?:null),secure_encrypt($holder?:null),secure_encrypt($iban?:null),secure_encrypt($bic?:null)]);
    log_event(null,null,'payout_profile.updated',['seller_id'=>(int)$s['id'],'method'=>$method]);
    flash('success','Auszahlungsdaten wurden verschlüsselt gespeichert.');redirect('/seller/wallet');
}

if (preg_match('#^/seller/payout/(\d+)/receipt\.pdf$#',$path,$m) && $method==='GET') {
    $s=require_seller();$batchId=(int)$m[1];
    $q=db()->prepare("SELECT p.*,s.first_name,s.last_name FROM payout_batches p JOIN sellers s ON s.id=p.seller_id WHERE p.id=? AND p.seller_id=?");$q->execute([$batchId,$s['id']]);$p=$q->fetch();if(!$p)not_found();
    $q=db()->prepare("SELECT e.amount,o.order_no,o.title_snapshot FROM payout_batch_entries e JOIN seller_wallet_entries w ON w.id=e.wallet_entry_id JOIN orders o ON o.id=w.order_id WHERE e.payout_batch_id=? ORDER BY e.wallet_entry_id");
    $q->execute([$batchId]);$items=$q->fetchAll();
    $lines=['Verkäuferin: '.$p['first_name'].' '.$p['last_name'],'Auszahlung: '.payout_batch_number($batchId,$p['paid_at']),'Datum: '.date('d.m.Y H:i',strtotime($p['paid_at'])).' Uhr','Methode: '.payout_method_label($p['payout_method']),'Gesamtbetrag: '.money($p['amount'])];
    if($p['reference'])$lines[]='Referenz: '.$p['reference'];$lines[]='';$lines[]='Enthaltene Buchungen:';foreach($items as $x)$lines[]=$x['order_no'].' - '.$x['title_snapshot'].' - '.money($x['amount']);
    simple_pdf_download(payout_batch_number($batchId,$p['paid_at']).'.pdf','Auszahlungsbeleg',$lines);
}

// SELLER SHIPPING CONFIRMATION
if (preg_match('#^/seller/order/(\d+)/confirm-shipment$#',$path,$m) && $method==='POST') {
    $s=require_seller();$orderId=(int)$m[1];
    $q=db()->prepare('SELECT * FROM orders WHERE id=? AND seller_id=?');$q->execute([$orderId,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    $shipment=shipment_for_order($orderId);
    if($o['status']!=='shipping'||!$shipment||$shipment['status']!=='pending'){flash('error','Für dieses Angebot ist keine Versandbestätigung offen.');redirect('/seller/order/'.$orderId);}
    if(!offer_ready_for_shipping((int)$o['offer_id'])){flash('error','Der gemeinsame Versand wird erst freigeschaltet, wenn alle Positionen des Angebots abgeschlossen sind.');redirect('/seller/order/'.$orderId);}
    if(post('confirm_shipped')!=='1'){flash('error','Bitte den gemeinsamen Versand verbindlich bestätigen.');redirect('/seller/order/'.$orderId);}
    $due=offer_shipping_due_date((int)$o['offer_id']);
    if(!$due||new DateTimeImmutable('today')<$due){flash('error','Der Versand kann erst am vorgesehenen gemeinsamen Versandtag bestätigt werden.');redirect('/seller/order/'.$orderId);}
    if(!$shipment['address_keyword']||!$shipment['address_name']||!$shipment['street']||!$shipment['postal_code']||!$shipment['city']){flash('error','Die Versandadresse ist noch nicht vollständig hinterlegt.');redirect('/seller/order/'.$orderId);}

    $reference=post('tracking_number')?:null;$note=post('seller_note')?:null;
    $sumQ=db()->prepare("SELECT COALESCE(SUM(w.amount),0)
        FROM seller_wallet_entries w JOIN orders x ON x.id=w.order_id
        WHERE x.offer_id=? AND w.status='reserved'");
    $sumQ->execute([$o['offer_id']]);$amount=(float)$sumQ->fetchColumn();

    db()->beginTransaction();
    try{
        db()->prepare("UPDATE order_shipments sh
            JOIN orders x ON x.id=sh.order_id
            SET sh.status='confirmed',sh.confirmed_at=NOW(),sh.tracking_number=?,sh.seller_note=?,sh.updated_at=NOW()
            WHERE x.offer_id=? AND sh.status='pending'")
            ->execute([$reference,$note,$o['offer_id']]);
        db()->prepare("UPDATE orders SET status='completed',completed_at=NOW(),updated_at=NOW()
            WHERE offer_id=? AND status='shipping'")->execute([$o['offer_id']]);
        db()->prepare("UPDATE seller_wallet_entries w
            JOIN orders x ON x.id=w.order_id
            SET w.status='available',w.available_at=NOW(),w.updated_at=NOW()
            WHERE x.offer_id=? AND w.status='reserved'")
            ->execute([$o['offer_id']]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());redirect('/seller/order/'.$orderId);
    }

    log_event((int)$o['offer_id'],$orderId,'shipment.confirmed_group',['tracking_number'=>$reference]);
    log_event((int)$o['offer_id'],$orderId,'wallet.available_group',['amount'=>$amount]);
    sync_offer_status((int)$o['offer_id']);
    flash('success','Gemeinsamer Versand bestätigt. Das Angebot ist abgeschlossen und die gesamte vorgemerkte Vergütung ist jetzt auszahlbar.');
    redirect('/seller/order/'.$orderId);
}

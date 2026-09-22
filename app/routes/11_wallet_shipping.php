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
    $q=db()->prepare("SELECT w.*,o.order_no,o.title_snapshot FROM seller_wallet_entries w JOIN orders o ON o.id=w.order_id WHERE w.seller_id=? ORDER BY w.created_at DESC");
    $q->execute([$sellerId]);$entries=$q->fetchAll();
    $profileReady=$profile['payout_method']==='paypal'
        ? filter_var((string)$profile['paypal_email'],FILTER_VALIDATE_EMAIL)
        : ($profile['payout_method']==='bank' && trim((string)$profile['bank_holder'])!=='' && trim((string)$profile['bank_iban'])!=='');
    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Wallet</span><h1><?=e($seller['first_name'].' '.$seller['last_name'])?></h1><p><?=e($seller['email'])?></p></div><a class="btn ghost" href="<?=e(url('/admin/wallets'))?>">Zurück</a></div>
    <div class="stats compact">
        <div class="stat"><span>Vorgemerkt</span><strong><?=money($summary['reserved'])?></strong></div>
        <div class="stat"><span>Auszahlbar</span><strong><?=money($summary['available'])?></strong></div>
        <div class="stat"><span>Ausgezahlt</span><strong><?=money($summary['paid'])?></strong></div>
    </div>
    <section class="panel">
        <h2>Auszahlungsweg</h2>
        <?php if($profile['payout_method']==='paypal'):?>
            <p><strong>PayPal</strong><br><?=e($profile['paypal_email'])?></p>
        <?php elseif($profile['payout_method']==='bank'):?>
            <p><strong>Banküberweisung</strong><br><?=e($profile['bank_holder'])?><br>IBAN <?=e($profile['bank_iban'])?><?php if($profile['bank_bic']):?><br>BIC <?=e($profile['bank_bic'])?><?php endif;?></p>
        <?php else:?><div class="notice warning">Die Verkäuferin hat noch keine Auszahlungsdaten hinterlegt.</div><?php endif;?>

        <?php if((float)$summary['available']>0):?>
            <form method="post" action="<?=e(url('/admin/wallets/'.$sellerId.'/pay'))?>">
                <label>Auszahlungsreferenz / Transaktions-ID (optional)<input name="reference" maxlength="190" placeholder="z. B. PayPal-ID oder Überweisungsreferenz"></label>
                <button class="btn" <?=!$profileReady?'disabled':''?>><?=money($summary['available'])?> als ausgezahlt markieren</button>
                <p class="muted">Die tatsächliche Bank-/PayPal-Zahlung erfolgt außerhalb des Portals. Hier wird sie anschließend dokumentiert.</p>
            </form>
        <?php endif;?>
    </section>
    <section class="panel"><h2>Buchungen</h2><div class="list">
    <?php foreach($entries as $w):?><a class="list-row" href="<?=e(url('/admin/order/'.$w['order_id']))?>"><div><strong><?=e($w['order_no'].' · '.$w['title_snapshot'])?></strong><span><?=e(wallet_status_label($w['status']))?><?php if($w['paid_at']):?> · <?=e(date('d.m.Y H:i',strtotime($w['paid_at'])))?><?php endif;?></span></div><strong><?=money($w['amount'])?></strong></a><?php endforeach;?>
    </div></section>
    <?php render('Wallet '.$seller['first_name'],ob_get_clean());exit;
}

if (preg_match('#^/admin/wallets/(\d+)/pay$#',$path,$m) && $method==='POST') {
    require_admin();$sellerId=(int)$m[1];$profile=payout_profile($sellerId);
    if(!in_array($profile['payout_method'],['paypal','bank'],true)){flash('error','Es sind keine gültigen Auszahlungsdaten hinterlegt.');redirect('/admin/wallets/'.$sellerId);}
    if($profile['payout_method']==='paypal'&&!filter_var((string)$profile['paypal_email'],FILTER_VALIDATE_EMAIL)){flash('error','Die PayPal-Adresse ist ungültig.');redirect('/admin/wallets/'.$sellerId);}
    if($profile['payout_method']==='bank'&&(!$profile['bank_holder']||!$profile['bank_iban'])){flash('error','Die Bankdaten sind unvollständig.');redirect('/admin/wallets/'.$sellerId);}
    $q=db()->prepare("SELECT * FROM seller_wallet_entries WHERE seller_id=? AND status='available' FOR UPDATE");
    db()->beginTransaction();
    try{
        $q->execute([$sellerId]);$entries=$q->fetchAll();
        if(!$entries)throw new RuntimeException('Es gibt aktuell nichts auszuzahlen.');
        $reference=post('reference')?:null;
        $u=db()->prepare("UPDATE seller_wallet_entries SET status='paid',payout_method=?,payout_reference=?,paid_at=NOW(),updated_at=NOW() WHERE id=?");
        foreach($entries as $w)$u->execute([$profile['payout_method'],$reference,$w['id']]);
        db()->commit();
        foreach($entries as $w)log_event(null,(int)$w['order_id'],'wallet.paid',['method'=>$profile['payout_method'],'reference'=>$reference]);
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());redirect('/admin/wallets/'.$sellerId);
    }
    flash('success','Alle auszahlbaren Beträge wurden als ausgezahlt markiert.');redirect('/admin/wallets/'.$sellerId);
}

// SELLER WALLET
if ($path==='/seller/wallet' && $method==='GET') {
    $s=require_seller();$summary=wallet_summary((int)$s['id']);$profile=payout_profile((int)$s['id']);
    $q=db()->prepare("SELECT w.*,o.order_no,o.title_snapshot FROM seller_wallet_entries w JOIN orders o ON o.id=w.order_id WHERE w.seller_id=? ORDER BY w.created_at DESC");
    $q->execute([$s['id']]);$entries=$q->fetchAll();
    ob_start();?>
    <div class="page-head"><div><span class="eyebrow">Wallet</span><h1>Meine Vergütung</h1><p>Vorgemerkt bis zum vollständigen Abschluss inklusive Versand.</p></div></div>
    <div class="stats compact">
        <div class="stat"><span>Vorgemerkt</span><strong><?=money($summary['reserved'])?></strong></div>
        <div class="stat"><span>Auszahlbar</span><strong><?=money($summary['available'])?></strong></div>
        <div class="stat"><span>Ausgezahlt</span><strong><?=money($summary['paid'])?></strong></div>
    </div>
    <div class="grid two">
        <section class="panel">
            <h2>Auszahlungsdaten</h2>
            <form method="post" action="<?=e(url('/seller/wallet/profile'))?>">
                <label>Auszahlung per
                    <select name="payout_method" required>
                        <option value="">Bitte wählen</option>
                        <option value="paypal" <?=$profile['payout_method']==='paypal'?'selected':''?>>PayPal</option>
                        <option value="bank" <?=$profile['payout_method']==='bank'?'selected':''?>>Banküberweisung</option>
                    </select>
                </label>
                <label>PayPal-E-Mail<input type="email" name="paypal_email" value="<?=e($profile['paypal_email']??'')?>"></label>
                <label>Kontoinhaber<input name="bank_holder" value="<?=e($profile['bank_holder']??'')?>"></label>
                <label>IBAN<input name="bank_iban" value="<?=e($profile['bank_iban']??'')?>" autocomplete="off"></label>
                <label>BIC (optional)<input name="bank_bic" value="<?=e($profile['bank_bic']??'')?>" autocomplete="off"></label>
                <button class="btn">Auszahlungsdaten speichern</button>
            </form>
        </section>
        <section class="panel">
            <h2>So funktioniert die Wallet</h2>
            <div class="wallet-help">
                <div><strong>1. Vorgemerkt</strong><span>Bei Annahme eines Angebots wird die Vergütung des Auftrags reserviert.</span></div>
                <div><strong>2. Auszahlbar</strong><span>Nach Durchführung und bestätigtem Versand wird sie freigegeben.</span></div>
                <div><strong>3. Ausgezahlt</strong><span>Nach PayPal-/Bankzahlung markiert der Admin den Betrag als ausgezahlt.</span></div>
            </div>
        </section>
    </div>
    <section class="panel"><h2>Buchungen</h2><div class="list">
        <?php foreach($entries as $w):?><a class="list-row" href="<?=e(url('/seller/order/'.$w['order_id']))?>"><div><strong><?=e($w['order_no'].' · '.$w['title_snapshot'])?></strong><span><?=e(wallet_status_label($w['status']))?></span></div><strong><?=money($w['amount'])?></strong></a><?php endforeach;?>
        <?php if(!$entries):?><div class="empty">Noch keine Wallet-Buchungen vorhanden.</div><?php endif;?>
    </div></section>
    <?php render('Wallet',ob_get_clean());exit;
}

if ($path==='/seller/wallet/profile' && $method==='POST') {
    $s=require_seller();$method=post('payout_method');
    if(!in_array($method,['paypal','bank'],true)){flash('error','Bitte PayPal oder Banküberweisung auswählen.');redirect('/seller/wallet');}
    $paypal=strtolower(post('paypal_email'));$holder=post('bank_holder');$iban=strtoupper(str_replace(' ','',post('bank_iban')));$bic=strtoupper(str_replace(' ','',post('bank_bic')));
    if($method==='paypal'&&!filter_var($paypal,FILTER_VALIDATE_EMAIL)){flash('error','Bitte eine gültige PayPal-E-Mail-Adresse angeben.');redirect('/seller/wallet');}
    if($method==='bank'&&($holder===''||!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/',$iban))){flash('error','Bitte Kontoinhaber und eine gültige IBAN angeben.');redirect('/seller/wallet');}
    db()->prepare("INSERT INTO seller_payout_profiles(seller_id,payout_method,paypal_email,bank_holder,bank_iban,bank_bic)
        VALUES(?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE payout_method=VALUES(payout_method),paypal_email=VALUES(paypal_email),bank_holder=VALUES(bank_holder),bank_iban=VALUES(bank_iban),bank_bic=VALUES(bank_bic),updated_at=NOW()")
        ->execute([$s['id'],$method,$paypal?:null,$holder?:null,$iban?:null,$bic?:null]);
    flash('success','Auszahlungsdaten gespeichert.');redirect('/seller/wallet');
}

// SELLER SHIPPING CONFIRMATION
if (preg_match('#^/seller/order/(\d+)/confirm-shipment$#',$path,$m) && $method==='POST') {
    $s=require_seller();$orderId=(int)$m[1];
    $q=db()->prepare('SELECT * FROM orders WHERE id=? AND seller_id=?');$q->execute([$orderId,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    $shipment=shipment_for_order($orderId);
    if($o['status']!=='shipping'||!$shipment||$shipment['status']!=='pending'){flash('error','Für diesen Auftrag ist keine Versandbestätigung offen.');redirect('/seller/order/'.$orderId);}
    if(post('confirm_shipped')!=='1'){flash('error','Bitte den Versand verbindlich bestätigen.');redirect('/seller/order/'.$orderId);}
    if(new DateTimeImmutable('today')<new DateTimeImmutable($shipment['due_date'])){flash('error','Der Versand kann erst am vorgesehenen Versandtag bestätigt werden.');redirect('/seller/order/'.$orderId);}
    if(!$shipment['address_keyword']||!$shipment['address_name']||!$shipment['street']||!$shipment['postal_code']||!$shipment['city']){flash('error','Die Versandadresse ist noch nicht vollständig hinterlegt.');redirect('/seller/order/'.$orderId);}

    db()->beginTransaction();
    try{
        db()->prepare("UPDATE order_shipments SET status='confirmed',confirmed_at=NOW(),tracking_number=?,seller_note=?,updated_at=NOW() WHERE id=?")
            ->execute([post('tracking_number')?:null,post('seller_note')?:null,$shipment['id']]);
        db()->prepare("UPDATE orders SET status='completed',completed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$orderId]);
        db()->prepare("UPDATE seller_wallet_entries SET status='available',available_at=NOW(),updated_at=NOW() WHERE order_id=? AND status='reserved'")->execute([$orderId]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());redirect('/seller/order/'.$orderId);
    }
    log_event((int)$o['offer_id'],$orderId,'shipment.confirmed',['tracking_number'=>post('tracking_number')?:null]);
    log_event((int)$o['offer_id'],$orderId,'wallet.available',['amount'=>(float)$o['compensation']]);
    sync_offer_status((int)$o['offer_id']);
    flash('success','Versand bestätigt. Der Auftrag ist abgeschlossen und die Vergütung ist jetzt auszahlbar.');
    redirect('/seller/order/'.$orderId);
}

<?php
declare(strict_types=1);

if (preg_match('#^/admin/offer/(\d+)/receipt-review$#',$path,$m) && $method==='POST') {
    $a=require_admin();$offerId=(int)$m[1];

    $q=db()->prepare("SELECT o.id,o.offer_no,o.title,o.seller_id,s.first_name,s.last_name
        FROM offers o JOIN sellers s ON s.id=o.seller_id WHERE o.id=?");
    $q->execute([$offerId]);$offer=$q->fetch();if(!$offer)not_found();

    if(!offer_all_shipments_confirmed($offerId)){
        flash('error','Empfang und Bewertung können erst nach vollständig bestätigtem gemeinsamen Versand erfasst werden.');
        redirect('/admin/offers');
    }

    $receivedDate=post('received_date');
    $received=DateTimeImmutable::createFromFormat('!Y-m-d',$receivedDate);
    $today=new DateTimeImmutable('today');
    if(!$received||$received->format('Y-m-d')!==$receivedDate||$received>$today){
        flash('error','Bitte ein gültiges Empfangsdatum bis heute wählen.');
        redirect('/admin/offer/'.$offerId);
    }

    $q=db()->prepare("SELECT MAX(sh.confirmed_at) FROM order_shipments sh
        JOIN orders o ON o.id=sh.order_id WHERE o.offer_id=?");
    $q->execute([$offerId]);$lastShipment=$q->fetchColumn();
    if($lastShipment && $receivedDate<date('Y-m-d',strtotime((string)$lastShipment))){
        flash('error','Das Empfangsdatum kann nicht vor der bestätigten Versandbestätigung liegen.');
        redirect('/admin/offer/'.$offerId);
    }

    $rating=(int)post('rating');
    if($rating<1||$rating>5){
        flash('error','Bitte eine Bewertung von 1 bis 5 Sternen wählen.');
        redirect('/admin/offer/'.$offerId);
    }
    $reviewText=post('review_text')?:null;
    if($reviewText!==null&&mb_strlen($reviewText)>2000){
        flash('error','Der Bewertungstext darf maximal 2000 Zeichen lang sein.');
        redirect('/admin/offer/'.$offerId);
    }

    $existing=offer_receipt_review($offerId);

    db()->beginTransaction();
    try{
        $lock=db()->prepare('SELECT id FROM offers WHERE id=? FOR UPDATE');
        $lock->execute([$offerId]);if(!$lock->fetchColumn())throw new RuntimeException('Angebot wurde nicht gefunden.');

        if(!offer_all_shipments_confirmed($offerId))throw new RuntimeException('Der Versand ist noch nicht vollständig bestätigt.');

        db()->prepare("INSERT INTO offer_receipt_reviews(offer_id,received_at,rating,review_text,recorded_by_admin_id)
            VALUES(?,?,?,?,?)
            ON DUPLICATE KEY UPDATE received_at=VALUES(received_at),rating=VALUES(rating),review_text=VALUES(review_text),
                recorded_by_admin_id=VALUES(recorded_by_admin_id),updated_at=NOW()")
            ->execute([$offerId,$receivedDate.' 12:00:00',$rating,$reviewText,$a['id']]);

        $released=release_offer_wallet_after_review($offerId);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());
        redirect('/admin/offer/'.$offerId);
    }

    log_event($offerId,null,$existing?'offer.receipt_review_updated':'offer.receipt_review_recorded',[
        'received_date'=>$receivedDate,
        'rating'=>$rating,
        'review_text'=>$reviewText,
        'wallet_released'=>$released,
    ]);

    if($released>0){
        notify_seller((int)$offer['seller_id'],'payout','Empfang bestätigt und Bewertung abgeschlossen',
            $offer['offer_no'].' · Der Empfang wurde bestätigt und die Inhalte mit '.$rating.' von 5 Sternen bewertet. '.money($released).' sind jetzt auszahlbar.',
            '/seller/wallet','receipt-review:'.$offerId,'payouts');
        flash('success','Empfang und Bewertung gespeichert. '.money($released).' wurden im Wallet auszahlbar.');
    }else{
        notify_seller((int)$offer['seller_id'],'review','Bewertung aktualisiert',
            $offer['offer_no'].' · Die hinterlegte Bewertung wurde auf '.$rating.' von 5 Sternen aktualisiert.',
            '/seller/orders','receipt-review-update:'.$offerId.':'.time());
        flash('success','Empfang und Bewertung wurden aktualisiert.');
    }

    redirect('/admin/offer/'.$offerId);
}

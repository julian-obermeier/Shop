<?php
declare(strict_types=1);

if (preg_match('#^/admin/order/(\d+)/message$#',$path,$m) && $method==='POST') {
    $a=require_admin();$orderId=(int)$m[1];$body=post('body');
    if($body===''||mb_strlen($body)>4000){flash('error','Die Nachricht muss zwischen 1 und 4000 Zeichen lang sein.');redirect('/admin/order/'.$orderId);}
    $q=db()->prepare("SELECT o.id,o.order_no,o.seller_id FROM orders o WHERE o.id=?");$q->execute([$orderId]);$o=$q->fetch();if(!$o)not_found();
    db()->prepare("INSERT INTO order_messages(order_id,sender_role,sender_id,body,read_by_admin_at) VALUES(?,'admin',?,?,NOW())")
        ->execute([$orderId,$a['id'],$body]);
    notify_seller((int)$o['seller_id'],'message','Neue Nachricht zum Auftrag '.$o['order_no'],$body,'/seller/order/'.$orderId,'message:'.db()->lastInsertId(),'messages');
    log_event(null,$orderId,'message.sent',['direction'=>'platform_to_seller']);
    flash('success','Nachricht wurde an die Verkäuferin gesendet.');
    redirect('/admin/order/'.$orderId);
}

if (preg_match('#^/seller/order/(\d+)/message$#',$path,$m) && $method==='POST') {
    $s=require_seller();$orderId=(int)$m[1];$body=post('body');
    if(is_seller_impersonation()){flash('error','Während der Verkäuferinnen-Vorschau können keine Nachrichten gesendet werden.');redirect('/seller/order/'.$orderId);}
    if($body===''||mb_strlen($body)>4000){flash('error','Die Nachricht muss zwischen 1 und 4000 Zeichen lang sein.');redirect('/seller/order/'.$orderId);}
    $q=db()->prepare("SELECT id,order_no FROM orders WHERE id=? AND seller_id=?");$q->execute([$orderId,$s['id']]);$o=$q->fetch();if(!$o)not_found();
    db()->prepare("INSERT INTO order_messages(order_id,sender_role,sender_id,body,read_by_seller_at) VALUES(?,'seller',?,?,NOW())")
        ->execute([$orderId,$s['id'],$body]);
    $messageId=(int)db()->lastInsertId();
    notify_admins('message','Neue Nachricht von einer Verkäuferin',$o['order_no'].' · '.$body,'/admin/order/'.$orderId,'message:'.$messageId);
    log_event(null,$orderId,'message.sent',['direction'=>'seller_to_platform']);
    flash('success','Nachricht wurde an die Plattform gesendet.');
    redirect('/seller/order/'.$orderId);
}

<?php
declare(strict_types=1);

if (($path==='/admin/notifications' || $path==='/seller/notifications') && $method==='GET') {
    $u=require_login();
    $expected=$u['role']==='admin'?'/admin/notifications':'/seller/notifications';
    if($path!==$expected){http_response_code(403);exit('Zugriff verweigert.');}
    $q=db()->prepare("SELECT * FROM notifications WHERE user_role=? AND user_id=? ORDER BY read_at IS NULL DESC,created_at DESC LIMIT 200");
    $q->execute([$u['role'],$u['id']]);$rows=$q->fetchAll();
    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Benachrichtigungen</span><h1>Mitteilungen</h1><p>Neue Vorgänge, Fristen und Rückmeldungen zentral im Blick.</p></div>
        <?php if($rows):?><form method="post" action="<?=e(url($expected.'/read-all'))?>"><button class="btn ghost">Alle als gelesen markieren</button></form><?php endif;?>
    </div>
    <div class="notification-list">
    <?php foreach($rows as $n):?>
        <article class="panel notification-item <?=empty($n['read_at'])?'unread':''?>">
            <div class="notification-icon"><?=e(match($n['type']){'message'=>'💬','payout'=>'€','overdue'=>'!','reminder'=>'⏱','evidence'=>'📷','offer'=>'◫','shipping'=>'↗','scent'=>'≈',default=>'•'})?></div>
            <div class="notification-copy">
                <div class="notification-head"><strong><?=e($n['title'])?></strong><span><?=e(date('d.m.Y H:i',strtotime($n['created_at'])))?> Uhr</span></div>
                <?php if($n['body']):?><p><?=nl2br(e($n['body']))?></p><?php endif;?>
            </div>
            <div class="notification-actions">
                <?php if($n['target_url']):?>
                    <form method="post" action="<?=e(url($expected.'/'.$n['id'].'/open'))?>"><button class="btn <?=empty($n['read_at'])?'':'ghost'?>">Öffnen</button></form>
                <?php elseif(empty($n['read_at'])):?>
                    <form method="post" action="<?=e(url($expected.'/'.$n['id'].'/read'))?>"><button class="btn ghost">Gelesen</button></form>
                <?php endif;?>
            </div>
        </article>
    <?php endforeach;?>
    <?php if(!$rows):?><div class="empty">Noch keine Benachrichtigungen vorhanden.</div><?php endif;?>
    </div>
    <?php render('Benachrichtigungen',ob_get_clean());exit;
}

if (preg_match('#^/(admin|seller)/notifications/read-all$#',$path,$m) && $method==='POST') {
    $u=require_login();if($u['role']!==$m[1]){http_response_code(403);exit('Zugriff verweigert.');}
    db()->prepare("UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_role=? AND user_id=?")->execute([$u['role'],$u['id']]);
    redirect('/'.$u['role'].'/notifications');
}

if (preg_match('#^/(admin|seller)/notifications/(\d+)/(open|read)$#',$path,$m) && $method==='POST') {
    $u=require_login();if($u['role']!==$m[1]){http_response_code(403);exit('Zugriff verweigert.');}
    $q=db()->prepare("SELECT * FROM notifications WHERE id=? AND user_role=? AND user_id=?");
    $q->execute([(int)$m[2],$u['role'],$u['id']]);$n=$q->fetch();if(!$n)not_found();
    db()->prepare("UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=?")->execute([$n['id']]);
    if($m[3]==='open'&&!empty($n['target_url']))redirect((string)$n['target_url']);
    redirect('/'.$u['role'].'/notifications');
}

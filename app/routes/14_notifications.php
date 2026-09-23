<?php
declare(strict_types=1);

if (($path==='/admin/notifications' || $path==='/seller/notifications') && $method==='GET') {
    $u=require_login();
    $expected=$u['role']==='admin'?'/admin/notifications':'/seller/notifications';
    if($path!==$expected){http_response_code(403);exit('Zugriff verweigert.');}
    $q=db()->prepare("SELECT * FROM notifications WHERE user_role=? AND user_id=? ORDER BY read_at IS NULL DESC,created_at DESC LIMIT 200");
    $q->execute([$u['role'],$u['id']]);$rows=$q->fetchAll();
    $emailPrefs=$u['role']==='seller'?seller_notification_preferences((int)$u['id']):null;
    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Benachrichtigungen</span><h1>Mitteilungen</h1><p>Neue Vorgänge, Fristen und Rückmeldungen zentral im Blick.</p></div>
        <?php if($rows):?><form method="post" action="<?=e(url($expected.'/read-all'))?>"><button class="btn ghost">Alle als gelesen markieren</button></form><?php endif;?>
    </div>

    <?php if($u['role']==='seller'):?>
    <section class="panel notification-settings">
        <div class="section-head">
            <div><h2>E-Mail-Benachrichtigungen</h2><span class="muted">Optional zusätzlich zu den Mitteilungen im Portal</span></div>
            <span class="badge"><?=e($u['email'])?></span>
        </div>
        <p class="muted">E-Mails werden neutral von der Vermittlungsplattform versendet. Standardmäßig sind alle Kategorien deaktiviert.</p>
        <?php if(is_seller_impersonation()):?>
            <div class="notice">In der Verkäuferinnen-Vorschau können E-Mail-Einstellungen nicht geändert werden.</div>
        <?php else:?>
        <form method="post" action="<?=e(url('/seller/notifications/settings'))?>">
            <div class="notification-pref-grid">
                <label class="notification-pref"><input type="checkbox" name="email_offers" value="1" <?=!empty($emailPrefs['email_offers'])?'checked':''?>><span><strong>Angebote</strong><small>Neue, geänderte oder zurückgezogene Angebote</small></span></label>
                <label class="notification-pref"><input type="checkbox" name="email_evidence" value="1" <?=!empty($emailPrefs['email_evidence'])?'checked':''?>><span><strong>Foto-/Nachweis-Nachforderungen</strong><small>Wenn die Plattform ein Foto oder fehlende Nachweise erneut anfordert</small></span></label>
                <label class="notification-pref"><input type="checkbox" name="email_messages" value="1" <?=!empty($emailPrefs['email_messages'])?'checked':''?>><span><strong>Nachrichten der Plattform</strong><small>Neue Nachricht innerhalb eines Auftrags</small></span></label>
                <label class="notification-pref"><input type="checkbox" name="email_upcoming" value="1" <?=!empty($emailPrefs['email_upcoming'])?'checked':''?>><span><strong>Bevorstehende Nachweise</strong><small>Erinnerung, wenn ein Nachweisfenster innerhalb der nächsten Stunde beginnt</small></span></label>
                <label class="notification-pref"><input type="checkbox" name="email_payouts" value="1" <?=!empty($emailPrefs['email_payouts'])?'checked':''?>><span><strong>Auszahlungen</strong><small>Wenn eine Auszahlung durch die Plattform dokumentiert wurde</small></span></label>
            </div>
            <button class="btn">E-Mail-Einstellungen speichern</button>
        </form>
        <?php endif;?>
    </section>
    <?php endif;?>

    <div class="notification-list">
    <?php foreach($rows as $n):?>
        <article class="panel notification-item <?=empty($n['read_at'])?'unread':''?>">
            <div class="notification-icon"><?=e(match($n['type']){'message'=>'💬','payout'=>'€','overdue'=>'!','reminder'=>'⏱','evidence'=>'📷','offer'=>'◫','shipping'=>'↗','scent'=>'≈',default=>'•'})?></div>
            <div class="notification-copy">
                <div class="notification-head"><strong><?=e($n['title'])?></strong><span><?=e(date('d.m.Y H:i',strtotime($n['created_at'])))?> Uhr</span></div>
                <?php if($n['body']):?><p><?=nl2br(e($n['body']))?></p><?php endif;?>
                <?php if($u['role']==='seller'&&!empty($n['email_category'])):?>
                    <div class="notification-email-meta">
                        <?php if($n['email_sent_at']):?><span>✉ E-Mail gesendet <?=e(date('d.m.Y H:i',strtotime($n['email_sent_at'])))?> Uhr</span>
                        <?php elseif($n['email_attempted_at']&&$n['email_error']):?><span class="mail-error">✉ E-Mail-Versand fehlgeschlagen</span>
                        <?php endif;?>
                    </div>
                <?php endif;?>
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

if ($path==='/seller/notifications/settings' && $method==='POST') {
    $s=require_seller();
    if(is_seller_impersonation()){flash('error','In der Verkäuferinnen-Vorschau können E-Mail-Einstellungen nicht geändert werden.');redirect('/seller/notifications');}

    $values=[
        post('email_offers')==='1'?1:0,
        post('email_evidence')==='1'?1:0,
        post('email_messages')==='1'?1:0,
        post('email_upcoming')==='1'?1:0,
        post('email_payouts')==='1'?1:0,
    ];
    db()->prepare("INSERT INTO seller_notification_preferences(
            seller_id,email_offers,email_evidence,email_messages,email_upcoming,email_payouts,updated_at
        ) VALUES(?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE
            email_offers=VALUES(email_offers),
            email_evidence=VALUES(email_evidence),
            email_messages=VALUES(email_messages),
            email_upcoming=VALUES(email_upcoming),
            email_payouts=VALUES(email_payouts),
            updated_at=NOW()")
        ->execute(array_merge([(int)$s['id']],$values));

    log_event(null,null,'notification.email_preferences_updated',[
        'seller_id'=>(int)$s['id'],
        'offers'=>(bool)$values[0],
        'evidence'=>(bool)$values[1],
        'messages'=>(bool)$values[2],
        'upcoming'=>(bool)$values[3],
        'payouts'=>(bool)$values[4],
    ]);
    flash('success','E-Mail-Benachrichtigungen wurden gespeichert.');
    redirect('/seller/notifications');
}

if (preg_match('#^/(admin|seller)/notifications/read-all$#',$path,$m) && $method==='POST') {
    $u=require_login();if($u['role']!==$m[1]){http_response_code(403);exit('Zugriff verweigert.');}
    if($u['role']==='seller'&&is_seller_impersonation()){flash('error','In der Verkäuferinnen-Vorschau werden Benachrichtigungen nicht als gelesen markiert.');redirect('/seller/notifications');}
    db()->prepare("UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_role=? AND user_id=?")->execute([$u['role'],$u['id']]);
    redirect('/'.$u['role'].'/notifications');
}

if (preg_match('#^/(admin|seller)/notifications/(\d+)/(open|read)$#',$path,$m) && $method==='POST') {
    $u=require_login();if($u['role']!==$m[1]){http_response_code(403);exit('Zugriff verweigert.');}
    if($u['role']==='seller'&&is_seller_impersonation()){flash('error','In der Verkäuferinnen-Vorschau werden Benachrichtigungen nicht verändert.');redirect('/seller/notifications');}
    $q=db()->prepare("SELECT * FROM notifications WHERE id=? AND user_role=? AND user_id=?");
    $q->execute([(int)$m[2],$u['role'],$u['id']]);$n=$q->fetch();if(!$n)not_found();
    db()->prepare("UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=?")->execute([$n['id']]);
    if($m[3]==='open'&&!empty($n['target_url']))redirect((string)$n['target_url']);
    redirect('/'.$u['role'].'/notifications');
}

<?php
declare(strict_types=1);

// ADMIN SELLERS + INVITATIONS
if ($path === '/admin/sellers' && $method === 'GET') {
    require_admin();
    $sellers=db()->query('SELECT * FROM sellers ORDER BY active DESC,first_name,last_name')->fetchAll();
    $invites=db()->query("SELECT i.*,a.name admin_name,s.first_name used_first_name,s.last_name used_last_name
        FROM seller_invitations i
        JOIN admins a ON a.id=i.admin_id
        LEFT JOIN sellers s ON s.id=i.used_by_seller_id
        ORDER BY i.created_at DESC
        LIMIT 30")->fetchAll();
    $created=pull_created_invitation();

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">Verkäuferinnen</span><h1>Konten & Einladungen</h1><p>Konten direkt anlegen oder Verkäuferinnen per Link beziehungsweise E-Mail einladen.</p></div>
    </div>

    <?php if($created):?>
    <section class="panel invite-result">
        <div class="section-head"><h2>Einladungslink erstellt</h2><span class="status status-active"><?=!empty($created['sent'])?'E-Mail versendet':'Link bereit'?></span></div>
        <?php if(!empty($created['email'])):?><p>Für: <strong><?=e($created['email'])?></strong></p><?php endif;?>
        <label>Einladungslink
            <div class="copy-row">
                <input id="new-invite-link" value="<?=e($created['link'])?>" readonly>
                <button type="button" class="btn ghost" data-copy-target="#new-invite-link">Link kopieren</button>
            </div>
        </label>
        <p class="muted">Gültig bis <?=e(date('d.m.Y H:i',strtotime($created['expires_at'])))?> Uhr · einmalig verwendbar.</p>
        <?php if(isset($created['sent'])&&!$created['sent']&&!empty($created['email'])):?><div class="notice warning">Der Link wurde erstellt, aber der automatische E-Mail-Versand ist fehlgeschlagen. Du kannst den Link manuell senden.</div><?php endif;?>
    </section>
    <?php endif;?>

    <div class="grid two">
        <section class="panel">
            <h2>Verkäuferin einladen</h2>
            <p class="muted">E-Mail ist nur für den E-Mail-Versand zwingend. Für einen allgemeinen Einladungslink kann sie leer bleiben.</p>
            <form method="post" action="<?=e(url('/admin/sellers/invite'))?>">
                <label>E-Mail (optional für Link)<input type="email" name="email" placeholder="name@beispiel.de"></label>
                <div class="invite-actions">
                    <button class="btn ghost" name="mode" value="link">Einladungslink erstellen</button>
                    <button class="btn" name="mode" value="email">Per E-Mail einladen</button>
                </div>
                <p class="field-hint">Einladungen sind 7 Tage gültig und können nur einmal verwendet werden.</p>
            </form>
        </section>

        <section class="panel">
            <h2>Konto direkt anlegen</h2>
            <form method="post" action="<?=e(url('/admin/sellers'))?>">
                <div class="form-grid">
                    <label>Vorname<input name="first_name" required></label>
                    <label>Nachname<input name="last_name" required></label>
                    <label>E-Mail<input type="email" name="email" required></label>
                    <label>Passwort<input type="password" name="password" minlength="10" required></label>
                </div>
                <button class="btn">Konto anlegen</button>
            </form>
        </section>
    </div>

    <section class="panel">
        <div class="section-head"><h2>Einladungen</h2><span class="muted">Letzte 30</span></div>
        <div class="list">
        <?php foreach($invites as $i):
            $expired=strtotime($i['expires_at'])<time();
            $status=$i['used_at']?'Angenommen':($i['revoked_at']?'Widerrufen':($expired?'Abgelaufen':($i['sent_at']?'E-Mail versendet':'Aktiv')));
            $statusClass=$i['used_at']?'completed':($i['revoked_at']||$expired?'not_fulfilled':'active');
        ?>
            <div class="list-row static">
                <div>
                    <strong><?=e($i['email']?:'Allgemeiner Einladungslink')?></strong>
                    <span>Erstellt <?=e(date('d.m.Y H:i',strtotime($i['created_at'])))?> · gültig bis <?=e(date('d.m.Y H:i',strtotime($i['expires_at'])))?><?php if($i['used_at']):?> · angenommen von <?=e(trim(($i['used_first_name']??'').' '.($i['used_last_name']??'')))?><?php endif;?></span>
                </div>
                <div class="invite-list-actions">
                    <span class="status status-<?=e($statusClass)?>"><?=e($status)?></span>
                    <?php if(!$i['used_at']&&!$i['revoked_at']&&!$expired):?>
                    <form method="post" action="<?=e(url('/admin/sellers/invite/'.$i['id'].'/revoke'))?>">
                        <button class="btn ghost danger">Widerrufen</button>
                    </form>
                    <?php endif;?>
                </div>
            </div>
        <?php endforeach;?>
        <?php if(!$invites):?><div class="empty">Noch keine Einladungen erstellt.</div><?php endif;?>
        </div>
    </section>

    <section class="panel">
        <h2>Vorhandene Konten</h2>
        <div class="list">
        <?php foreach($sellers as $s):?>
            <div class="list-row static">
                <div><a class="seller-name-link" href="<?=e(url('/admin/seller/'.$s['id']))?>"><strong><?=e($s['first_name'].' '.$s['last_name'])?></strong></a><span><?=e($s['email'])?></span></div>
                <div class="seller-row-actions">
                    <?php if((int)$s['active']):?>
                        <a class="btn ghost" href="<?=e(url('/admin/scent-requests').'?seller_id='.$s['id'])?>">Duftprobe anfragen</a>
                        <form method="post" action="<?=e(url('/admin/sellers/'.$s['id'].'/impersonate'))?>">
                            <button class="btn ghost">Als Verkäuferin anmelden</button>
                        </form>
                    <?php endif;?>
                    <span class="status"><?=((int)$s['active'])?'Aktiv':'Inaktiv'?></span>
                </div>
            </div>
        <?php endforeach;?>
        <?php if(!$sellers):?><div class="empty">Noch kein Konto angelegt.</div><?php endif;?>
        </div>
    </section>
    <?php render('Verkäuferinnen',ob_get_clean());exit;
}



if (preg_match('#^/admin/sellers/(\d+)/impersonate$#',$path,$m) && $method==='POST') {
    $admin=require_admin();$sellerId=(int)$m[1];

    $q=db()->prepare('SELECT id,first_name,last_name,active FROM sellers WHERE id=?');
    $q->execute([$sellerId]);$seller=$q->fetch();
    if(!$seller||(int)$seller['active']!==1){
        flash('error','Diese Verkäuferin ist nicht aktiv.');
        redirect('/admin/sellers');
    }

    log_event(null,null,'impersonation.started',[
        'seller_id'=>$sellerId,
        'seller_name'=>trim((string)$seller['first_name'].' '.(string)$seller['last_name'])
    ]);

    start_seller_impersonation((int)$admin['id'],$sellerId);
    redirect('/seller');
}

if ($path === '/admin/sellers' && $method === 'POST') {
    require_admin();$email=strtolower(post('email'));$pw=(string)($_POST['password']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pw)<10||!post('first_name')||!post('last_name')){
        flash('error','Bitte alle Felder korrekt ausfüllen.');redirect('/admin/sellers');
    }
    try{
        db()->prepare('INSERT INTO sellers(email,first_name,last_name,password_hash) VALUES(?,?,?,?)')
            ->execute([$email,post('first_name'),post('last_name'),password_hash($pw,PASSWORD_DEFAULT)]);
        flash('success','Verkäuferinnenkonto wurde angelegt.');
    }catch(PDOException $e){
        flash('error','Die E-Mail-Adresse ist bereits vergeben.');
    }
    redirect('/admin/sellers');
}

if ($path === '/admin/sellers/invite' && $method === 'POST') {
    $admin=require_admin();$mode=post('mode');$email=strtolower(post('email'));
    if(!in_array($mode,['link','email'],true)){flash('error','Ungültige Einladungsart.');redirect('/admin/sellers');}
    if($mode==='email'&&!filter_var($email,FILTER_VALIDATE_EMAIL)){
        flash('error','Für eine E-Mail-Einladung ist eine gültige E-Mail-Adresse erforderlich.');redirect('/admin/sellers');
    }

    try{
        $invite=create_seller_invitation((int)$admin['id'],$email!==''?$email:null);
        $sent=false;
        if($mode==='email'){
            $sent=send_seller_invitation_email($email,$invite['link'],$invite['expires_at']);
            if($sent)db()->prepare('UPDATE seller_invitations SET sent_at=NOW() WHERE id=?')->execute([$invite['id']]);
        }
        $invite['sent']=$sent;
        $_SESSION['_created_invitation']=$invite;
        flash('success',$mode==='email'&&$sent?'Einladung wurde per E-Mail versendet.':'Einladungslink wurde erstellt.');
    }catch(Throwable $e){
        flash('error',$e->getMessage());
    }
    redirect('/admin/sellers');
}

if (preg_match('#^/admin/sellers/invite/(\d+)/revoke$#',$path,$m) && $method==='POST') {
    require_admin();$id=(int)$m[1];
    $q=db()->prepare("UPDATE seller_invitations SET revoked_at=NOW()
        WHERE id=? AND used_at IS NULL AND revoked_at IS NULL AND expires_at>NOW()");
    $q->execute([$id]);
    flash($q->rowCount()?'success':'error',$q->rowCount()?'Einladung wurde widerrufen.':'Diese Einladung kann nicht mehr widerrufen werden.');
    redirect('/admin/sellers');
}

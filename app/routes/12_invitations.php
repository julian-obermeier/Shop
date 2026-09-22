<?php
declare(strict_types=1);

// PUBLIC SELLER INVITATION
if (preg_match('#^/invite/([a-f0-9]{64})$#',$path,$m) && $method==='GET') {
    $token=$m[1];
    $invite=seller_invitation_by_token($token);
    if(!$invite){
        ob_start();?>
        <div class="auth-shell single">
            <section class="panel auth-card">
                <span class="eyebrow">Einladung</span>
                <h2>Einladung nicht mehr gültig</h2>
                <p>Dieser Einladungslink wurde bereits verwendet, widerrufen oder ist abgelaufen.</p>
                <a class="btn ghost full" href="<?=e(url('/login'))?>">Zum Login</a>
            </section>
        </div>
        <?php render('Einladung ungültig',ob_get_clean());exit;
    }

    ob_start();?>
    <div class="auth-shell">
        <section class="auth-copy">
            <span class="eyebrow">Einladung</span>
            <h1>Verkäuferinnenkonto anlegen</h1>
            <p>Du wurdest eingeladen, dem privaten Auftragsportal beizutreten. Der Link ist einmalig verwendbar.</p>
            <div class="feature-list">
                <div>✓ Persönliche Angebote</div>
                <div>✓ Nachweise und Aufträge</div>
                <div>✓ Wallet und Auszahlungen</div>
                <div>✓ Geschützter Versandworkflow</div>
            </div>
        </section>
        <form class="panel auth-card" method="post">
            <h2>Konto erstellen</h2>
            <div class="form-grid">
                <label>Vorname<input name="first_name" maxlength="120" required autocomplete="given-name"></label>
                <label>Nachname<input name="last_name" maxlength="120" required autocomplete="family-name"></label>
            </div>
            <?php if($invite['email']):?>
                <label>E-Mail<input type="email" value="<?=e($invite['email'])?>" readonly></label>
                <p class="field-hint">Diese Einladung ist fest an diese E-Mail-Adresse gebunden.</p>
            <?php else:?>
                <label>E-Mail<input type="email" name="email" required autocomplete="email"></label>
            <?php endif;?>
            <label>Passwort<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
            <label>Passwort wiederholen<input type="password" name="password_confirm" minlength="10" required autocomplete="new-password"></label>
            <button class="btn full">Konto erstellen</button>
            <p class="muted">Einladung gültig bis <?=e(date('d.m.Y H:i',strtotime($invite['expires_at'])))?> Uhr.</p>
        </form>
    </div>
    <?php render('Einladung annehmen',ob_get_clean());exit;
}

if (preg_match('#^/invite/([a-f0-9]{64})$#',$path,$m) && $method==='POST') {
    $token=$m[1];$hash=hash('sha256',$token);
    $first=post('first_name');$last=post('last_name');
    $pw=(string)($_POST['password']??'');$confirm=(string)($_POST['password_confirm']??'');

    db()->beginTransaction();
    try{
        $q=db()->prepare("SELECT * FROM seller_invitations
            WHERE token_hash=? AND used_at IS NULL AND revoked_at IS NULL AND expires_at>NOW()
            FOR UPDATE");
        $q->execute([$hash]);$invite=$q->fetch();
        if(!$invite)throw new RuntimeException('Diese Einladung ist nicht mehr gültig.');

        $email=$invite['email']?strtolower((string)$invite['email']):strtolower(post('email'));
        if($first===''||$last===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Bitte alle Angaben vollständig ausfüllen.');
        if(strlen($pw)<10)throw new RuntimeException('Das Passwort muss mindestens 10 Zeichen lang sein.');
        if($pw!==$confirm)throw new RuntimeException('Die Passwörter stimmen nicht überein.');

        $q=db()->prepare('SELECT COUNT(*) FROM sellers WHERE email=?');$q->execute([$email]);
        if((int)$q->fetchColumn()>0)throw new RuntimeException('Für diese E-Mail-Adresse existiert bereits ein Konto.');

        db()->prepare('INSERT INTO sellers(email,first_name,last_name,password_hash) VALUES(?,?,?,?)')
            ->execute([$email,$first,$last,password_hash($pw,PASSWORD_DEFAULT)]);
        $sellerId=(int)db()->lastInsertId();

        db()->prepare('UPDATE seller_invitations SET used_at=NOW(),used_by_seller_id=? WHERE id=?')
            ->execute([$sellerId,$invite['id']]);
        db()->commit();

        session_regenerate_id(true);
        $_SESSION['role']='seller';
        $_SESSION['user_id']=$sellerId;
        flash('success','Dein Verkäuferinnenkonto wurde erstellt. Willkommen im Portal.');
        redirect('/seller');
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());
        redirect('/invite/'.$token);
    }
}

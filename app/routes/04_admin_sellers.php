<?php
declare(strict_types=1);
// ADMIN SELLERS
if ($path === '/admin/sellers' && $method === 'GET') {
    require_admin(); $sellers=db()->query('SELECT * FROM sellers ORDER BY active DESC,first_name,last_name')->fetchAll();
    ob_start(); ?><div class="page-head"><div><span class="eyebrow">Verkäuferinnen</span><h1>Konten</h1></div></div><div class="grid two"><section class="panel"><h2>Neue Verkäuferin</h2><form method="post" action="<?=e(url('/admin/sellers'))?>"><div class="form-grid"><label>Vorname<input name="first_name" required></label><label>Nachname<input name="last_name" required></label><label>E-Mail<input type="email" name="email" required></label><label>Passwort<input type="password" name="password" minlength="10" required></label></div><button class="btn">Konto anlegen</button></form></section><section class="panel"><h2>Vorhandene Konten</h2><div class="list"><?php foreach($sellers as $s):?><div class="list-row static"><div><strong><?=e($s['first_name'].' '.$s['last_name'])?></strong><span><?=e($s['email'])?></span></div><span class="status"><?=((int)$s['active'])?'Aktiv':'Inaktiv'?></span></div><?php endforeach;?><?php if(!$sellers):?><div class="empty">Noch kein Konto angelegt.</div><?php endif;?></div></section></div><?php render('Verkäuferinnen',ob_get_clean());exit;
}
if ($path === '/admin/sellers' && $method === 'POST') {
    require_admin(); $email=strtolower(post('email')); $pw=(string)($_POST['password']??'');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pw)<10||!post('first_name')||!post('last_name')){flash('error','Bitte alle Felder korrekt ausfüllen.');redirect('/admin/sellers');}
    try{db()->prepare('INSERT INTO sellers(email,first_name,last_name,password_hash) VALUES(?,?,?,?)')->execute([$email,post('first_name'),post('last_name'),password_hash($pw,PASSWORD_DEFAULT)]);flash('success','Verkäuferinnenkonto wurde angelegt.');}
    catch(PDOException $e){flash('error','Die E-Mail-Adresse ist bereits vergeben.');}
    redirect('/admin/sellers');
}


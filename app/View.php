<?php declare(strict_types=1); ?>
<!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#111827"><title><?=e($title)?> · <?=e(app_config('app.name','Auftragsportal'))?></title>
<link rel="stylesheet" href="<?=e(url('/assets/app.css'))?>"></head><body>
<header class="topbar"><a class="brand" href="<?=e(url('/'))?>"><span class="brand-mark">A</span><span><?=e(app_config('app.name','Auftragsportal'))?></span></a>
<?php if($user):?><nav><?php if($user['role']==='admin'):?>
<a href="<?=e(url('/admin'))?>">Übersicht</a><a href="<?=e(url('/admin/reviews'))?>">Prüfcenter</a><a href="<?=e(url('/admin/offers'))?>">Angebote</a><a href="<?=e(url('/admin/orders'))?>">Aufträge</a><a href="<?=e(url('/admin/wallets'))?>">Wallets</a><a href="<?=e(url('/admin/sellers'))?>">Verkäuferinnen</a><a href="<?=e(url('/admin/settings'))?>">Versandadresse</a>
<?php else:?><a href="<?=e(url('/seller'))?>">Übersicht</a><a href="<?=e(url('/seller/offers'))?>">Angebote</a><a href="<?=e(url('/seller/orders'))?>">Aufträge</a><a href="<?=e(url('/seller/wallet'))?>">Wallet</a><?php endif;?><a href="<?=e(url('/logout'))?>">Abmelden</a></nav><?php endif;?></header>
<?php foreach($flashes as [$type,$message]):?><div class="flash <?=e($type)?>"><?=e($message)?></div><?php endforeach;?>
<main><?= $content ?></main>
<footer>Private Vermittlungsplattform · Geschützte Nachweise · <?=date('Y')?></footer>
<script src="<?=e(url('/assets/app.js'))?>"></script></body></html>

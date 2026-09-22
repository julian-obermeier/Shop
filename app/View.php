<?php declare(strict_types=1); ?>
<!doctype html><html lang="de"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#111827"><title><?=e($title)?> · <?=e(app_config('app.name','Auftragsportal'))?></title>
<link rel="stylesheet" href="<?=e(url('/assets/app.css'))?>"></head><body>
<header class="topbar"><a class="brand" href="<?=e(url('/'))?>"><span class="brand-mark">A</span><span><?=e(app_config('app.name','Auftragsportal'))?></span></a>
<?php if($user):?><nav><?php if($user['role']==='admin'):?>
<a href="<?=e(url('/admin'))?>">Heute</a><a href="<?=e(url('/admin/reviews'))?>">Prüfcenter</a><a href="<?=e(url('/admin/offers'))?>">Angebote</a><a href="<?=e(url('/admin/orders'))?>">Aufträge</a><a href="<?=e(url('/admin/scent-requests'))?>">Duftproben</a><a href="<?=e(url('/admin/wallets'))?>">Wallets</a><a href="<?=e(url('/admin/sellers'))?>">Verkäuferinnen</a><a class="nav-notifications" href="<?=e(url('/admin/notifications'))?>">Mitteilungen<?php if($notificationUnread):?><b><?=$notificationUnread?></b><?php endif;?></a><a href="<?=e(url('/admin/settings'))?>">Versandadresse</a>
<?php else:?><a href="<?=e(url('/seller'))?>">Übersicht</a><a href="<?=e(url('/seller/offers'))?>">Angebote</a><a href="<?=e(url('/seller/orders'))?>">Aufträge</a><a href="<?=e(url('/seller/scent-requests'))?>">Duftproben</a><a href="<?=e(url('/seller/wallet'))?>">Wallet</a><a class="nav-notifications" href="<?=e(url('/seller/notifications'))?>">Mitteilungen<?php if($notificationUnread):?><b><?=$notificationUnread?></b><?php endif;?></a><?php endif;?><a href="<?=e(url('/logout'))?>">Abmelden</a></nav><?php endif;?></header>
<?php if($impersonator):?><div class="impersonation-bar"><span>Adminansicht · Verkäuferinnenkonto geöffnet</span><form method="post" action="<?=e(url('/impersonation/stop'))?>"><?=csrf_field()?><button class="btn">Zurück zur Verwaltung</button></form></div><?php endif;?>
<?php foreach($flashes as [$type,$message]):?><div class="flash <?=e($type)?>"><?=e($message)?></div><?php endforeach;?>
<main><?= $content ?></main>
<footer>Private Vermittlungsplattform · Geschützte Nachweise · <?=date('Y')?></footer>
<script src="<?=e(url('/assets/app.js'))?>"></script></body></html>

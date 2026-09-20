<?php ?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#17151b">
<title><?= e($title) ?> · <?= e(app_config('app.name','Private Ankauf')) ?></title>
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= e(url('/assets/app.css')) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= e(url('/')) ?>">Private<span>Ankauf</span></a>
  <nav>
    <a href="<?= e(url('/angebote')) ?>">Angebote</a>
    <a href="<?= e(url('/so-funktioniert-es')) ?>">So funktioniert's</a>
    <a href="<?= e(url('/faq')) ?>">FAQ</a>
    <?php if ($seller): ?><a href="<?= e(url('/heute')) ?>">Heute</a><a href="<?= e(url('/dashboard')) ?>">Dashboard</a><a href="<?= e(url('/individuelle-angebote')) ?>">Einzelangebote</a><a href="<?= e(url('/wallet')) ?>">Wallet</a><a href="<?= e(url('/archiv')) ?>">Archiv</a><a href="<?= e(url('/profil')) ?>">Profil</a><a href="<?= e(url('/logout')) ?>">Abmelden</a>
    <?php elseif ($admin): ?><a href="<?= e(url('/admin')) ?>">Admin</a><a href="<?= e(url('/admin/heute')) ?>">Heute</a><a href="<?= e(url('/admin/suche')) ?>">Suche</a><a href="<?= e(url('/admin/auftraege')) ?>">Aufträge</a><a href="<?= e(url('/admin/archiv')) ?>">Archiv</a><a href="<?= e(url('/admin/kalender')) ?>">Kalender</a><a href="<?= e(url('/admin/einzelangebote')) ?>">Einzelangebote</a><a href="<?= e(url('/admin/aufgabenbibliothek')) ?>">Aufgaben</a><a href="<?= e(url('/admin/verkaeuferinnen')) ?>">Verkäuferinnen</a><a href="<?= e(url('/admin/auszahlungen')) ?>">Auszahlungen</a><a href="<?= e(url('/admin/einstellungen')) ?>">Einstellungen</a><a href="<?= e(url('/admin/logout')) ?>">Abmelden</a>
    <?php else: ?><a href="<?= e(url('/login')) ?>">Login</a><a class="nav-cta" href="<?= e(url('/registrieren')) ?>">Registrieren</a><?php endif; ?>
  </nav>
</header>
<?php foreach($flashes as [$type,$msg]): ?><div class="flash <?=e($type)?>"><?=e($msg)?></div><?php endforeach; ?>
<main><?= $content ?></main>
<footer>
  <div><strong>Nur für Volljährige ab 18 Jahren.</strong></div>
  <div class="footer-links"><a href="<?=e(url('/regeln'))?>">Regeln</a><a href="<?=e(url('/datenschutz'))?>">Datenschutz</a><a href="<?=e(url('/impressum'))?>">Impressum</a><a href="<?=e(url('/kontakt'))?>">Kontakt</a></div>
</footer>
<script src="<?= e(url('/assets/app.js')) ?>"></script>
</body>
</html>

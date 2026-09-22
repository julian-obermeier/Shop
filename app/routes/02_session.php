<?php
declare(strict_types=1);
// Public / session
if ($path === '/' && $method === 'GET') {
    $u = current_user();
    if (!$u) redirect('/login');
    redirect($u['role'] === 'admin' ? '/admin' : '/seller');
}
if ($path === '/login' && $method === 'GET') {
    if (current_user()) redirect('/');
    ob_start(); ?>
    <div class="auth-shell"><section class="auth-copy"><span class="eyebrow">Private Vermittlungsplattform</span><h1>Angebote, Nachweise und Aufträge – zentral organisiert.</h1><p>Die Plattform vermittelt Angebote und übernimmt die organisatorische Abwicklung zwischen den beteiligten Parteien.</p><div class="feature-list"><div>✓ Persönliche Angebote mit mehreren Positionen</div><div>✓ Strukturierte Vorabkontrollen</div><div>✓ Geschützte Foto-Nachweise je Durchführungstag</div><div>✓ Wallet, Versand und Auszahlungsstatus</div></div></section><form class="panel auth-card" method="post"><?=csrf_field()?><h2>Anmelden</h2><label>E-Mail<input type="email" name="email" autocomplete="username" required></label><label>Passwort<input type="password" name="password" autocomplete="current-password" required></label><button class="btn full">Login</button></form></div>
    <?php render('Login', ob_get_clean()); exit;
}
if ($path === '/login' && $method === 'POST') {
    $email = strtolower(post('email')); $pw = (string)($_POST['password'] ?? '');
    $q = db()->prepare('SELECT id,password_hash FROM admins WHERE email=?'); $q->execute([$email]); $row=$q->fetch(); $role='admin';
    if (!$row) { $q=db()->prepare('SELECT id,password_hash,active FROM sellers WHERE email=?'); $q->execute([$email]); $row=$q->fetch(); $role='seller'; }
    if (!$row || !password_verify($pw,(string)$row['password_hash']) || ($role==='seller' && !(int)$row['active'])) {
        flash('error','E-Mail oder Passwort ist falsch.'); redirect('/login');
    }
    session_regenerate_id(true);
    $_SESSION['role'] = $role;
    $_SESSION['user_id'] = (int)$row['id'];
    redirect('/');
}
if ($path === '/logout' && $method === 'GET') { $_SESSION=[]; session_destroy(); redirect('/login'); }


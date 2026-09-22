<?php
declare(strict_types=1);

if($path==='/admin/cron' && $method==='GET'){
    require_admin();
    $status=cron_status();
    $lastSuccess=$status['last_success_at']?new DateTimeImmutable($status['last_success_at']):null;
    $stale=!$lastSuccess || $lastSuccess < new DateTimeImmutable('-30 minutes');

    ob_start();?>
    <div class="page-head">
        <div><span class="eyebrow">System</span><h1>Reminder Engine</h1><p>Zeitgesteuerte Erinnerungen unabhängig von Seitenaufrufen.</p></div>
        <a class="btn ghost" href="<?=e(url('/admin'))?>">Zurück zu Heute</a>
    </div>

    <div class="stats compact">
        <div class="stat <?=$status['last_status']==='success'?'cron-ok':''?>"><span>Letzter Status</span><strong class="stat-date"><?=e($status['last_status']??'–')?></strong></div>
        <div class="stat <?=$stale?'cron-stale':''?>"><span>Letzter Erfolg</span><strong class="stat-date"><?=e($lastSuccess?$lastSuccess->format('d.m.Y H:i'):'Noch nie')?></strong></div>
        <div class="stat"><span>Zuletzt erzeugt</span><strong><?=$status['last_created_count']?></strong></div>
    </div>

    <?php if($stale):?><div class="notice warning"><strong>Cron läuft noch nicht regelmäßig.</strong><br>Richte den unten angegebenen Aufruf bei ALL-INKL idealerweise alle 10 Minuten ein.</div><?php endif;?>
    <?php if($status['last_error']):?><div class="notice warning"><strong>Letzter Fehler:</strong><br><?=nl2br(e($status['last_error']))?></div><?php endif;?>

    <section class="panel">
        <div class="section-head"><h2>ALL-INKL Cron-Aufruf</h2><span class="badge">Empfehlung: alle 10 Minuten</span></div>
        <p>Wenn dein Tarif HTTP-Cronjobs unterstützt, verwende diese geschützte URL:</p>
        <div class="copy-row">
            <input id="cron-url" readonly value="<?=e(cron_url())?>">
            <button type="button" class="btn ghost" data-copy-target="#cron-url">URL kopieren</button>
        </div>
        <p class="field-hint">Der Token liegt nur unter <code>storage/private/.cron-token</code> und wird nicht ins Git-Repository geschrieben.</p>

        <details class="inline-editor">
            <summary>Alternative per PHP-CLI</summary>
            <p class="muted">Falls ALL-INKL direkte PHP-Skripte als Cronjob ausführen kann, kann stattdessen <code>cron.php</code> im Projektverzeichnis regelmäßig über PHP gestartet werden. Bei CLI-Aufruf ist kein Token nötig.</p>
        </details>
    </section>

    <section class="panel">
        <div class="section-head"><h2>Testlauf</h2><span class="muted">führt dieselbe Logik wie der Cronjob aus</span></div>
        <form method="post" action="<?=e(url('/admin/cron/run'))?>">
            <button class="btn">Reminder Engine jetzt ausführen</button>
        </form>
    </section>
    <?php render('Reminder Engine',ob_get_clean());exit;
}

if($path==='/admin/cron/run' && $method==='POST'){
    require_admin();
    try{
        $result=run_scheduled_notifications();
        flash('success','Reminder Engine ausgeführt · '.$result['created'].' neue Erinnerung(en) erzeugt.');
    }catch(Throwable $e){
        flash('error','Reminder Engine fehlgeschlagen: '.$e->getMessage());
    }
    redirect('/admin/cron');
}

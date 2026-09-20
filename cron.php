<?php
declare(strict_types=1);
require __DIR__.'/app/Core.php';

$pdo=db();
$now=new DateTimeImmutable('now',new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
$nowSql=$now->format('Y-m-d H:i:s');
$grace=max(0,(int)setting_value('grace_minutes','60'));

$pdo->prepare("DELETE FROM email_verifications WHERE expires_at < ?")->execute([$nowSql]);
$pdo->prepare("DELETE FROM password_resets WHERE expires_at < ? OR used_at IS NOT NULL")->execute([$nowSql]);

function cron_provisional_violation(int $orderId, int $sellerId, string $sourceKey, string $type, string $reason): void {
    $q=db()->prepare("SELECT id FROM violations WHERE source_key=? LIMIT 1");$q->execute([$sourceKey]);
    if($q->fetchColumn()) return;
    db()->beginTransaction();
    try{
        db()->prepare("INSERT INTO violations(order_id,violation_type,source_key,status,reason,extension_days) VALUES(?,?,?,'open',?,1)")
          ->execute([$orderId,$type,$sourceKey,$reason]);
        $vid=(int)db()->lastInsertId();
        db()->prepare("INSERT INTO extra_days(order_id,source_type,source_id,status,paid,amount,reason) VALUES(?,'violation',?,'provisional',0,0,?)")
          ->execute([$orderId,$vid,$reason]);
        db()->commit();
        notify_seller($sellerId,'violation.open','Möglicher Verstoß erkannt',$reason.' · Ein zusätzlicher Tag wurde bis zur Adminprüfung vorläufig vorgemerkt.',null,'violation-'.$sourceKey,true);
    }catch(Throwable $e){db()->rollBack();throw $e;}
}

/* Zeitfenster öffnen und bereits vollständig belegte Fenster abschließen. */
$pdo->prepare("UPDATE evidence_windows SET status='open' WHERE status='planned' AND starts_at<=? AND ends_at>=?")->execute([$nowSql,$nowSql]);

$windows=$pdo->query("SELECT ew.*,o.seller_id,o.order_no FROM evidence_windows ew JOIN orders o ON o.id=ew.order_id WHERE ew.status IN('planned','open') AND o.status='running'")->fetchAll();
foreach($windows as $w){
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM evidences WHERE order_id=? AND evidence_type='daily' AND day_no=? AND window_key=? AND status<>'rejected'");
    $cnt->execute([$w['order_id'],$w['day_no'],$w['window_key']]);$submitted=(int)$cnt->fetchColumn();
    if($submitted>=(int)$w['required_count']){
        $pdo->prepare("UPDATE evidence_windows SET status='submitted' WHERE id=?")->execute([$w['id']]);
        continue;
    }

    $startTs=strtotime($w['starts_at']);$endTs=strtotime($w['ends_at']);$nowTs=$now->getTimestamp();
    $diffStart=$startTs-$nowTs;$diffEnd=$endTs-$nowTs;

    if($diffStart<=3600 && $diffStart>3300) notify_seller((int)$w['seller_id'],'evidence.reminder','Nachweis in 60 Minuten','Für Auftrag '.$w['order_no'].' beginnt das Zeitfenster „'.$w['window_key'].'“ in etwa 60 Minuten.','/auftrag/'.$w['order_no'],'window-'.$w['id'].'-60m');
    if($diffStart<=900 && $diffStart>600) notify_seller((int)$w['seller_id'],'evidence.reminder','Nachweis in 15 Minuten','Für Auftrag '.$w['order_no'].' beginnt das Zeitfenster „'.$w['window_key'].'“ in etwa 15 Minuten.','/auftrag/'.$w['order_no'],'window-'.$w['id'].'-15m');
    if($nowTs>=$startTs && $nowTs<$startTs+300) notify_seller((int)$w['seller_id'],'evidence.open','Nachweisfenster geöffnet','Das Zeitfenster „'.$w['window_key'].'“ für Auftrag '.$w['order_no'].' ist jetzt geöffnet.','/auftrag/'.$w['order_no'],'window-'.$w['id'].'-open');

    if(strtotime($w['grace_ends_at']??$w['ends_at'])<$nowTs){
        $missing=max(0,(int)$w['required_count']-$submitted);
        $pdo->prepare("UPDATE evidence_windows SET status='missed' WHERE id=?")->execute([$w['id']]);
        for($i=1;$i<=$missing;$i++){
            cron_provisional_violation((int)$w['order_id'],(int)$w['seller_id'],'window-'.$w['id'].'-missing-'.$i,'missing_evidence','Pflichtnachweis fehlt: Tag '.$w['day_no'].' / '.$w['window_key'].' ('.$i.'/'.$missing.').');
        }
    }
}

/* Spontane Nachweise: Halbzeit-/Enderinnerung und fehlende Bilder einzeln als mögliche Verstöße. */
$spontaneous=$pdo->query("SELECT sr.*,o.seller_id,o.order_no FROM spontaneous_requests sr JOIN orders o ON o.id=sr.order_id WHERE sr.status NOT IN('reviewed','missed') AND o.status IN('running','shipping','review')")->fetchAll();
foreach($spontaneous as $r){
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM evidences WHERE source_type='spontaneous' AND source_id=? AND status<>'rejected'");$cnt->execute([$r['id']]);$submitted=(int)$cnt->fetchColumn();
    if($submitted>=(int)$r['required_count']){
        $pdo->prepare("UPDATE spontaneous_requests SET status='uploaded' WHERE id=?")->execute([$r['id']]);
        continue;
    }
    $created=strtotime($r['created_at']);$due=strtotime($r['due_at']);$nowTs=$now->getTimestamp();$mid=$created+(int)(($due-$created)/2);
    if($nowTs>=$mid && $nowTs<$due) notify_seller((int)$r['seller_id'],'spontaneous.reminder','Spontaner Nachweis noch offen','Der spontane Nachweis für Auftrag '.$r['order_no'].' ist noch offen.','/auftrag/'.$r['order_no'].'/spontan/'.$r['id'],'spontaneous-'.$r['id'].'-half');
    if($due-$nowTs<=900 && $due-$nowTs>0) notify_seller((int)$r['seller_id'],'spontaneous.reminder','Spontaner Nachweis bald fällig','Der spontane Nachweis für Auftrag '.$r['order_no'].' ist in weniger als 15 Minuten fällig.','/auftrag/'.$r['order_no'].'/spontan/'.$r['id'],'spontaneous-'.$r['id'].'-soon');
    if(strtotime($r['grace_ends_at'])<$nowTs){
        $missing=max(0,(int)$r['required_count']-$submitted);
        $pdo->prepare("UPDATE spontaneous_requests SET status='missed' WHERE id=?")->execute([$r['id']]);
        for($i=1;$i<=$missing;$i++) cron_provisional_violation((int)$r['order_id'],(int)$r['seller_id'],'spontaneous-'.$r['id'].'-missing-'.$i,'spontaneous_missing','Spontaner Nachweis fehlt ('.$i.'/'.$missing.').');
    }
}

/* Zusatzaufgaben: die gesamte Aufgabe zählt bei Nichterfüllung höchstens als ein möglicher Verstoß. */
$tasks=$pdo->query("SELECT t.*,o.seller_id,o.order_no FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE t.status='open' AND t.due_at IS NOT NULL AND t.violation_enabled=1")->fetchAll();
foreach($tasks as $t){
    $deadline=(new DateTimeImmutable($t['due_at']))->modify('+'.$grace.' minutes');
    if($deadline<$now){
        cron_provisional_violation((int)$t['order_id'],(int)$t['seller_id'],'task-'.$t['id'].'-missed','task_missing','Zusatzaufgabe „'.$t['title'].'“ wurde nicht fristgerecht eingereicht.');
    }
}

/* Genehmigte Vorabkontrollen am vereinbarten Startdatum starten. */
$startable=$pdo->prepare("SELECT id FROM orders WHERE status='precheck' AND precheck_approved_at IS NOT NULL AND planned_start_date IS NOT NULL AND planned_start_date<=?");
$startable->execute([$now->format('Y-m-d')]);
foreach($startable->fetchAll() as $row){
    start_order_on_planned_date((int)$row['id'],$now);
}

/* Auftragstage abschließen und bei vollständig erledigter Durchführung in den Versand wechseln. */
$runningOrders=$pdo->query("SELECT id FROM orders WHERE status='running'")->fetchAll();
foreach($runningOrders as $row){
    $orderId=(int)$row['id'];
    $days=$pdo->prepare("SELECT * FROM order_days WHERE order_id=? AND status IN('planned','active') ORDER BY day_no");
    $days->execute([$orderId]);
    foreach($days->fetchAll() as $day){
        $w=$pdo->prepare("SELECT status,grace_ends_at FROM evidence_windows WHERE order_id=? AND order_run_id<=>? AND day_no=?");
        $w->execute([$orderId,$day['order_run_id'],$day['day_no']]);
        $windowsForDay=$w->fetchAll();
        $terminal=true;$missed=false;
        if($windowsForDay){
            foreach($windowsForDay as $win){
                if(in_array($win['status'],['planned','open'],true)){$terminal=false;break;}
                if($win['status']==='missed')$missed=true;
            }
        }else{
            $dayEnd=new DateTimeImmutable($day['calendar_date'].' 23:59:59',new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
            $terminal=$dayEnd<$now;
        }
        if($terminal){
            $pdo->prepare("UPDATE order_days SET status=? WHERE id=?")->execute([$missed?'missed':'completed',$day['id']]);
        }else{
            $pdo->prepare("UPDATE order_days SET status='active' WHERE id=? AND calendar_date<=?")->execute([$day['id'],$now->format('Y-m-d')]);
        }
    }
    advance_order_to_shipping_if_ready($orderId);
}

/* Versandschritt-Fristen: ein unvollständiger Schritt zählt als ein möglicher Verstoß. */
$shippingSteps=$pdo->query("SELECT st.*,o.seller_id,o.order_no FROM order_shipping_steps st JOIN orders o ON o.id=st.order_id WHERE st.status='open' AND st.due_at IS NOT NULL AND o.status='shipping'")->fetchAll();
foreach($shippingSteps as $step){
    $due=new DateTimeImmutable($step['due_at'],new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
    $graceEnd=$due->modify('+'.$grace.' minutes');
    $diff=$due->getTimestamp()-$now->getTimestamp();

    if($diff<=3600 && $diff>3300){
        notify_seller((int)$step['seller_id'],'shipping.reminder','Versandschritt in 60 Minuten fällig','Der Versandschritt „'.$step['title'].'“ in Auftrag '.$step['order_no'].' ist in etwa 60 Minuten fällig.','/auftrag/'.$step['order_no'].'/versand','shipping-step-'.$step['id'].'-60m');
    }
    if($diff<=900 && $diff>600){
        notify_seller((int)$step['seller_id'],'shipping.reminder','Versandschritt bald fällig','Der Versandschritt „'.$step['title'].'“ in Auftrag '.$step['order_no'].' ist in etwa 15 Minuten fällig.','/auftrag/'.$step['order_no'].'/versand','shipping-step-'.$step['id'].'-15m');
    }
    if($graceEnd<$now){
        cron_provisional_violation((int)$step['order_id'],(int)$step['seller_id'],'shipping-step-'.$step['id'].'-missed','shipping_requirement','Versandschritt „'.$step['title'].'“ wurde nicht fristgerecht abgeschlossen.');
    }
}

/* Individuelle Angebote: 24h / 1h Erinnerung und automatisches Ablaufen. */
$assignments=$pdo->query("SELECT a.*,o.title,s.email FROM offer_assignments a JOIN offers o ON o.id=a.offer_id JOIN sellers s ON s.id=a.seller_id WHERE a.status='assigned'")->fetchAll();
foreach($assignments as $a){
    $deadline=strtotime($a['acceptance_deadline']);$diff=$deadline-$now->getTimestamp();
    if($diff<=0){
        $pdo->prepare("UPDATE offer_assignments SET status='expired',updated_at=NOW() WHERE id=? AND status='assigned'")->execute([$a['id']]);
        notify_seller((int)$a['seller_id'],'private_offer.expired','Individuelles Angebot abgelaufen','Das individuelle Angebot „'.$a['title'].'“ wurde nicht innerhalb der Annahmefrist angenommen.','/individuelle-angebote','private-offer-'.$a['id'].'-expired',true);
        continue;
    }
    if($diff<=3600 && !$a['reminded_1h_at']){
        notify_seller((int)$a['seller_id'],'private_offer.reminder','Individuelles Angebot läuft bald ab','Das Angebot „'.$a['title'].'“ läuft in weniger als einer Stunde ab.','/individuelle-angebote','private-offer-'.$a['id'].'-1h',true);
        $pdo->prepare("UPDATE offer_assignments SET reminded_1h_at=NOW() WHERE id=?")->execute([$a['id']]);
    }elseif($diff<=86400 && $diff>3600 && !$a['reminded_24h_at']){
        notify_seller((int)$a['seller_id'],'private_offer.reminder','Individuelles Angebot läuft morgen ab','Das Angebot „'.$a['title'].'“ läuft innerhalb der nächsten 24 Stunden ab.','/individuelle-angebote','private-offer-'.$a['id'].'-24h',true);
        $pdo->prepare("UPDATE offer_assignments SET reminded_24h_at=NOW() WHERE id=?")->execute([$a['id']]);
    }
}

echo '['.date('c')."] cron ok\n";

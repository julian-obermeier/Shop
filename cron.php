<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require __DIR__.'/app/Core.php';

$pdo=db();
$cronLock='shop-v1-cron-'.substr(hash('sha256',(string)app_config('app.url','default')),0,24);
$lockStmt=$pdo->prepare('SELECT GET_LOCK(?,0)');
$lockStmt->execute([$cronLock]);
if((int)$lockStmt->fetchColumn()!==1){
    echo '['.date('c')."] cron skipped: another run is active\n";
    exit;
}
register_shutdown_function(static function() use ($pdo,$cronLock): void {
    try{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$cronLock]);}catch(Throwable){}
});

$now=new DateTimeImmutable('now',new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
$nowSql=$now->format('Y-m-d H:i:s');
$grace=max(0,(int)setting_value('grace_minutes','60'));

$pdo->prepare("DELETE FROM email_verifications WHERE expires_at < ?")->execute([$nowSql]);
$pdo->prepare("DELETE FROM password_resets WHERE expires_at < ? OR used_at IS NOT NULL")->execute([$nowSql]);
$pdo->prepare("DELETE FROM rate_limits WHERE (blocked_until IS NULL AND window_started_at < DATE_SUB(?,INTERVAL 2 DAY)) OR (blocked_until IS NOT NULL AND blocked_until < DATE_SUB(?,INTERVAL 1 DAY))")->execute([$nowSql,$nowSql]);

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



function cron_configured_violation(
    int $orderId,
    int $sellerId,
    string $sourceKey,
    string $type,
    string $reason,
    int $extensionDays,
    string $link
): void {
    $q=db()->prepare("SELECT id FROM violations WHERE source_key=? LIMIT 1");
    $q->execute([$sourceKey]);
    if($q->fetchColumn()) return;

    $extensionDays=max(0,$extensionDays);
    db()->beginTransaction();
    try{
        db()->prepare("INSERT INTO violations(order_id,violation_type,source_key,status,reason,extension_days) VALUES(?,?,?,'open',?,?)")
          ->execute([$orderId,$type,$sourceKey,$reason,$extensionDays]);
        $violationId=(int)db()->lastInsertId();

        if($extensionDays>0){
            db()->prepare("INSERT INTO extra_days(order_id,source_type,source_id,status,paid,amount,reason) VALUES(?,'violation',?,'provisional',0,0,?)")
              ->execute([$orderId,$violationId,$reason]);
        }
        db()->commit();

        $effect=$extensionDays>0
          ? 'Bei Bestätigung wird die digitale Bearbeitungsfrist um '.$extensionDays.' Tag'.($extensionDays===1?'':'e').' verlängert.'
          : 'Bei Bestätigung wird die Fristüberschreitung nur protokolliert; es entsteht kein zusätzlicher Tag.';
        notify_seller(
            $sellerId,
            'violation.open',
            'Digitale Fristüberschreitung wird geprüft',
            $reason.' '.$effect,
            $link,
            'violation-'.$sourceKey,
            true
        );
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        throw $e;
    }
}

function cron_log_only_deadline(int $orderId, int $sellerId, string $sourceKey, string $eventType, string $reason, string $link): void {
    $q=db()->prepare("SELECT COUNT(*) FROM system_events WHERE order_id=? AND event_type=? AND payload_json LIKE ?");
    $q->execute([$orderId,$eventType,'%"source_key":"'.$sourceKey.'"%']);
    if((int)$q->fetchColumn()>0) return;
    log_event($eventType,$sellerId,$orderId,['source_key'=>$sourceKey,'reason'=>$reason]);
    notify_seller($sellerId,$eventType,'Digitale Frist überschritten',$reason,$link,'deadline-'.$sourceKey,true);
}

/* Zeitfenster öffnen und bereits vollständig belegte Fenster abschließen. */
$pdo->prepare("UPDATE evidence_windows ew
               JOIN orders o ON o.id=ew.order_id
               SET ew.status='open'
               WHERE ew.status='planned'
                 AND o.status='running'
                 AND ew.order_run_id=(SELECT r.id FROM order_runs r WHERE r.order_id=o.id ORDER BY r.run_no DESC LIMIT 1)
                 AND ew.starts_at<=?
                 AND ew.ends_at>=?")
    ->execute([$nowSql,$nowSql]);

$windows=$pdo->query("SELECT ew.*,o.seller_id,o.order_no
                     FROM evidence_windows ew
                     JOIN orders o ON o.id=ew.order_id
                     WHERE ew.status IN('planned','open')
                       AND o.status='running'
                       AND ew.order_run_id=(SELECT r.id FROM order_runs r WHERE r.order_id=o.id ORDER BY r.run_no DESC LIMIT 1)")->fetchAll();
foreach($windows as $w){
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM evidences WHERE order_id=? AND order_run_id<=>? AND evidence_type='daily' AND day_no=? AND window_key=? AND status<>'rejected'");
    $cnt->execute([$w['order_id'],$w['order_run_id'],$w['day_no'],$w['window_key']]);$submitted=(int)$cnt->fetchColumn();
    if($submitted>=(int)$w['required_count']){
        $pdo->prepare("UPDATE evidence_windows SET status='submitted' WHERE id=?")->execute([$w['id']]);
        continue;
    }

    $startTs=strtotime($w['starts_at']);$endTs=strtotime($w['ends_at']);$nowTs=$now->getTimestamp();
    $diffStart=$startTs-$nowTs;$diffEnd=$endTs-$nowTs;

    if($diffStart>900 && $diffStart<=3600) notify_seller((int)$w['seller_id'],'evidence.reminder','Nachweis in weniger als 60 Minuten','Für Auftrag '.$w['order_no'].' beginnt das Zeitfenster „'.$w['window_key'].'“ innerhalb der nächsten 60 Minuten.','/auftrag/'.$w['order_no'],'window-'.$w['id'].'-60m');
    if($diffStart>0 && $diffStart<=900) notify_seller((int)$w['seller_id'],'evidence.reminder','Nachweis in weniger als 15 Minuten','Für Auftrag '.$w['order_no'].' beginnt das Zeitfenster „'.$w['window_key'].'“ innerhalb der nächsten 15 Minuten.','/auftrag/'.$w['order_no'],'window-'.$w['id'].'-15m');
    if($nowTs>=$startTs && $nowTs<=$endTs) notify_seller((int)$w['seller_id'],'evidence.open','Nachweisfenster geöffnet','Das Zeitfenster „'.$w['window_key'].'“ für Auftrag '.$w['order_no'].' ist jetzt geöffnet.','/auftrag/'.$w['order_no'],'window-'.$w['id'].'-open');

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

/* Neuaufnahmen nach Beanstandungen: Fristablauf überwachen. */
$retakes=$pdo->query("SELECT r.*,o.order_no FROM evidence_retake_requests r JOIN orders o ON o.id=r.order_id WHERE r.status='requested'")->fetchAll();
foreach($retakes as $r){
    if(strtotime($r['grace_ends_at']) < $now->getTimestamp()){
        $violationId=$r['violation_id'] ? (int)$r['violation_id'] : null;
        if(!$violationId){
            $violationId=ensure_provisional_violation(
                (int)$r['order_id'],
                'retake-'.$r['id'].'-missed',
                'retake_missing',
                'Angeforderte Neuaufnahme wurde nicht fristgerecht eingereicht.'
            );
            $pdo->prepare("UPDATE evidence_retake_requests SET violation_id=? WHERE id=?")->execute([$violationId,$r['id']]);
        }
        $pdo->prepare("UPDATE evidence_retake_requests SET status='missed',reviewed_at=NOW() WHERE id=? AND status='requested'")->execute([$r['id']]);
        notify_seller(
            (int)$r['seller_id'],
            'evidence.retake_missed',
            'Neuaufnahme nicht fristgerecht eingereicht',
            'Die Frist einschließlich Nachfrist für eine angeforderte Neuaufnahme in Auftrag '.$r['order_no'].' ist abgelaufen.',
            '/auftrag/'.$r['order_no'],
            'retake-'.$r['id'].'-missed',
            true
        );
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


/* Digitale Erstabgabe und Revisionen: individuelle Frist + Nachfrist überwachen. */
$digitalOrders=$pdo->query("SELECT o.* FROM orders o
    WHERE o.digital_due_at IS NOT NULL
      AND o.status IN('running','review')
      AND NOT EXISTS (SELECT 1 FROM digital_versions d WHERE d.order_id=o.id)")->fetchAll();
foreach($digitalOrders as $o){
    $rules=offer_digital_rules((int)$o['id']);
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    $due=new DateTimeImmutable($o['digital_due_at'],$tz);
    $graceEnd=$due->modify('+'.max(0,(int)$rules['deadline']['grace_minutes']).' minutes');
    $diff=$due->getTimestamp()-$now->getTimestamp();

    if($diff>0 && $diff<=3600){
        notify_seller((int)$o['seller_id'],'digital.deadline_reminder','Digitale Erstabgabe in weniger als 1 Stunde fällig','Die digitale Erstabgabe für Auftrag '.$o['order_no'].' ist bis '.$due->format('d.m.Y H:i').' fällig.','/auftrag/'.$o['order_no'].'/digital','digital-initial-'.$o['id'].'-1h',true);
    }elseif($diff>3600 && $diff<=86400){
        notify_seller((int)$o['seller_id'],'digital.deadline_reminder','Digitale Erstabgabe innerhalb von 24 Stunden fällig','Die digitale Erstabgabe für Auftrag '.$o['order_no'].' ist bis '.$due->format('d.m.Y H:i').' fällig.','/auftrag/'.$o['order_no'].'/digital','digital-initial-'.$o['id'].'-24h',true);
    }

    if($now>=$due && $now<=$graceEnd){
        notify_seller((int)$o['seller_id'],'digital.deadline_grace','Nachfrist für digitale Erstabgabe läuft','Die reguläre Frist ist abgelaufen. Die Nachfrist endet am '.$graceEnd->format('d.m.Y H:i').'.','/auftrag/'.$o['order_no'].'/digital','digital-initial-'.$o['id'].'-grace-'.$due->getTimestamp(),true);
        continue;
    }
    if($graceEnd >= $now) continue;

    $extension=(($rules['deadline']['violation_effect']??'log_only')==='extension_day')?1:0;
    $source='digital-initial-'.$o['id'].'-deadline-'.$due->getTimestamp();
    $reason='Die digitale Erstabgabe für Auftrag '.$o['order_no'].' wurde nicht innerhalb der Frist einschließlich Nachfrist eingereicht.';
    cron_configured_violation((int)$o['id'],(int)$o['seller_id'],$source,'digital_deadline',$reason,$extension,'/auftrag/'.$o['order_no'].'/digital');
}

$digitalRevisions=$pdo->query("SELECT r.*,o.seller_id,o.order_no
    FROM revision_rounds r
    JOIN orders o ON o.id=r.order_id
    WHERE r.status='open' AND r.due_at IS NOT NULL AND o.status IN('running','review')")->fetchAll();
foreach($digitalRevisions as $r){
    $rules=offer_digital_rules((int)$r['order_id']);
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    $due=new DateTimeImmutable($r['due_at'],$tz);
    $graceEnd=$r['grace_ends_at']
        ? new DateTimeImmutable($r['grace_ends_at'],$tz)
        : $due->modify('+'.max(0,(int)$rules['revision']['grace_minutes']).' minutes');
    $diff=$due->getTimestamp()-$now->getTimestamp();

    if($diff>0 && $diff<=3600){
        notify_seller((int)$r['seller_id'],'digital.revision_reminder','Revision in weniger als 1 Stunde fällig','Revision '.$r['round_no'].' für Auftrag '.$r['order_no'].' ist bis '.$due->format('d.m.Y H:i').' fällig.','/auftrag/'.$r['order_no'].'/digital','digital-revision-'.$r['id'].'-1h-'.$due->getTimestamp(),true);
    }elseif($diff>3600 && $diff<=86400){
        notify_seller((int)$r['seller_id'],'digital.revision_reminder','Revision innerhalb von 24 Stunden fällig','Revision '.$r['round_no'].' für Auftrag '.$r['order_no'].' ist bis '.$due->format('d.m.Y H:i').' fällig.','/auftrag/'.$r['order_no'].'/digital','digital-revision-'.$r['id'].'-24h-'.$due->getTimestamp(),true);
    }

    if($now>=$due && $now<=$graceEnd){
        notify_seller((int)$r['seller_id'],'digital.revision_grace','Nachfrist für Revision läuft','Die reguläre Revisionsfrist ist abgelaufen. Die Nachfrist endet am '.$graceEnd->format('d.m.Y H:i').'.','/auftrag/'.$r['order_no'].'/digital','digital-revision-'.$r['id'].'-grace-'.$due->getTimestamp(),true);
        continue;
    }
    if($graceEnd >= $now) continue;

    $extension=(($rules['revision']['violation_effect']??'log_only')==='extension_day')?1:0;
    $source='digital-revision-'.$r['id'].'-deadline-'.$due->getTimestamp();
    $reason='Die Revision '.$r['round_no'].' für Auftrag '.$r['order_no'].' wurde nicht innerhalb der Frist einschließlich Nachfrist eingereicht.';
    cron_configured_violation((int)$r['order_id'],(int)$r['seller_id'],$source,'digital_revision_deadline',$reason,$extension,'/auftrag/'.$r['order_no'].'/digital');
}

/* Genehmigte Vorabkontrollen am vereinbarten Startdatum starten. */
$startable=$pdo->prepare("SELECT id FROM orders WHERE status='precheck' AND precheck_approved_at IS NOT NULL AND planned_start_date IS NOT NULL AND planned_start_date<=?");
$startable->execute([$now->format('Y-m-d')]);
foreach($startable->fetchAll() as $row){
    $orderId=(int)$row['id'];
    start_order_on_planned_date($orderId,$now);
    $pdo->prepare("UPDATE order_components SET status='execution',updated_at=NOW() WHERE order_id=? AND status='preparation'")
        ->execute([$orderId]);
}

/* Auftragstage abschließen und bei vollständig erledigter Durchführung in den Versand wechseln. */
$runningOrders=$pdo->query("SELECT id FROM orders WHERE status='running'")->fetchAll();
foreach($runningOrders as $row){
    $orderId=(int)$row['id'];
    $runId=current_run_id($orderId);
    if(!$runId) continue;
    $days=$pdo->prepare("SELECT * FROM order_days WHERE order_id=? AND order_run_id=? AND status IN('planned','active') ORDER BY day_no");
    $days->execute([$orderId,$runId]);
    foreach($days->fetchAll() as $day){
        $w=$pdo->prepare("SELECT status,grace_ends_at FROM evidence_windows WHERE order_id=? AND order_run_id=? AND day_no=?");
        $w->execute([$orderId,$runId,$day['day_no']]);
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
    $interval=max(0,(int)setting_value('interim_summary_interval','7'));
    if($interval>0){
        $cq=$pdo->prepare("SELECT COUNT(*) FROM order_days WHERE order_id=? AND status IN('completed','missed')");
        $cq->execute([$orderId]);$completedDays=(int)$cq->fetchColumn();
        if($completedDays>0 && $completedDays%$interval===0){
            $exists=$pdo->prepare("SELECT COUNT(*) FROM order_interim_summaries WHERE order_id=? AND completed_days=?");
            $exists->execute([$orderId,$completedDays]);
            if((int)$exists->fetchColumn()===0){
                $snapshot=build_interim_summary($orderId,$completedDays);
                $pdo->prepare("INSERT INTO order_interim_summaries(order_id,completed_days,snapshot_json) VALUES(?,?,?)")
                    ->execute([$orderId,$completedDays,json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);
                $oq=$pdo->prepare("SELECT seller_id,order_no FROM orders WHERE id=?");$oq->execute([$orderId]);$ord=$oq->fetch();
                if($ord){
                    notify_seller(
                        (int)$ord['seller_id'],
                        'order.interim_summary',
                        'Neuer Zwischenstand',
                        'Für Auftrag '.$ord['order_no'].' wurde nach '.$completedDays.' abgeschlossenen Tagen ein neuer Zwischenstand erstellt.',
                        '/auftrag/'.$ord['order_no'].'/zwischenstaende',
                        'interim-'.$orderId.'-'.$completedDays,
                        false
                    );
                }
            }
        }
    }
    advance_order_to_shipping_if_ready($orderId);
    $statusQ=$pdo->prepare("SELECT status FROM orders WHERE id=?");$statusQ->execute([$orderId]);$currentStatus=$statusQ->fetchColumn();
    if($currentStatus==='shipping'){
        $pdo->prepare("UPDATE order_components SET status='shipping',updated_at=NOW() WHERE order_id=? AND component_type='physical' AND status='execution'")
            ->execute([$orderId]);
    }
}

/* Versandschritt-Fristen: ein unvollständiger Schritt zählt als ein möglicher Verstoß. */
$shippingSteps=$pdo->query("SELECT st.*,o.seller_id,o.order_no FROM order_shipping_steps st JOIN orders o ON o.id=st.order_id WHERE st.status='open' AND st.due_at IS NOT NULL AND o.status='shipping'")->fetchAll();
foreach($shippingSteps as $step){
    $due=new DateTimeImmutable($step['due_at'],new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
    $graceEnd=$due->modify('+'.$grace.' minutes');
    $diff=$due->getTimestamp()-$now->getTimestamp();

    if($diff>900 && $diff<=3600){
        notify_seller((int)$step['seller_id'],'shipping.reminder','Versandschritt in weniger als 60 Minuten fällig','Der Versandschritt „'.$step['title'].'“ in Auftrag '.$step['order_no'].' ist innerhalb der nächsten 60 Minuten fällig.','/auftrag/'.$step['order_no'].'/versand','shipping-step-'.$step['id'].'-60m');
    }
    if($diff>0 && $diff<=900){
        notify_seller((int)$step['seller_id'],'shipping.reminder','Versandschritt in weniger als 15 Minuten fällig','Der Versandschritt „'.$step['title'].'“ in Auftrag '.$step['order_no'].' ist innerhalb der nächsten 15 Minuten fällig.','/auftrag/'.$step['order_no'].'/versand','shipping-step-'.$step['id'].'-15m');
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

<?php
declare(strict_types=1);
require __DIR__.'/app/Core.php';

$pdo=db();
$tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
$nowObj=new DateTimeImmutable('now',$tz);
$now=$nowObj->format('Y-m-d H:i:s');

$pdo->prepare("DELETE FROM email_verifications WHERE expires_at < ?")->execute([$now]);
$pdo->prepare("DELETE FROM password_resets WHERE expires_at < ? OR used_at IS NOT NULL")->execute([$now]);

// Reguläre Nachweisfenster öffnen, erinnern und abschließen.
$q=$pdo->query("SELECT ew.*,o.order_no,o.seller_id,o.status order_status
 FROM evidence_windows ew
 JOIN orders o ON o.id=ew.order_id
 WHERE o.status='running' AND ew.status IN('planned','open','submitted')");
$windows=$q->fetchAll();

foreach($windows as $w){
    $windowId=(int)$w['id'];
    $orderId=(int)$w['order_id'];
    $sellerId=(int)$w['seller_id'];
    $required=(int)$w['required_count'];

    $count=$pdo->prepare("SELECT COUNT(*) FROM evidences
      WHERE order_id=? AND order_run_id <=> ? AND evidence_type='daily'
      AND day_no=? AND window_key=? AND status IN('submitted','accepted')");
    $count->execute([$orderId,$w['order_run_id'],$w['day_no'],$w['window_key']]);
    $submitted=(int)$count->fetchColumn();

    if($submitted >= $required){
        if($w['status']!=='submitted') $pdo->prepare("UPDATE evidence_windows SET status='submitted' WHERE id=?")->execute([$windowId]);
        continue;
    }

    $start=new DateTimeImmutable($w['starts_at'],$tz);
    $end=new DateTimeImmutable($w['ends_at'],$tz);
    $grace=new DateTimeImmutable($w['grace_ends_at']?:$w['ends_at'],$tz);

    if($nowObj >= $start && $nowObj <= $grace && $w['status']==='planned'){
        $pdo->prepare("UPDATE evidence_windows SET status='open' WHERE id=?")->execute([$windowId]);
        notify_seller($sellerId,'evidence.window.open','Nachweisfenster geöffnet',
            'Für Auftrag '.$w['order_no'].' ist das Nachweisfenster '.window_label($w['window_key']).' geöffnet.',
            '/auftrag/'.$w['order_no'],'window-open-'.$windowId,true);
    }

    foreach([60=>'60 Minuten',15=>'15 Minuten'] as $minutes=>$label){
        $threshold=$end->modify('-'.$minutes.' minutes');
        if($nowObj >= $threshold && $nowObj <= $end){
            notify_seller($sellerId,'evidence.window.reminder','Nachweis bald fällig',
                'Für Auftrag '.$w['order_no'].' fehlen noch '.max(0,$required-$submitted).' Nachweis(e) im Fenster '.window_label($w['window_key']).'. Ende: '.$end->format('H:i').' Uhr.',
                '/auftrag/'.$w['order_no'],'window-reminder-'.$minutes.'-'.$windowId,true);
        }
    }

    if($nowObj > $end && $nowObj <= $grace){
        notify_seller($sellerId,'evidence.window.grace','Nachfrist läuft',
            'Das reguläre Fenster '.window_label($w['window_key']).' ist beendet. Die Nachfrist läuft bis '.$grace->format('H:i').' Uhr.',
            '/auftrag/'.$w['order_no'],'window-grace-'.$windowId,true);
    }

    if($nowObj > $grace && $submitted < $required){
        $missing=$required-$submitted;
        $pdo->prepare("UPDATE evidence_windows SET status='missed' WHERE id=?")->execute([$windowId]);

        for($n=1;$n<=$missing;$n++){
            $sourceKey='window:'.$windowId.':missing:'.$n;
            $ins=$pdo->prepare("INSERT IGNORE INTO violations(order_id,violation_type,status,reason,source_key,extension_days)
              VALUES(?,'missing_evidence','open',?,?,1)");
            $reason='Fehlender Nachweis – Tag '.$w['day_no'].' / '.window_label($w['window_key']);
            $ins->execute([$orderId,$reason,$sourceKey]);
            if($ins->rowCount()>0){
                $violationId=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO extra_days(order_id,source_type,source_id,paid,amount,reason)
                  VALUES(?,'violation',?,0,0,?)")->execute([$orderId,$violationId,$reason]);
                $extraDayId=(int)$pdo->lastInsertId();
                schedule_extra_day($extraDayId);
                log_event('violation.provisional',$sellerId,$orderId,['violation_id'=>$violationId,'window_id'=>$windowId,'source_key'=>$sourceKey]);
            }
        }

        notify_seller($sellerId,'evidence.window.missed','Nachweisfrist verpasst',
            'Für Auftrag '.$w['order_no'].' fehlen im Fenster '.window_label($w['window_key']).' '.$missing.' Pflichtnachweis(e). Die möglichen Verstöße warten auf Adminprüfung.',
            '/auftrag/'.$w['order_no'],'window-missed-'.$windowId,true);
        $pdo->prepare("INSERT INTO chat_messages(order_id,sender_type,message)
          SELECT ?,'system',? WHERE NOT EXISTS(
            SELECT 1 FROM system_events WHERE order_id=? AND event_type=? AND payload_json LIKE ?
          )")->execute([$orderId,
            'Nachweisfenster '.window_label($w['window_key']).' an Tag '.$w['day_no'].' verpasst: '.$missing.' mögliche(r) Verstoß/Verstöße.',
            $orderId,'window.missed','%"window_id":'.$windowId.'%']);
        log_event('window.missed',$sellerId,$orderId,['window_id'=>$windowId,'missing'=>$missing]);
    }
}

// Tage aus den zugehörigen Fenstern ableiten.
$days=$pdo->query("SELECT d.id,d.order_id,d.order_run_id,d.day_no,
 SUM(w.status IN('planned','open')) open_count,
 COUNT(w.id) window_count
 FROM order_days d
 LEFT JOIN evidence_windows w ON w.order_id=d.order_id AND w.order_run_id<=>d.order_run_id AND w.day_no=d.day_no
 JOIN orders o ON o.id=d.order_id
 WHERE o.status='running'
 GROUP BY d.id")->fetchAll();
foreach($days as $d){
    if((int)$d['window_count']===0) continue;
    if((int)$d['open_count']===0){
        $pdo->prepare("UPDATE order_days SET status='completed' WHERE id=?")->execute([$d['id']]);
    } else {
        $pdo->prepare("UPDATE order_days SET status=CASE WHEN calendar_date=CURDATE() THEN 'active' ELSE status END WHERE id=?")->execute([$d['id']]);
    }
}

// Ist der aktuelle Durchlauf vollständig beendet, physische Aufträge in Versand überführen.
$running=$pdo->query("SELECT o.id,o.order_no,o.seller_id,f.fulfillment_type,
 r.id run_id
 FROM orders o JOIN offers f ON f.id=o.offer_id
 JOIN order_runs r ON r.id=(SELECT rr.id FROM order_runs rr WHERE rr.order_id=o.id ORDER BY rr.run_no DESC LIMIT 1)
 WHERE o.status='running'")->fetchAll();
foreach($running as $o){
    $p=$pdo->prepare("SELECT COUNT(*) FROM order_days WHERE order_id=? AND order_run_id=? AND status<>'completed'");
    $p->execute([$o['id'],$o['run_id']]);$open=(int)$p->fetchColumn();
    $all=$pdo->prepare("SELECT COUNT(*) FROM order_days WHERE order_id=? AND order_run_id=?");
    $all->execute([$o['id'],$o['run_id']]);$allCount=(int)$all->fetchColumn();
    if($allCount>0 && $open===0 && $o['fulfillment_type']!=='digital'){
        $pdo->prepare("UPDATE orders SET status='shipping',updated_at=? WHERE id=? AND status='running'")->execute([$now,$o['id']]);
        notify_seller((int)$o['seller_id'],'order.shipping','Durchführung abgeschlossen',
            'Die Durchführung von Auftrag '.$o['order_no'].' ist abgeschlossen. Bitte führe jetzt den Versandworkflow aus.',
            '/auftrag/'.$o['order_no'].'/versand','order-shipping-'.$o['id'],true);
        $pdo->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Alle Durchführungstage abgeschlossen. Versandphase gestartet.')")->execute([$o['id']]);
    }
}

// Spontane Fotoanforderungen: Frist + 1h Grace; jedes fehlende Foto = möglicher Verstoß.
$sp=$pdo->query("SELECT r.*,o.order_no,o.seller_id FROM spontaneous_requests r JOIN orders o ON o.id=r.order_id
 WHERE o.status='running' AND r.status NOT IN('reviewed','missed')")->fetchAll();
foreach($sp as $r){
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM evidences WHERE order_id=? AND evidence_type='spontaneous'
      AND reference_type='spontaneous_request' AND reference_id=? AND status IN('submitted','accepted')");
    $cnt->execute([$r['order_id'],$r['id']]);$submitted=(int)$cnt->fetchColumn();
    if($submitted >= (int)$r['required_count']){
        $pdo->prepare("UPDATE spontaneous_requests SET status='uploaded' WHERE id=?")->execute([$r['id']]);
        continue;
    }
    $due=new DateTimeImmutable($r['due_at'],$tz);
    $grace=new DateTimeImmutable($r['grace_ends_at']?:$r['due_at'],$tz);
    if($nowObj>$due && $nowObj<=$grace){
        notify_seller((int)$r['seller_id'],'spontaneous.grace','Nachfrist für spontanen Nachweis',
          'Für Auftrag '.$r['order_no'].' läuft die Nachfrist einer spontanen Fotoanforderung bis '.$grace->format('H:i').' Uhr.',
          '/auftrag/'.$r['order_no'],'spontaneous-grace-'.$r['id'],true);
    }
    if($nowObj>$grace){
        $missing=(int)$r['required_count']-$submitted;
        $pdo->prepare("UPDATE spontaneous_requests SET status='missed' WHERE id=?")->execute([$r['id']]);
        for($n=1;$n<=$missing;$n++){
            $sourceKey='spontaneous:'.$r['id'].':missing:'.$n;
            $ins=$pdo->prepare("INSERT IGNORE INTO violations(order_id,violation_type,status,reason,source_key,extension_days)
              VALUES(?,'spontaneous_missing','open',?,?,1)");
            $reason='Spontaner Nachweis nicht erfüllt';
            $ins->execute([$r['order_id'],$reason,$sourceKey]);
            if($ins->rowCount()>0){
                $vid=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO extra_days(order_id,source_type,source_id,paid,amount,reason) VALUES(?,'violation',?,0,0,?)")->execute([$r['order_id'],$vid,$reason]);
                schedule_extra_day((int)$pdo->lastInsertId());
            }
        }
        notify_seller((int)$r['seller_id'],'spontaneous.missed','Spontane Fotoanforderung verpasst',
          'Es fehlen '.$missing.' Foto(s). Die möglichen Verstöße warten auf Adminprüfung.',
          '/auftrag/'.$r['order_no'],'spontaneous-missed-'.$r['id'],true);
    }
}

echo '['.$nowObj->format(DATE_ATOM)."] cron ok; windows=".count($windows)."\n";

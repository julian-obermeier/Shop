<?php
declare(strict_types=1);

if (preg_match('#^/admin/auftrag/(\\d{8})/aendern$#',$path,$m) && $method==='GET') {
    require_admin();
    $q=db()->prepare("SELECT o.*,f.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id WHERE o.order_no=?");
    $q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    $h=db()->prepare("SELECT c.*,a.email admin_email FROM order_changes c JOIN admins a ON a.id=c.admin_id WHERE c.order_id=? ORDER BY c.created_at DESC,c.id DESC");
    $h->execute([$o['id']]);$changes=$h->fetchAll();

    $active=!$o['archived_at'] && in_array($o['status'],['precheck','running','shipping','review','payout'],true);
    $prestart=$active && $o['status']==='precheck' && empty($o['started_at']);
    $moneyEditable=$active && in_array($o['status'],['precheck','running','shipping','review'],true);

    ob_start();?>
    <div class="dashboard-head">
      <div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1>Auftragsdaten ändern</h1><p class="meta"><?=e($o['title'])?> · <?=e($o['seller_name'])?></p></div>
      <a class="btn secondary" href="<?=e(url('/admin/auftrag/'.$o['order_no']))?>">Zum Auftrag</a>
    </div>

    <?php if(!$active):?><div class="panel"><strong>Dieser Auftrag ist nicht mehr änderbar.</strong><p class="meta">Abgeschlossene, abgelehnte oder archivierte Aufträge bleiben historisch unverändert.</p></div><?php endif;?>

    <div class="grid two">
      <section class="panel">
        <h2>Vergütung</h2>
        <p>Grundvergütung: <strong><?=money($o['base_compensation'])?></strong><br>Aktueller Gesamtwert: <strong><?=money($o['total_compensation'])?></strong></p>
        <?php if($moneyEditable):?><form method="post">
          <?=csrf_field()?><input type="hidden" name="change_type" value="base_compensation">
          <label>Neue Grundvergütung (€)<input type="number" step=".01" min="0" name="new_value" value="<?=e($o['base_compensation'])?>" required></label>
          <label>Wirksam ab Durchführungstag (optional)<input type="number" min="1" name="effective_day_no"></label>
          <label>Grund der Änderung<textarea name="reason" required></textarea></label>
          <button class="btn">Vergütung ändern</button>
        </form><?php else:?><p class="meta">Die Vergütung kann in diesem Status nicht mehr geändert werden.</p><?php endif;?>
      </section>

      <section class="panel">
        <h2>Reguläre Dauer</h2>
        <p>Aktuell: <strong><?=e($o['duration_days']??'–')?> Tag(e)</strong></p>
        <?php if($prestart):?><form method="post">
          <?=csrf_field()?><input type="hidden" name="change_type" value="duration_days">
          <label>Neue reguläre Dauer<input type="number" min="1" name="new_value" value="<?=e($o['duration_days']??1)?>" required></label>
          <label>Grund der Änderung<textarea name="reason" required></textarea></label>
          <button class="btn">Dauer ändern</button>
        </form><?php else:?><p class="meta">Die reguläre Dauer wird nur vor dem tatsächlichen Start direkt geändert. Nach Start werden zusätzliche Tage über den vorgesehenen Zusatztag-/Verstoß-Workflow ergänzt.</p><?php endif;?>
      </section>

      <section class="panel">
        <h2>Geplanter Start</h2>
        <p>Aktuell: <strong><?=e($o['planned_start_date']?date('d.m.Y',strtotime($o['planned_start_date'])):'noch nicht festgelegt')?></strong></p>
        <?php if($prestart):?><form method="post">
          <?=csrf_field()?><input type="hidden" name="change_type" value="planned_start_date">
          <label>Neues Startdatum<input type="date" name="new_value" min="<?=e((new DateTimeImmutable('tomorrow'))->format('Y-m-d'))?>" value="<?=e($o['planned_start_date']??'')?>" required></label>
          <label>Grund der Änderung<textarea name="reason" required></textarea></label>
          <button class="btn">Startdatum ändern</button>
        </form><?php else:?><p class="meta">Das Startdatum kann nur vor dem tatsächlichen Start direkt geändert werden.</p><?php endif;?>
      </section>

      <section class="panel">
        <h2>Änderungsgrundsatz</h2>
        <p class="meta">Die ursprüngliche Auftragsbestätigung wird nicht überschrieben. Jede nachträgliche Änderung wird als eigener Historieneintrag mit Altwert, Neuwert, Grund, Admin und Zeitpunkt gespeichert und der Verkäuferin mitgeteilt.</p>
      </section>
    </div>

    <h2>Änderungshistorie</h2>
    <div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Feld</th><th>Alt</th><th>Neu</th><th>Wirksam ab</th><th>Grund</th><th>Admin</th></tr></thead><tbody>
    <?php foreach($changes as $x):?><tr>
      <td><?=e(date('d.m.Y H:i',strtotime($x['created_at'])))?></td>
      <td><?=e($x['field_name'])?></td>
      <td><?=e($x['old_value']??'–')?></td>
      <td><?=e($x['new_value']??'–')?></td>
      <td><?=e($x['effective_day_no']?'Tag '.$x['effective_day_no']:'sofort')?></td>
      <td><?=nl2br(e($x['reason']))?></td>
      <td><?=e($x['admin_email'])?></td>
    </tr><?php endforeach;?>
    <?php if(!$changes):?><tr><td colspan="7">Noch keine nachträglichen Auftragsänderungen.</td></tr><?php endif;?>
    </tbody></table></div>
    <?php render('Auftrag ändern',ob_get_clean());exit;
}

if (preg_match('#^/admin/auftrag/(\\d{8})/aendern$#',$path,$m) && $method==='POST') {
    $a=require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    if($o['archived_at'] || in_array($o['status'],['completed','rejected','archived'],true)){
        flash('error','Dieser Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no'].'/aendern');
    }

    $type=post('change_type');
    $reason=post('reason');
    $effective=post('effective_day_no')!==''?max(1,(int)post('effective_day_no')):null;
    if($reason===''){flash('error','Bitte einen Änderungsgrund angeben.');redirect('/admin/auftrag/'.$o['order_no'].'/aendern');}

    $oldValue='';$newValue='';$sellerText='';

    db()->beginTransaction();
    try{
        if($type==='base_compensation'){
            if(!in_array($o['status'],['precheck','running','shipping','review'],true)) throw new RuntimeException('Die Vergütung kann in diesem Status nicht mehr geändert werden.');
            $new=max(0,round((float)post('new_value'),2));
            $old=round((float)$o['base_compensation'],2);
            $delta=round($new-$old,2);
            if(abs($delta)<0.001) throw new RuntimeException('Der neue Wert entspricht dem bisherigen Wert.');

            db()->prepare("UPDATE orders SET base_compensation=?,total_compensation=total_compensation+?,updated_at=NOW() WHERE id=?")
              ->execute([$new,$delta,$o['id']]);
            db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Vergütungsänderung Auftrag')")
              ->execute([$o['seller_id'],$o['id'],$delta]);

            $oldValue=money($old);$newValue=money($new);
            $sellerText='Die Grundvergütung wurde von '.money($old).' auf '.money($new).' geändert. Der Gesamtwert wurde um '.money($delta).' angepasst.';
        }elseif($type==='duration_days'){
            if($o['status']!=='precheck' || !empty($o['started_at'])) throw new RuntimeException('Die reguläre Dauer kann nur vor dem tatsächlichen Start geändert werden.');
            $new=max(1,(int)post('new_value'));
            $old=(int)$o['duration_days'];
            if($new===$old) throw new RuntimeException('Der neue Wert entspricht dem bisherigen Wert.');
            db()->prepare("UPDATE orders SET duration_days=?,updated_at=NOW() WHERE id=?")->execute([$new,$o['id']]);
            $oldValue=$old.' Tag(e)';$newValue=$new.' Tag(e)';
            $sellerText='Die reguläre Auftragsdauer wurde von '.$old.' auf '.$new.' Tag(e) geändert.';
        }elseif($type==='planned_start_date'){
            if($o['status']!=='precheck' || !empty($o['started_at'])) throw new RuntimeException('Das Startdatum kann nur vor dem tatsächlichen Start geändert werden.');
            $raw=post('new_value');
            $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
            $newDate=DateTimeImmutable::createFromFormat('!Y-m-d',$raw,$tz);
            $tomorrow=new DateTimeImmutable('tomorrow',$tz);
            if(!$newDate || $newDate<$tomorrow) throw new RuntimeException('Das neue Startdatum muss frühestens morgen liegen.');
            $old=$o['planned_start_date']?:null;
            if($old===$newDate->format('Y-m-d')) throw new RuntimeException('Der neue Wert entspricht dem bisherigen Wert.');
            db()->prepare("UPDATE orders SET planned_start_date=?,updated_at=NOW() WHERE id=?")->execute([$newDate->format('Y-m-d'),$o['id']]);
            db()->prepare("UPDATE order_start_date_requests SET status='rejected',decided_at=NOW(),admin_note='Durch direkte Adminänderung ersetzt' WHERE order_id=? AND status='pending'")->execute([$o['id']]);
            $oldValue=$old?date('d.m.Y',strtotime($old)):'nicht festgelegt';$newValue=$newDate->format('d.m.Y');
            $sellerText='Das geplante Startdatum wurde von '.$oldValue.' auf '.$newValue.' geändert.';
            $effective=null;
        }else{
            throw new RuntimeException('Unbekannter Änderungstyp.');
        }

        db()->prepare("INSERT INTO order_changes(order_id,admin_id,field_name,old_value,new_value,reason,effective_day_no) VALUES(?,?,?,?,?,?,?)")
          ->execute([$o['id'],$a['id'],$type,$oldValue,$newValue,$reason,$effective]);
        db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
          ->execute([$o['id'],'Auftragsänderung: '.$sellerText.' Grund: '.$reason]);
        log_event('order.changed',(int)$o['seller_id'],(int)$o['id'],['field'=>$type,'old'=>$oldValue,'new'=>$newValue,'reason'=>$reason,'effective_day_no'=>$effective,'admin_id'=>(int)$a['id']]);
        db()->commit();
    }catch(Throwable $e){
        if(db()->inTransaction())db()->rollBack();
        flash('error',$e->getMessage());redirect('/admin/auftrag/'.$o['order_no'].'/aendern');
    }

    notify_seller((int)$o['seller_id'],'order.changed','Auftrag geändert',$sellerText.' Grund: '.$reason,'/auftrag/'.$o['order_no'],null,true);
    flash('success','Auftragsänderung gespeichert und dokumentiert.');
    redirect('/admin/auftrag/'.$o['order_no'].'/aendern');
}


if (preg_match('#^/admin/auftrag/(\\d{8})/nachweisplan$#',$path,$m) && $method==='GET') {
    require_admin();
    $q=db()->prepare("SELECT o.*,f.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id WHERE o.order_no=?");
    $q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();

    $runId=current_run_id((int)$o['id']);
    $suggestedDay=1;
    if($runId){
        $q=db()->prepare("SELECT MIN(day_no) FROM order_days WHERE order_id=? AND order_run_id=? AND calendar_date>=CURDATE()");
        $q->execute([$o['id'],$runId]);$candidate=$q->fetchColumn();
        if($candidate!==false && $candidate!==null) $suggestedDay=max(1,(int)$candidate);
    }
    $rules=order_evidence_rules_for_day((int)$o['id'],$suggestedDay);
    $h=db()->prepare("SELECT x.*,a.email admin_email FROM order_evidence_plan_overrides x JOIN admins a ON a.id=x.admin_id WHERE x.order_id=? ORDER BY x.effective_day_no,x.id");
    $h->execute([$o['id']]);$history=$h->fetchAll();

    ob_start();?>
    <div class="dashboard-head"><div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1>Nachweisplan ändern</h1><p class="meta"><?=e($o['title'])?> · <?=e($o['seller_name'])?></p></div><a class="btn secondary" href="<?=e(url('/admin/auftrag/'.$o['order_no']))?>">Zum Auftrag</a></div>
    <div class="panel">
      <p>Hier änderst du ausschließlich den künftigen Tagesplan dieses Auftrags. Bereits abgelaufene Nachweisfenster und bereits eingereichte Nachweise werden nicht rückwirkend verändert.</p>
      <?php if(!in_array($o['status'],['precheck','running'],true)):?><p class="badge">In diesem Auftragsstatus ist keine Planänderung mehr möglich.</p><?php else:?>
      <form method="post">
        <?=csrf_field()?>
        <div class="form-grid">
          <label>Wirksam ab Durchführungstag<input type="number" min="1" name="effective_day_no" value="<?=e($suggestedDay)?>" required></label>
          <label>Morgen – Pflichtfotos<input type="number" min="0" max="20" name="morning_count" value="<?=e($rules['daily']['morning'])?>" required></label>
          <label>Mittag – Pflichtfotos<input type="number" min="0" max="20" name="midday_count" value="<?=e($rules['daily']['midday'])?>" required></label>
          <label>Abend – Pflichtfotos<input type="number" min="0" max="20" name="evening_count" value="<?=e($rules['daily']['evening'])?>" required></label>
        </div>
        <label>Grund der Änderung<textarea name="reason" required></textarea></label>
        <button class="btn">Nachweisplan ab diesem Tag ändern</button>
      </form>
      <?php endif;?>
    </div>

    <h2>Planänderungen</h2>
    <div class="table-wrap"><table><thead><tr><th>Wirksam ab</th><th>Morgen</th><th>Mittag</th><th>Abend</th><th>Grund</th><th>Zeit</th><th>Admin</th></tr></thead><tbody>
    <?php foreach($history as $x): $r=json_decode($x['rules_json'],true)?:[];?><tr>
      <td>Tag <?=e($x['effective_day_no'])?></td>
      <td><?=e($r['daily']['morning']??0)?></td>
      <td><?=e($r['daily']['midday']??0)?></td>
      <td><?=e($r['daily']['evening']??0)?></td>
      <td><?=nl2br(e($x['reason']))?></td>
      <td><?=e(date('d.m.Y H:i',strtotime($x['created_at'])))?></td>
      <td><?=e($x['admin_email'])?></td>
    </tr><?php endforeach;?>
    <?php if(!$history):?><tr><td colspan="7">Noch keine auftragsspezifische Änderung.</td></tr><?php endif;?>
    </tbody></table></div>
    <?php render('Nachweisplan ändern',ob_get_clean());exit;
}

if (preg_match('#^/admin/auftrag/(\\d{8})/nachweisplan$#',$path,$m) && $method==='POST') {
    $a=require_admin();
    $q=db()->prepare("SELECT * FROM orders WHERE order_no=?");$q->execute([$m[1]]);$o=$q->fetch();if(!$o)not_found();
    if(!in_array($o['status'],['precheck','running'],true) || $o['archived_at']){
        flash('error','Der Nachweisplan kann in diesem Status nicht geändert werden.');redirect('/admin/auftrag/'.$o['order_no']);
    }

    $effective=max(1,(int)post('effective_day_no','1'));
    $reason=post('reason');
    if($reason===''){flash('error','Bitte einen Änderungsgrund angeben.');redirect('/admin/auftrag/'.$o['order_no'].'/nachweisplan');}

    $runId=current_run_id((int)$o['id']);
    if($o['status']==='running' && $runId){
        $q=db()->prepare("SELECT MIN(day_no) FROM order_days WHERE order_id=? AND order_run_id=? AND calendar_date>=CURDATE()");
        $q->execute([$o['id'],$runId]);$minMutable=$q->fetchColumn();
        if($minMutable!==false && $minMutable!==null && $effective<(int)$minMutable){
            flash('error','Die Änderung darf nicht rückwirkend vor Tag '.(int)$minMutable.' gelten.');
            redirect('/admin/auftrag/'.$o['order_no'].'/nachweisplan');
        }
    }

    $before=order_evidence_rules_for_day((int)$o['id'],$effective);
    $rules=[
      'precheck_required_count'=>$before['precheck_required_count'],
      'daily'=>[
        'morning'=>max(0,min(20,(int)post('morning_count','0'))),
        'midday'=>max(0,min(20,(int)post('midday_count','0'))),
        'evening'=>max(0,min(20,(int)post('evening_count','0'))),
      ],
    ];
    if($before['daily']===$rules['daily']){
        flash('error','Der neue Nachweisplan entspricht dem bisher gültigen Plan.');
        redirect('/admin/auftrag/'.$o['order_no'].'/nachweisplan');
    }

    $pdo=db();$pdo->beginTransaction();
    try{
        $pdo->prepare("INSERT INTO order_evidence_plan_overrides(order_id,admin_id,effective_day_no,rules_json,reason) VALUES(?,?,?,?,?)")
          ->execute([$o['id'],$a['id'],$effective,json_encode($rules,JSON_UNESCAPED_UNICODE),$reason]);

        if($o['status']==='running' && $runId){
            $defs=[
              'morning'=>parse_window_setting('window_morning','06:00-10:00'),
              'midday'=>parse_window_setting('window_midday','12:00-16:00'),
              'evening'=>parse_window_setting('window_evening','18:00-23:59'),
            ];
            $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
            $now=new DateTimeImmutable('now',$tz);
            $grace=max(0,(int)setting_value('grace_minutes','60'));

            $days=$pdo->prepare("SELECT day_no,calendar_date FROM order_days WHERE order_id=? AND order_run_id=? AND day_no>=? ORDER BY day_no");
            $days->execute([$o['id'],$runId,$effective]);
            foreach($days->fetchAll() as $day){
                foreach($defs as $key=>$range){
                    $required=(int)$rules['daily'][$key];
                    $wq=$pdo->prepare("SELECT * FROM evidence_windows WHERE order_id=? AND order_run_id=? AND day_no=? AND window_key=? LIMIT 1");
                    $wq->execute([$o['id'],$runId,$day['day_no'],$key]);$window=$wq->fetch();

                    if($window){
                        $windowEnd=new DateTimeImmutable($window['grace_ends_at']?:$window['ends_at'],$tz);
                        if($windowEnd<=$now) continue;
                        $cq=$pdo->prepare("SELECT COUNT(*) FROM evidences WHERE order_id=? AND order_run_id=? AND evidence_type='daily' AND day_no=? AND window_key=? AND status IN('submitted','accepted')");
                        $cq->execute([$o['id'],$runId,$day['day_no'],$key]);$submitted=(int)$cq->fetchColumn();

                        if($required===0){
                            $status=$submitted>0?'submitted':'waived';
                        }elseif($submitted >= $required){
                            $status='submitted';
                        }else{
                            $start=new DateTimeImmutable($window['starts_at'],$tz);
                            $status=$start<=$now?'open':'planned';
                        }
                        $pdo->prepare("UPDATE evidence_windows SET required_count=?,status=? WHERE id=?")->execute([$required,$status,$window['id']]);
                    }elseif($required>0){
                        $start=new DateTimeImmutable($day['calendar_date'].' '.$range[0].':00',$tz);
                        $end=new DateTimeImmutable($day['calendar_date'].' '.$range[1].':00',$tz);
                        $graceEnd=$end->modify('+'.$grace.' minutes');
                        if($graceEnd<=$now) continue;
                        $status=$start<=$now?'open':'planned';
                        $pdo->prepare("INSERT INTO evidence_windows(order_id,order_run_id,day_no,window_key,starts_at,ends_at,grace_ends_at,required_count,status) VALUES(?,?,?,?,?,?,?,?,?)")
                          ->execute([$o['id'],$runId,$day['day_no'],$key,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$graceEnd->format('Y-m-d H:i:s'),$required,$status]);
                    }
                }
            }
        }

        $oldText='Morgen '.$before['daily']['morning'].' / Mittag '.$before['daily']['midday'].' / Abend '.$before['daily']['evening'];
        $newText='Morgen '.$rules['daily']['morning'].' / Mittag '.$rules['daily']['midday'].' / Abend '.$rules['daily']['evening'];
        $pdo->prepare("INSERT INTO order_changes(order_id,admin_id,field_name,old_value,new_value,reason,effective_day_no) VALUES(?,?,?,?,?,?,?)")
          ->execute([$o['id'],$a['id'],'evidence_plan',$oldText,$newText,$reason,$effective]);
        $pdo->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
          ->execute([$o['id'],'Nachweisplan ab Durchführungstag '.$effective.' geändert: '.$newText.'. Grund: '.$reason]);
        log_event('order.evidence_plan_changed',(int)$o['seller_id'],(int)$o['id'],['effective_day_no'=>$effective,'old'=>$before['daily'],'new'=>$rules['daily'],'reason'=>$reason,'admin_id'=>(int)$a['id']]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        flash('error',$e->getMessage());redirect('/admin/auftrag/'.$o['order_no'].'/nachweisplan');
    }

    notify_seller((int)$o['seller_id'],'order.evidence_plan_changed','Nachweisplan geändert','Der Nachweisplan für Auftrag '.$o['order_no'].' wurde ab Durchführungstag '.$effective.' geändert. Grund: '.$reason,'/auftrag/'.$o['order_no'],null,true);
    flash('success','Nachweisplan aktualisiert und dokumentiert.');
    redirect('/admin/auftrag/'.$o['order_no'].'/nachweisplan');
}

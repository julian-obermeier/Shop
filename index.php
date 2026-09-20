<?php
declare(strict_types=1);
require __DIR__.'/app/Core.php';
require __DIR__.'/app/PublicPages.php';

$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';
$basePath=parse_url((string)app_config('app.url',''),PHP_URL_PATH) ?: '';
if ($basePath && $basePath!=='/' && str_starts_with($path,$basePath)) $path=substr($path,strlen($basePath)) ?: '/';
$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method==='POST') csrf_verify();
reject_if_archived_route($path,$method);

function legal_page(string $title,string $intro,array $sections): never {
    ob_start(); ?><section class="legal"><div class="eyebrow">Information</div><h1><?=e($title)?></h1><p class="meta"><?=e($intro)?></p><?php foreach($sections as $h=>$p):?><h2><?=e($h)?></h2><p><?=e($p)?></p><?php endforeach;?></section><?php render($title,ob_get_clean()); exit;
}
function not_found(): never { http_response_code(404); render('Nicht gefunden','<div class="empty"><h1>404</h1><p>Die angeforderte Seite wurde nicht gefunden.</p></div>'); exit; }

if($path==='/'&&$method==='GET'){
 $offers=db()->query("SELECT o.*,c.name category_name,
   (SELECT COUNT(*) FROM orders x WHERE x.offer_id=o.id) accepted_count,
   (SELECT COUNT(*) FROM orders x WHERE x.offer_id=o.id AND x.status IN('precheck','running','shipping','review','payout')) active_count
   FROM offers o JOIN categories c ON c.id=o.category_id
   WHERE o.status='active' AND o.visibility='public' ORDER BY o.created_at DESC LIMIT 6")->fetchAll();
 ob_start();?>
 <section class="hero"><div><div class="eyebrow">Diskrete Ankaufsplattform · 18+</div><h1>Du entscheidest, welche Aufträge zu dir passen.</h1><p>Konkrete Ankaufangebote, transparente Vergütung und ein geführter Ablauf von der Vorbereitung bis zur Auszahlung.</p><div class="actions"><a class="btn" href="<?=e(url('/angebote'))?>">Angebote entdecken</a><a class="btn secondary" href="<?=e(url('/registrieren'))?>">Als Verkäuferin registrieren</a></div></div><div class="panel"><h3>Kein klassischer Marktplatz</h3><p>Du musst keine Anzeigen erstellen und keine Käufer suchen. Der Betreiber veröffentlicht konkrete Aufträge.</p><div class="timeline"><div>✓ Keine öffentlichen Verkäuferprofile</div><div>✓ Anforderungen vor Annahme sichtbar</div><div>✓ Geschützte Nachweise</div><div>✓ Wallet & Auszahlung</div></div></div></section>
 <h2>Aktuelle Angebote</h2><div class="grid"><?php foreach($offers as $o):?><article class="card"><span class="badge"><?=e($o['category_name'])?></span><h3><?=e($o['title'])?></h3><p class="meta"><?=e(mb_strimwidth(strip_tags($o['description']),0,130,'…'))?></p><div class="price"><?=money($o['compensation'])?></div><p class="meta"><?=e($o['accepted_count'])?>× angenommen · <?=e($o['active_count'])?> aktuell aktiv</p><a class="btn" href="<?=e(url('/angebot/'.$o['slug']))?>">Details</a></article><?php endforeach;?><?php if(!$offers):?><div class="empty">Aktuell sind noch keine Angebote veröffentlicht.</div><?php endif;?></div>
 <?php render('Startseite',ob_get_clean()); exit;
}
if($path==='/angebote'&&$method==='GET'){
 $q=trim((string)($_GET['q']??''));$cat=(int)($_GET['category']??0);
 $minComp=isset($_GET['min_comp'])&&$_GET['min_comp']!==''?(float)$_GET['min_comp']:null;
 $maxComp=isset($_GET['max_comp'])&&$_GET['max_comp']!==''?(float)$_GET['max_comp']:null;
 $maxDays=isset($_GET['max_days'])&&$_GET['max_days']!==''?(int)$_GET['max_days']:null;
 $sql="SELECT o.*,c.name category_name,
   (SELECT COUNT(*) FROM orders x WHERE x.offer_id=o.id) accepted_count,
   (SELECT COUNT(*) FROM orders x WHERE x.offer_id=o.id AND x.status IN('precheck','running','shipping','review','payout')) active_count
   FROM offers o JOIN categories c ON c.id=o.category_id WHERE o.status='active' AND o.visibility='public'";$args=[];
 if($q!==''){$sql.=" AND (o.title LIKE ? OR o.description LIKE ?)";$args[]="%$q%";$args[]="%$q%";}
 if($cat){$sql.=" AND o.category_id=?";$args[]=$cat;}
 if($minComp!==null){$sql.=" AND o.compensation>=?";$args[]=$minComp;}
 if($maxComp!==null){$sql.=" AND o.compensation<=?";$args[]=$maxComp;}
 if($maxDays!==null){$sql.=" AND (o.duration_days IS NULL OR o.duration_days<=?)";$args[]=$maxDays;}
 $sql.=" ORDER BY o.created_at DESC";$st=db()->prepare($sql);$st->execute($args);$offers=$st->fetchAll();
 $cats=db()->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order,name")->fetchAll();
 ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Angebote</div><h1>Aktuelle Ankaufangebote</h1></div></div>
 <form method="get" class="panel"><div class="form-grid"><label>Suche<input name="q" value="<?=e($q)?>" placeholder="Angebote durchsuchen"></label><label>Kategorie<select name="category"><option value="">Alle Kategorien</option><?php foreach($cats as $catRow):?><option value="<?=$catRow['id']?>" <?=$cat===(int)$catRow['id']?'selected':''?>><?=e($catRow['name'])?></option><?php endforeach;?></select></label><label>Vergütung ab (€)<input type="number" step=".01" min="0" name="min_comp" value="<?=e($minComp??'')?>"></label><label>Vergütung bis (€)<input type="number" step=".01" min="0" name="max_comp" value="<?=e($maxComp??'')?>"></label><label>Maximale Dauer (Tage)<input type="number" min="1" name="max_days" value="<?=e($maxDays??'')?>"></label></div><button class="btn">Filtern</button></form><br>
 <div class="grid"><?php foreach($offers as $o):?><article class="card"><span class="badge"><?=e($o['category_name'])?></span><h3><?=e($o['title'])?></h3><p class="meta"><?=e(mb_strimwidth(strip_tags($o['description']),0,150,'…'))?></p><div class="price"><?=money($o['compensation'])?></div><p class="meta"><?=$o['duration_days']?e($o['duration_days']).' Tage':'Individueller Umfang'?> · <?=e($o['accepted_count'])?>× angenommen · <?=e($o['active_count'])?> aktiv</p><a class="btn" href="<?=e(url('/angebot/'.$o['slug']))?>">Ansehen</a></article><?php endforeach;?><?php if(!$offers):?><div class="empty">Keine passenden Angebote gefunden.</div><?php endif;?></div><?php render('Angebote',ob_get_clean());exit;
}
if(preg_match('#^/angebot/([a-z0-9-]+)$#',$path,$m)&&$method==='GET'){
 $st=db()->prepare("SELECT o.*,c.name category_name,
   (SELECT COUNT(*) FROM orders x WHERE x.offer_id=o.id) accepted_count,
   (SELECT COUNT(*) FROM orders x WHERE x.offer_id=o.id AND x.status IN('precheck','running','shipping','review','payout')) active_count
   FROM offers o JOIN categories c ON c.id=o.category_id WHERE o.slug=? AND o.status='active' AND o.visibility='public'");
 $st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();
 $op=db()->prepare("SELECT * FROM offer_options WHERE offer_id=? AND active=1 ORDER BY id");$op->execute([$o['id']]);$options=$op->fetchAll();
 $rules=offer_evidence_rules($o);
 $dailyCount=array_sum($rules['daily']);
 $regularPhotos=(int)$rules['precheck_required_count']+($o['duration_days']?($dailyCount*(int)$o['duration_days']):0);
 $planned=offer_task_plan_summary((int)$o['id'],$o['duration_days']!==null?(int)$o['duration_days']:1);
 $shipping=build_shipping_snapshot($o);
 $shippingPhotos=array_sum(array_map(fn($x)=>(int)($x['required_photos']??0),$shipping['steps']??[]));
 $knownPhotos=$regularPhotos+$shippingPhotos+(int)($planned['required_photos']??0);
 $shippingLabel=$shipping['cost_mode']==='fixed'
   ? 'Fester Versandzuschuss: '.money($shipping['allowance'])
   : ($shipping['cost_mode']==='reimburse' ? 'Versandkosten werden gegen Nachweis erstattet.' : 'Versandkosten trägt die Verkäuferin.');
 $baseKnown=(float)$o['compensation']+(float)$planned['compensation']+($shipping['cost_mode']==='fixed'?(float)$shipping['allowance']:0.0);
 $currentSeller=seller();$isFirstOrder=false;
 if($currentSeller){$fq=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=?");$fq->execute([$currentSeller['id']]);$isFirstOrder=(int)$fq->fetchColumn()===0;}
 ob_start();?><div class="eyebrow"><?=e($o['category_name'])?></div><h1><?=e($o['title'])?></h1>
 <div class="grid two"><section class="panel"><h2>Das erwartet dich</h2><p><?=nl2br(e($o['description']))?></p>
 <h3>Aufwand</h3><div class="timeline"><div><strong>Dauer:</strong> <?=$o['duration_days']?e($o['duration_days']).' Tage':'individuell / nicht tagegebunden'?></div><div><strong>Vorabnachweise:</strong> <?=e($rules['precheck_required_count'])?> Foto(s)</div><div><strong>Tägliche Regel-Nachweise:</strong> <?=e($dailyCount)?> Foto(s) pro Tag<?php if($o['duration_days']):?> · <?=e($dailyCount*(int)$o['duration_days'])?> insgesamt<?php endif;?></div><div><strong>Geplante Zusatzaufgaben:</strong> <?=e($planned['executions'])?> Ausführung(en)</div><div><strong>Versandschritte:</strong> <?=e(count($shipping['steps']??[]))?> · <?=e($shippingPhotos)?> bekannte Versandfoto(s)</div><div><strong>Bekannte Pflichtfotos:</strong> <?=e($knownPhotos)?><?php if(!$o['duration_days']):?> + variable Tagesnachweise<?php endif;?></div></div>
 <p class="meta">Spontane Nachweise, Neuaufnahmen nach Beanstandungen oder bestätigte Verstöße können zusätzliche Nachweise bzw. zusätzliche Durchführungstage verursachen.</p>
 <?php if($planned['plans']):?><h3>Geplante Aufgaben</h3><div class="timeline"><?php foreach($planned['plans'] as $pt): $occ=count($pt['_occurrence_days']??[]);?><div><strong><?=e($pt['title'])?></strong> · <?=e($occ)?>× · <?=money((float)$pt['compensation']*$occ)?><?php if((int)$pt['required_photos']>0):?> · <?=e((int)$pt['required_photos']*$occ)?> Aufgabenfoto(s)<?php endif;?><br><span class="meta"><?=e($pt['description']??'')?></span></div><?php endforeach;?></div><?php endif;?>
 <h3>Versand</h3><p><?=e($shippingLabel)?><?php if($shipping['preferred_carrier']):?><br>Bevorzugter Versanddienstleister: <?=e($shipping['preferred_carrier'])?><?php endif;?><?php if($shipping['instructions']):?><br><?=nl2br(e($shipping['instructions']))?><?php endif;?></p><p class="meta">Die konkrete Empfängeradresse wird erst in der Versandphase angezeigt.</p>
 <p class="meta"><?=e($o['accepted_count'])?> echte Annahme(n) insgesamt · <?=e($o['active_count'])?> aktuell aktive Auftrag/Aufträge.</p></section>
 <aside class="panel"><div class="meta">Grundvergütung</div><div class="price"><?=money($o['compensation'])?></div>
 <?php if($planned['compensation']>0):?><p>Geplante Aufgaben: <strong>+<?=money($planned['compensation'])?></strong></p><?php endif;?>
 <?php if($shipping['cost_mode']==='fixed' && $shipping['allowance']>0):?><p>Versandzuschuss: <strong>+<?=money($shipping['allowance'])?></strong></p><?php endif;?>
 <p><strong>Bekannter Wert vor Optionen: <?=money($baseKnown)?></strong></p>
 <?php if($currentSeller):?><form method="post" action="<?=e(url('/angebot/'.$o['slug'].'/annehmen'))?>"><?=csrf_field()?>
 <?php if($options):?><h3>Zusatzoptionen</h3><?php foreach($options as $opt):?><label style="display:flex;gap:10px;align-items:flex-start"><input style="width:auto;margin-top:5px" type="checkbox" name="option_ids[]" value="<?=e($opt['id'])?>"><span><?=e($opt['label'])?> <?php if((float)$opt['price']>0):?><strong>+<?=money($opt['price'])?></strong><?php else:?><strong>kostenlos</strong><?php endif;?></span></label><?php endforeach;?><?php endif;?>
 <?php if($isFirstOrder):?><div class="card" style="margin-top:12px"><strong>Pflicht-Kurzbriefing vor deinem ersten Auftrag</strong><p class="meta">Du erfüllst den Auftrag ausschließlich selbst und mit eigenen Artikeln/Inhalten. Pflichtnachweise und Fristen sind verbindlich. Ein normaler Selbst-Storno ist nach Annahme nicht vorgesehen. Automatisch erkannte Verstöße werden zunächst geprüft; bestätigte Verstöße können unbezahlte Zusatztage auslösen. Versand- bzw. digitale Abschlussregeln gehören zum Auftrag.</p><label><input type="checkbox" style="width:auto" name="confirm_briefing" value="1" required> Kurzbriefing gelesen und verstanden</label></div><?php endif;?>
 <h3>Verbindliche Bestätigung</h3>
 <label><input type="checkbox" style="width:auto" name="confirm_personal" value="1" required> Ich erfülle diesen Auftrag persönlich; keine Fremdware und keine Leistung durch Dritte.</label>
 <label><input type="checkbox" style="width:auto" name="confirm_effort" value="1" required> Ich habe Dauer, bekannte Nachweise, geplante Aufgaben, Optionen und Versandaufwand geprüft.</label>
 <label><input type="checkbox" style="width:auto" name="confirm_rules" value="1" required> Ich akzeptiere die Plattformregeln und die Bedingungen dieser Angebotsversion.</label>
 <label><input type="checkbox" style="width:auto" name="confirm_violation" value="1" required> Mir ist bekannt, dass bestätigte Verstöße zusätzliche unbezahlte Durchführungstage verursachen können.</label>
 <button class="btn">Angebot verbindlich annehmen</button></form>
 <?php else:?><a class="btn" href="<?=e(url('/login'))?>">Einloggen & annehmen</a><?php endif;?></aside></div><?php render($o['title'],ob_get_clean());exit;
}
if(preg_match('#^/angebot/([a-z0-9-]+)/annehmen$#',$path,$m)&&$method==='POST'){
 $s=require_seller(); if(!$s['email_verified_at']){flash('error','Bitte bestätige zuerst deine E-Mail-Adresse.');redirect('/dashboard');}
 foreach(['confirm_personal','confirm_effort','confirm_rules','confirm_violation'] as $requiredConfirm){
   if(($_POST[$requiredConfirm]??'')!=='1'){flash('error','Bitte bestätige alle Pflichtpunkte vor der Annahme.');redirect('/angebot/'.$m[1]);}
 }
 $firstQ=db()->prepare("SELECT COUNT(*) FROM orders WHERE seller_id=?");$firstQ->execute([$s['id']]);$isFirstOrder=(int)$firstQ->fetchColumn()===0;
 if($isFirstOrder && ($_POST['confirm_briefing']??'')!=='1'){flash('error','Vor dem ersten Auftrag muss das Kurzbriefing bestätigt werden.');redirect('/angebot/'.$m[1]);}
 $st=db()->prepare("SELECT * FROM offers WHERE slug=? AND status='active' AND visibility='public'");$st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();
 $dupe=db()->prepare("SELECT COUNT(*) FROM orders x JOIN offers ox ON ox.id=x.offer_id WHERE x.seller_id=? AND ox.category_id=? AND x.status IN('precheck','running','shipping','review','payout')");
 $dupe->execute([$s['id'],$o['category_id']]); if((int)$dupe->fetchColumn()>0){flash('error','In dieser Kategorie besteht bereits ein aktiver Auftrag.');redirect('/angebote');}
 $requested=array_values(array_unique(array_map('intval',(array)($_POST['option_ids']??[]))));
 $selected=[];$optionsTotal=0.0;
 if($requested){
   $placeholders=implode(',',array_fill(0,count($requested),'?'));
   $args=array_merge([$o['id']],$requested);
   $q=db()->prepare("SELECT * FROM offer_options WHERE offer_id=? AND active=1 AND id IN ($placeholders)");$q->execute($args);$selected=$q->fetchAll();
   foreach($selected as $opt)$optionsTotal+=(float)$opt['price'];
 }
 $shippingSnapshot=build_shipping_snapshot($o);
 $shippingAllowance=$shippingSnapshot['cost_mode']==='fixed' ? max(0,(float)$shippingSnapshot['allowance']) : 0.0;
 $taskSummary=offer_task_plan_summary((int)$o['id'],$o['duration_days']!==null?(int)$o['duration_days']:1);
 $plannedTaskCompensation=(float)$taskSummary['compensation'];
 $total=(float)$o['compensation']+$optionsTotal+$shippingAllowance+$plannedTaskCompensation;$no=order_number();
 db()->beginTransaction(); try{
   db()->prepare("INSERT INTO orders(order_no,seller_id,offer_id,offer_version,status,base_compensation,total_compensation,duration_days,shipping_snapshot_json) VALUES(?,?,?,?, 'precheck',?,?,?,?)")->execute([$no,$s['id'],$o['id'],$o['current_version'],$o['compensation'],$total,$o['duration_days'],json_encode($shippingSnapshot,JSON_UNESCAPED_UNICODE)]);
   $oid=(int)db()->lastInsertId();
   $confirmationPayload=[
     'personal_fulfillment'=>true,
     'effort_reviewed'=>true,
     'rules_accepted'=>true,
     'violation_consequences_acknowledged'=>true,
     'first_order_briefing'=>$isFirstOrder,
     'offer_id'=>(int)$o['id'],
     'offer_version'=>(int)$o['current_version'],
     'duration_days'=>$o['duration_days']!==null?(int)$o['duration_days']:null,
     'precheck_photos'=>(int)offer_evidence_rules($o)['precheck_required_count'],
     'daily_photos_per_day'=>array_sum(offer_evidence_rules($o)['daily']),
     'planned_task_executions'=>(int)$taskSummary['executions'],
     'planned_task_photos'=>(int)$taskSummary['required_photos'],
     'selected_option_ids'=>$requested,
     'shipping_step_count'=>count($shippingSnapshot['steps']??[]),
     'shipping_allowance'=>$shippingAllowance,
     'total_compensation'=>$total,
   ];
   db()->prepare("INSERT INTO order_acceptance_confirmations(order_id,seller_id,offer_version,payload_json) VALUES(?,?,?,?)")
     ->execute([$oid,$s['id'],$o['current_version'],json_encode($confirmationPayload,JSON_UNESCAPED_UNICODE)]);
   snapshot_offer_task_plans((int)$o['id'],$oid);
   db()->prepare("INSERT INTO order_runs(order_id,run_no,status) VALUES(?,1,'precheck')")->execute([$oid]);
   foreach($selected as $opt)db()->prepare("INSERT INTO order_options(order_id,offer_option_id,label_snapshot,price_snapshot) VALUES(?,?,?,?)")->execute([$oid,$opt['id'],$opt['label'],$opt['price']]);
   db()->prepare("INSERT INTO wallet_entries(seller_id,order_id,entry_type,amount,description) VALUES(?,?,'reserved',?,'Auftragswert vorgemerkt')")->execute([$s['id'],$oid,$total]);
   db()->prepare("INSERT INTO system_events(seller_id,order_id,event_type,payload_json) VALUES(?,?,'order.accepted',?)")->execute([$s['id'],$oid,json_encode(['offer_version'=>$o['current_version'],'option_ids'=>$requested,'shipping_allowance'=>$shippingAllowance,'planned_task_compensation'=>$plannedTaskCompensation,'planned_task_executions'=>$taskSummary['executions'],'total'=>$total],JSON_UNESCAPED_UNICODE)]);
   if(in_array($o['fulfillment_type'],['digital','mixed'],true))db()->prepare("INSERT INTO rights_acceptances(order_id,seller_id,terms_version,payload_json) VALUES(?,?,?,?)")->execute([$oid,$s['id'],'v1',json_encode(['scope'=>'technical_processing_and_order_terms'],JSON_UNESCAPED_UNICODE)]);
   db()->commit();
 }catch(Throwable $e){db()->rollBack();throw $e;}
 flash('success','Auftrag '.$no.' wurde angenommen. Gesamtwert: '.money($total).'. Bitte Vorabkontrolle bzw. Auftragsvorbereitung durchführen.');redirect('/auftrag/'.$no);
}
if($path==='/registrieren'&&$method==='GET'){
 ob_start();?><div class="grid two"><section><div class="eyebrow">Verkäuferinnenkonto</div><h1>Registrieren</h1><p>Nur für Volljährige ab 18 Jahren. Es gibt kein öffentliches Verkäuferinnenprofil.</p></section><form class="panel" method="post"><?=csrf_field()?><div class="form-grid"><label>Vorname<input name="first_name" required></label><label>Nachname<input name="last_name" required></label><label>Geburtsdatum<input type="date" name="birth_date" required></label><label>Telefon<input name="phone" required></label><label>Straße / Hausnummer<input name="street" required></label><label>PLZ<input name="postal_code" required></label><label>Ort<input name="city" required></label><label>E-Mail<input type="email" name="email" required></label></div><label>Passwort<input type="password" name="password" minlength="10" required></label><label><input type="checkbox" name="adult" value="1" required style="width:auto"> Ich bestätige, dass ich mindestens 18 Jahre alt bin und die Plattformregeln akzeptiere.</label><button class="btn">Konto erstellen</button></form></div><?php render('Registrieren',ob_get_clean());exit;
}
if($path==='/registrieren'&&$method==='POST'){
 $birth=post('birth_date');$adult=($_POST['adult']??'')==='1';$email=strtolower(post('email'));$pass=(string)($_POST['password']??'');
 $age=$birth?date_diff(new DateTime($birth),new DateTime('today'))->y:0;
 if(!$adult||$age<18||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<10){flash('error','Bitte prüfe Volljährigkeit, E-Mail und Passwort.');redirect('/registrieren');}
 try{
  db()->prepare("INSERT INTO sellers(first_name,last_name,birth_date,street,postal_code,city,phone,email,password_hash) VALUES(?,?,?,?,?,?,?,?,?)")->execute([post('first_name'),post('last_name'),$birth,post('street'),post('postal_code'),post('city'),post('phone'),$email,password_hash($pass,PASSWORD_DEFAULT)]);
  $id=(int)db()->lastInsertId();[$raw,$hash]=make_token();db()->prepare("INSERT INTO email_verifications(seller_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))")->execute([$id,$hash]);
  send_app_mail($email,'E-Mail bestätigen','<p>Bitte bestätige deine E-Mail:</p><p><a href="'.e(url('/email-bestaetigen?token='.$raw)).'">E-Mail bestätigen</a></p>');
  $_SESSION['seller_id']=$id;session_regenerate_id(true);flash('success','Konto erstellt. Bitte bestätige deine E-Mail-Adresse.');redirect('/dashboard');
 }catch(PDOException $e){flash('error','Diese E-Mail-Adresse ist bereits registriert.');redirect('/registrieren');}
}
if($path==='/email-bestaetigen'&&$method==='GET'){
 $hash=hash('sha256',(string)($_GET['token']??''));$st=db()->prepare("SELECT * FROM email_verifications WHERE token_hash=? AND expires_at>NOW()");$st->execute([$hash]);$v=$st->fetch();
 if($v){db()->prepare("UPDATE sellers SET email_verified_at=NOW() WHERE id=?")->execute([$v['seller_id']]);db()->prepare("DELETE FROM email_verifications WHERE seller_id=?")->execute([$v['seller_id']]);flash('success','E-Mail-Adresse erfolgreich bestätigt.');}else flash('error','Bestätigungslink ist ungültig oder abgelaufen.');
 redirect('/dashboard');
}
if($path==='/login'&&$method==='GET'){
 ob_start();?><div class="grid two"><section><div class="eyebrow">Verkäuferinnenbereich</div><h1>Willkommen zurück</h1><p>Melde dich an, um Aufträge, Nachweise, Wallet und Auszahlungen zu verwalten.</p></section><form class="panel" method="post"><?=csrf_field()?><label>E-Mail<input type="email" name="email" required></label><label>Passwort<input type="password" name="password" required></label><button class="btn">Anmelden</button><a href="<?=e(url('/passwort-vergessen'))?>">Passwort vergessen?</a></form></div><?php render('Login',ob_get_clean());exit;
}
if($path==='/login'&&$method==='POST'){
 $email=strtolower(post('email'));
 if(!rate_limit_consume('seller-login',$email,5,900,900)){flash('error','Zu viele Anmeldeversuche. Bitte später erneut versuchen.');redirect('/login');}
 $st=db()->prepare("SELECT * FROM sellers WHERE email=? AND deleted_at IS NULL");$st->execute([$email]);$s=$st->fetch();
 if(!$s||!password_verify((string)($_POST['password']??''),$s['password_hash'])){flash('error','E-Mail oder Passwort ist falsch.');redirect('/login');}
 rate_limit_clear('seller-login',$email);
 session_regenerate_id(true);$_SESSION['seller_id']=$s['id'];redirect('/dashboard');
}
if($path==='/logout'){unset($_SESSION['seller_id']);session_regenerate_id(true);redirect('/');}
if($path==='/passwort-vergessen'&&$method==='GET'){
 ob_start();?><section class="legal"><h1>Passwort zurücksetzen</h1><form class="panel" method="post"><?=csrf_field()?><label>E-Mail<input type="email" name="email" required></label><button class="btn">Reset-Link anfordern</button></form></section><?php render('Passwort vergessen',ob_get_clean());exit;
}
if($path==='/passwort-vergessen'&&$method==='POST'){
 $email=strtolower(post('email'));
 if(!rate_limit_consume('password-reset',$email,5,3600,3600)){flash('success','Wenn ein Konto vorhanden ist, wurde ein Reset-Link versendet.');redirect('/login');}
 $st=db()->prepare("SELECT * FROM sellers WHERE email=? AND deleted_at IS NULL");$st->execute([$email]);$s=$st->fetch();
 if($s){[$raw,$hash]=make_token();db()->prepare("DELETE FROM password_resets WHERE seller_id=?")->execute([$s['id']]);db()->prepare("INSERT INTO password_resets(seller_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))")->execute([$s['id'],$hash]);send_app_mail($s['email'],'Passwort zurücksetzen','<p><a href="'.e(url('/passwort-reset?token='.$raw)).'">Neues Passwort setzen</a></p>');}
 flash('success','Wenn ein Konto vorhanden ist, wurde ein Reset-Link versendet.');redirect('/login');
}
if($path==='/passwort-reset'&&$method==='GET'){
 $token=(string)($_GET['token']??'');ob_start();?><section class="legal"><h1>Neues Passwort</h1><form class="panel" method="post"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>"><label>Neues Passwort<input type="password" name="password" minlength="10" required></label><button class="btn">Passwort speichern</button></form></section><?php render('Passwort Reset',ob_get_clean());exit;
}
if($path==='/passwort-reset'&&$method==='POST'){
 $hash=hash('sha256',post('token'));$st=db()->prepare("SELECT * FROM password_resets WHERE token_hash=? AND expires_at>NOW() AND used_at IS NULL");$st->execute([$hash]);$r=$st->fetch();$p=(string)($_POST['password']??'');
 if(!$r||strlen($p)<10){flash('error','Reset-Link ungültig oder Passwort zu kurz.');redirect('/passwort-vergessen');}
 db()->prepare("UPDATE sellers SET password_hash=? WHERE id=?")->execute([password_hash($p,PASSWORD_DEFAULT),$r['seller_id']]);db()->prepare("UPDATE password_resets SET used_at=NOW() WHERE id=?")->execute([$r['id']]);flash('success','Passwort geändert.');redirect('/login');
}
if($path==='/dashboard'&&$method==='GET'){
 $s=require_seller();$st=db()->prepare("SELECT o.*,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.seller_id=? ORDER BY o.created_at DESC");$st->execute([$s['id']]);$orders=$st->fetchAll();
 $w=db()->prepare("SELECT entry_type,SUM(amount) total FROM wallet_entries WHERE seller_id=? GROUP BY entry_type");$w->execute([$s['id']]);$wallet=[];foreach($w->fetchAll() as $r)$wallet[$r['entry_type']]=$r['total'];
 $dashboardAvailable=(float)($wallet['available']??0)+(float)($wallet['adjustment']??0)-(float)($wallet['paid']??0);
 ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Heute</div><h1>Hallo <?=e($s['first_name'])?></h1><p class="meta"><?=$s['email_verified_at']?'E-Mail bestätigt':'E-Mail noch nicht bestätigt – Angebote können noch nicht angenommen werden.'?></p><?php if(!$s['email_verified_at']):?><form method="post" action="<?=e(url('/email-bestaetigung-neu'))?>" style="margin-top:10px"><?=csrf_field()?><button class="btn secondary">Bestätigungs-E-Mail erneut senden</button></form><?php endif;?></div><div class="actions"><a class="btn secondary" href="<?=e(url('/benachrichtigungen'))?>">Benachrichtigungen</a><a class="btn secondary" href="<?=e(url('/wallet'))?>">Wallet</a><a class="btn secondary" href="<?=e(url('/profil'))?>">Profil</a><a class="btn" href="<?=e(url('/angebote'))?>">Angebote entdecken</a></div></div><div class="grid"><div class="card"><div class="meta">Vorgemerkt</div><div class="stat"><?=money($wallet['reserved']??0)?></div></div><div class="card"><div class="meta">Verfügbar</div><div class="stat"><?=money($dashboardAvailable)?></div></div><div class="card"><div class="meta">Aktive Aufträge</div><div class="stat"><?=count(array_filter($orders,fn($o)=>!in_array($o['status'],['completed','rejected','archived'],true)))?></div></div></div><h2>Deine Aufträge</h2><div class="table-wrap"><table><thead><tr><th>Nr.</th><th>Auftrag</th><th>Status</th><th>Wert</th><th></th></tr></thead><tbody><?php foreach($orders as $o):?><tr><td><?=e($o['order_no'])?></td><td><?=e($o['title'])?></td><td><span class="badge"><?=e($o['status'])?></span></td><td><?=money($o['total_compensation'])?></td><td><a href="<?=e(url('/auftrag/'.$o['order_no']))?>">Öffnen</a></td></tr><?php endforeach;?></tbody></table></div><?php render('Dashboard',ob_get_clean());exit;
}
if(preg_match('#^/auftrag/(\d{8})$#',$path,$m)&&$method==='GET'){
 $s=require_seller();$st=db()->prepare("SELECT o.*,f.title,f.description,f.evidence_rules_json,f.fulfillment_type,c.id category_id,c.name category_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN categories c ON c.id=f.category_id WHERE o.order_no=? AND o.seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
 $currentRunId=current_run_id((int)$o['id']);
 $itq=db()->prepare("SELECT * FROM order_items WHERE order_id=? AND order_run_id<=>? ORDER BY id DESC LIMIT 1");$itq->execute([$o['id'],$currentRunId]);$orderItem=$itq->fetch()?:null;
 $cfq=db()->prepare("SELECT * FROM category_fields WHERE category_id=? AND is_active=1 ORDER BY sort_order,id");$cfq->execute([$o['category_id']]);$categoryFields=$cfq->fetchAll();
 $itemAttributes=$orderItem ? (json_decode($orderItem['attributes_json']??'{}',true)?:[]) : [];
 $aoq=db()->prepare("SELECT * FROM offer_options WHERE offer_id=? AND active=1 ORDER BY id");$aoq->execute([$o['offer_id']]);$availableOptions=$aoq->fetchAll();
 $soq=db()->prepare("SELECT * FROM order_options WHERE order_id=? ORDER BY id");$soq->execute([$o['id']]);$selectedOptions=$soq->fetchAll();
 $selectedOptionIds=array_map(fn($x)=>(int)$x['offer_option_id'],$selectedOptions);
 $sdq=db()->prepare("SELECT * FROM order_start_date_requests WHERE order_id=? ORDER BY created_at DESC");$sdq->execute([$o['id']]);$startDateRequests=$sdq->fetchAll();
 $pendingStartDateRequest=null;foreach($startDateRequests as $sdr){if($sdr['status']==='pending'){$pendingStartDateRequest=$sdr;break;}}
 $ev=db()->prepare("SELECT * FROM evidences WHERE order_id=? ORDER BY created_at DESC");$ev->execute([$o['id']]);$evidences=$ev->fetchAll();
 $rules=offer_evidence_rules($o);$preRequired=max(1,(int)$rules['precheck_required_count']);
 $preAccepted=count(array_filter($evidences,fn($x)=>$x['evidence_type']==='precheck'&&(int)($x['order_run_id']??0)===(int)$currentRunId&&$x['status']==='accepted'));
 $prePending=count(array_filter($evidences,fn($x)=>$x['evidence_type']==='precheck'&&(int)($x['order_run_id']??0)===(int)$currentRunId&&$x['status']==='submitted'));
 $ow=db()->prepare("SELECT w.*,(SELECT COUNT(*) FROM evidences e WHERE e.order_id=w.order_id AND e.order_run_id<=>w.order_run_id AND e.evidence_type='daily' AND e.day_no=w.day_no AND e.window_key=w.window_key AND e.status IN('submitted','accepted')) submitted_count FROM evidence_windows w WHERE w.order_id=? AND w.order_run_id<=>? AND w.starts_at<=NOW() AND COALESCE(w.grace_ends_at,w.ends_at)>=NOW() AND w.status IN('planned','open','submitted') ORDER BY w.starts_at");
 $ow->execute([$o['id'],$currentRunId]);$openWindows=$ow->fetchAll();
 ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1><?=e($o['title'])?></h1><span class="badge"><?=e($o['status'])?></span></div><div class="price"><?=money($o['total_compensation'])?></div></div><div class="grid two"><section class="panel"><h2>Ablauf</h2><div class="timeline"><div>1. Vorbereitung / Vorabkontrolle <?= $o['status']==='precheck'?'← aktuell':''?></div><div>2. Durchführung</div><div>3. Versand / digitale Abgabe</div><div>4. Prüfung</div><div>5. Auszahlung</div><div>6. Archiv</div></div></section><section class="panel"><h2>Vorabkontrolle</h2><?php if($o['status']==='precheck'):?><p>Lade die für den konkreten Artikel erforderlichen Startnachweise direkt über die Kamera hoch.</p><p><strong><?=e($preAccepted)?> / <?=e($preRequired)?></strong> Pflichtnachweise freigegeben<?php if($prePending):?> · <?=e($prePending)?> warten auf Prüfung<?php endif;?></p><div class="progress"><span style="width:<?=e((string)min(100,round(($preAccepted/$preRequired)*100)))?>%"></span></div><br><form method="post" action="<?=e(url('/auftrag/'.$o['order_no'].'/nachweis'))?>" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="type" value="precheck">
<label>Kurze Artikelbezeichnung<input name="item_label" value="<?=e($orderItem['label']??'')?>" required placeholder="z. B. schwarze Sportsocken"></label>
<div class="form-grid"><label>Größe (optional)<input name="size_value" value="<?=e($orderItem['size_value']??'')?>"></label><label>Farbe (optional)<input name="color_value" value="<?=e($orderItem['color_value']??'')?>"></label><label>Marke (optional)<input name="brand_value" value="<?=e($orderItem['brand_value']??'')?>"></label><label>Material (optional)<input name="material_value" value="<?=e($orderItem['material_value']??'')?>"></label></div>
<?php foreach($categoryFields as $fld): $key=$fld['field_key'];$val=$itemAttributes[$key]??'';$opts=json_decode($fld['options_json']??'[]',true)?:[]; ?>
<label><?=e($fld['label'])?><?=$fld['required']?' *':''?>
<?php if($fld['field_type']==='select'):?><select name="attr[<?=e($key)?>]" <?=$fld['required']?'required':''?>><option value="">Bitte wählen</option><?php foreach($opts as $opt):?><option value="<?=e($opt)?>" <?=$val===$opt?'selected':''?>><?=e($opt)?></option><?php endforeach;?></select>
<?php elseif($fld['field_type']==='multiselect'):?><select name="attr[<?=e($key)?>][]" multiple <?=$fld['required']?'required':''?>><?php foreach($opts as $opt):?><option value="<?=e($opt)?>" <?=in_array($opt,(array)$val,true)?'selected':''?>><?=e($opt)?></option><?php endforeach;?></select>
<?php elseif($fld['field_type']==='boolean'):?><select name="attr[<?=e($key)?>]" <?=$fld['required']?'required':''?>><option value="">Bitte wählen</option><option value="1" <?=$val==='1'||$val===1?'selected':''?>>Ja</option><option value="0" <?=$val==='0'||$val===0?'selected':''?>>Nein</option></select>
<?php else:?><input type="<?=$fld['field_type']==='number'?'number':($fld['field_type']==='date'?'date':'text')?>" name="attr[<?=e($key)?>]" value="<?=e(is_array($val)?implode(', ',$val):$val)?>" <?=$fld['required']?'required':''?>><?php endif;?>
</label><?php endforeach;?>
<label>Pflichtfoto<input data-camera-input type="file" name="evidence" required></label><button class="btn">Nachweis einreichen</button></form><?php else:?><p class="meta">Die Vorabkontrolle ist abgeschlossen bzw. befindet sich nicht mehr in der Vorbereitungsphase.</p><?php endif;?></section></div>
<section class="panel" style="margin-top:18px"><h2>Startdatum</h2>
<?php if($o['planned_start_date']):?>
  <p>Vereinbarter Start: <strong><?=e(date('d.m.Y',strtotime($o['planned_start_date'])))?></strong></p>
  <p class="meta"><?php if($o['precheck_approved_at']):?>Vorabkontrolle vollständig freigegeben · automatischer Start am vereinbarten Datum.<?php else:?>Die Vorabkontrolle muss vor diesem Datum vollständig freigegeben sein.<?php endif;?></p>
  <?php if($o['status']==='precheck'):?>
    <?php if($pendingStartDateRequest):?><div class="card"><strong>Änderung beantragt: <?=e(date('d.m.Y',strtotime($pendingStartDateRequest['requested_date'])))?></strong><p><?=e($pendingStartDateRequest['reason'])?></p><span class="badge">WARTET AUF ADMIN</span></div>
    <?php else:?><details><summary>Startdatum ändern</summary><form method="post" action="<?=e(url('/auftrag/'.$o['order_no'].'/startdatum-aendern'))?>" style="margin-top:12px"><?=csrf_field()?><label>Neues Startdatum<input type="date" name="requested_date" min="<?=e(date('Y-m-d',strtotime('+1 day')))?>" required></label><label>Grund<textarea name="reason" required></textarea></label><button class="btn secondary">Änderung beantragen</button></form></details><?php endif;?>
  <?php endif;?>
<?php elseif($o['status']==='precheck'):?>
  <p>Lege fest, wann die Durchführung starten soll. Das früheste mögliche Datum ist morgen.</p>
  <form method="post" action="<?=e(url('/auftrag/'.$o['order_no'].'/startdatum'))?>"><?=csrf_field()?><label>Startdatum<input type="date" name="start_date" min="<?=e(date('Y-m-d',strtotime('+1 day')))?>" required></label><button class="btn">Startdatum festlegen</button></form>
<?php else:?><p class="meta">Für diesen Auftrag ist kein separates Startdatum hinterlegt.</p><?php endif;?>
<?php if($startDateRequests):?><h3>Änderungshistorie</h3><div class="timeline"><?php foreach($startDateRequests as $r):?><div><strong><?=e(date('d.m.Y',strtotime($r['requested_date'])))?></strong> · <?=e($r['status'])?><br><span class="meta"><?=e($r['reason'])?><?php if($r['admin_note']):?> · Admin: <?=e($r['admin_note'])?><?php endif;?></span></div><?php endforeach;?></div><?php endif;?>
</section>
<section class="panel" style="margin-top:18px"><h2>Zusatzoptionen & Auftragswert</h2>
<?php if($o['status']==='precheck'):?><form method="post" action="<?=e(url('/auftrag/'.$o['order_no'].'/optionen'))?>"><?=csrf_field()?>
<?php foreach($availableOptions as $opt):?><label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" style="width:auto;margin-top:5px" name="option_ids[]" value="<?=e($opt['id'])?>" <?=in_array((int)$opt['id'],$selectedOptionIds,true)?'checked':''?>><span><?=e($opt['label'])?> · <?=$opt['price']>0?('+'.money($opt['price'])):'kostenlos'?></span></label><?php endforeach;?>
<?php if($availableOptions):?><button class="btn secondary">Optionen aktualisieren</button><?php else:?><p class="meta">Für dieses Angebot sind keine Zusatzoptionen verfügbar.</p><?php endif;?></form>
<p class="meta">Bis zum tatsächlichen Start kannst du die Auswahl ändern. Danach sind Änderungen nur noch administrativ möglich.</p>
<?php else:?><div class="timeline"><?php foreach($selectedOptions as $opt):?><div><?=e($opt['label_snapshot'])?> · <?=money($opt['price_snapshot'])?></div><?php endforeach;?><?php if(!$selectedOptions):?><div class="meta">Keine Zusatzoptionen gewählt.</div><?php endif;?></div><?php endif;?>
<p><strong>Aktueller Auftragswert: <?=money($o['total_compensation'])?></strong></p></section>
<h2>Nachweise</h2><div class="table-wrap"><table><thead><tr><th>Typ</th><th>Zeitpunkt</th><th>Status</th></tr></thead><tbody><?php foreach($evidences as $evd):?><tr><td><?=e($evd['evidence_type'])?></td><td><?=e(date('d.m.Y H:i',strtotime($evd['created_at'])))?></td><td><?=e($evd['status'])?></td></tr><?php endforeach;?></tbody></table></div><div class="grid two" style="margin-top:18px">
<section class="panel"><h2>Während der Durchführung</h2><?php if($o['status']==='running'):?><?php $availableWindows=array_values(array_filter($openWindows,fn($w)=>(int)$w['submitted_count']<(int)$w['required_count'])); ?><?php if($availableWindows):?><form method="post" action="<?=e(url('/auftrag/'.$o['order_no'].'/tagesnachweis'))?>" enctype="multipart/form-data"><?=csrf_field()?><label>Aktuelles Nachweisfenster<select name="window_id" required><?php foreach($availableWindows as $w): $remaining=max(0,(int)$w['required_count']-(int)$w['submitted_count']);?><option value="<?=e($w['id'])?>">Tag <?=e($w['day_no'])?> · <?=e($w['window_key'])?> · noch <?=e($remaining)?> Foto(s) · bis <?=e(date('H:i',strtotime($w['grace_ends_at']?:$w['ends_at'])))?></option><?php endforeach;?></select></label><label>Live-Nachweis<input data-camera-input type="file" name="evidence" required></label><button class="btn">Tagesnachweis einreichen</button></form><?php else:?><p class="meta">Aktuell ist kein Nachweisfenster geöffnet. Dein Heute-Bereich zeigt die nächsten Termine.</p><?php endif;?><?php else:?><p class="meta">Tagesnachweise werden freigeschaltet, sobald der Auftrag läuft.</p><?php endif;?></section>
<section class="panel"><h2>Auftrag & Kommunikation</h2><div class="actions"><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no'].'/chat'))?>">Auftragschat</a><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no'].'/versand'))?>">Versand</a><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no'].'/digital'))?>">Digitale Abgabe</a><a class="btn secondary" href="<?=e(url('/auftrag/'.$o['order_no'].'/zwischenstaende'))?>">Zwischenstände</a></div><?php if($o['status']==='running'):?><hr><h3>Beschädigung melden</h3><form method="post" action="<?=e(url('/auftrag/'.$o['order_no'].'/beschaedigung'))?>" enctype="multipart/form-data"><?=csrf_field()?><label>Beschreibung<textarea name="reason" required></textarea></label><label>Nachweisfoto<input data-camera-input type="file" name="evidence"></label><button class="btn secondary">Beschädigung melden</button></form><?php endif;?></section>
</div><?php render('Auftrag '.$o['order_no'],ob_get_clean());exit;
}
if(preg_match('#^/auftrag/(\d{8})/nachweis$#',$path,$m)&&$method==='POST'){
 $s=require_seller();
 $st=db()->prepare("SELECT o.*,f.category_id FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.order_no=? AND o.seller_id=?");$st->execute([$m[1],$s['id']]);$o=$st->fetch();if(!$o)not_found();
 if(post('type')==='precheck' && $o['status']!=='precheck'){flash('error','Die Vorabkontrolle ist bereits beendet.');redirect('/auftrag/'.$o['order_no']);}

 try{
   $runId=current_run_id((int)$o['id']);
   $attrs=[];
   if(post('type')==='precheck'){
      $fields=db()->prepare("SELECT * FROM category_fields WHERE category_id=? AND is_active=1 ORDER BY sort_order,id");$fields->execute([$o['category_id']]);
      foreach($fields->fetchAll() as $fld){
        $key=$fld['field_key'];$raw=$_POST['attr'][$key]??null;
        if($fld['field_type']==='multiselect'){
          $value=array_values(array_filter(array_map('trim',(array)$raw),fn($v)=>$v!==''));
        }else{
          $value=is_array($raw)?'':trim((string)$raw);
        }
        if($fld['required'] && ($value==='' || $value===[])){throw new RuntimeException('Pflichtfeld „'.$fld['label'].'“ muss ausgefüllt werden.');}
        if($value!=='' && $value!==[]) $attrs[$key]=$value;
      }
   }

   $up=private_upload($_FILES['evidence']??[],'order-'.$o['id']);
   if(post('type')==='precheck'){
      $q=db()->prepare("SELECT * FROM order_items WHERE order_id=? AND order_run_id<=>? ORDER BY id DESC LIMIT 1");$q->execute([$o['id'],$runId]);$item=$q->fetch();
      $values=[post('item_label'),post('size_value'),post('color_value'),post('brand_value'),post('material_value'),$attrs?json_encode($attrs,JSON_UNESCAPED_UNICODE):null];
      if($item){
        db()->prepare("UPDATE order_items SET label=?,size_value=?,color_value=?,brand_value=?,material_value=?,attributes_json=? WHERE id=? AND locked_at IS NULL")
          ->execute([...$values,$item['id']]);
      }else{
        db()->prepare("INSERT INTO order_items(order_id,order_run_id,label,size_value,color_value,brand_value,material_value,attributes_json,locked_at) VALUES(?,?,?,?,?,?,?,?,NULL)")
          ->execute([$o['id'],$runId,...$values]);
      }
   }
   db()->prepare("INSERT INTO evidences(order_id,order_run_id,seller_id,evidence_type,file_path,mime_type,file_size,sha256) VALUES(?,?,?,?,?,?,?,?)")
     ->execute([$o['id'],$runId,$s['id'],post('type','precheck'),$up['path'],$up['mime'],$up['size'],$up['sha256']]);
   flash('success','Nachweis und Artikeldaten wurden sicher gespeichert.');
 }catch(Throwable $e){flash('error',$e->getMessage());}
 redirect('/auftrag/'.$o['order_no']);
}
if($path==='/admin/login'&&$method==='GET'){
 ob_start();?><section class="legal"><div class="eyebrow">Administration</div><h1>Admin-Login</h1><form method="post" class="panel"><?=csrf_field()?><label>E-Mail<input type="email" name="email" required></label><label>Passwort<input type="password" name="password" required></label><button class="btn">Anmelden</button></form></section><?php render('Admin Login',ob_get_clean());exit;
}
if($path==='/admin/login'&&$method==='POST'){
 $email=strtolower(post('email'));
 if(!rate_limit_consume('admin-login',$email,5,900,1800)){flash('error','Zu viele Anmeldeversuche. Bitte später erneut versuchen.');redirect('/admin/login');}
 $st=db()->prepare("SELECT * FROM admins WHERE email=?");$st->execute([$email]);$a=$st->fetch();if(!$a||!password_verify((string)($_POST['password']??''),$a['password_hash'])){flash('error','Login fehlgeschlagen.');redirect('/admin/login');}
 rate_limit_clear('admin-login',$email);
 session_regenerate_id(true);$_SESSION['admin_id']=$a['id'];redirect('/admin');
}
if($path==='/admin/logout'){unset($_SESSION['admin_id']);session_regenerate_id(true);redirect('/admin/login');}
if($path==='/admin'&&$method==='GET'){
 require_admin();$counts=['offers'=>(int)db()->query("SELECT COUNT(*) FROM offers WHERE status='active'")->fetchColumn(),'sellers'=>(int)db()->query("SELECT COUNT(*) FROM sellers WHERE deleted_at IS NULL")->fetchColumn(),'orders'=>(int)db()->query("SELECT COUNT(*) FROM orders WHERE status NOT IN('completed','rejected','archived')")->fetchColumn(),'prechecks'=>(int)db()->query("SELECT COUNT(*) FROM evidences e JOIN orders o ON o.id=e.order_id WHERE e.evidence_type='precheck' AND e.status='submitted' AND o.status='precheck'")->fetchColumn()];
 ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Heute</h1></div><div class="actions"><a class="btn" href="<?=e(url('/admin/angebote'))?>">Angebote</a><a class="btn secondary" href="<?=e(url('/admin/kategorien'))?>">Kategorien</a><a class="btn secondary" href="<?=e(url('/admin/auftraege'))?>">Aufträge</a><a class="btn secondary" href="<?=e(url('/admin/verkaeuferinnen'))?>">Verkäuferinnen</a><a class="btn secondary" href="<?=e(url('/admin/auszahlungen'))?>">Auszahlungen</a><a class="btn secondary" href="<?=e(url('/admin/einstellungen'))?>">Einstellungen</a></div></div><div class="grid"><div class="card"><div class="meta">Aktive Angebote</div><div class="stat"><?=$counts['offers']?></div></div><div class="card"><div class="meta">Verkäuferinnen</div><div class="stat"><?=$counts['sellers']?></div></div><div class="card"><div class="meta">Aktive Aufträge</div><div class="stat"><?=$counts['orders']?></div></div></div><h2>Sofort bearbeiten</h2><div class="card"><strong><?=$counts['prechecks']?> offene Vorabnachweise</strong><p class="meta">Noch nicht geprüfte Vorabkontrollen priorisiert bearbeiten.</p><a href="<?=e(url('/admin/auftraege'))?>">Aufträge öffnen →</a></div><?php render('Admin Dashboard',ob_get_clean());exit;
}
if($path==='/admin/kategorien'&&$method==='GET'){
 require_admin();$cats=db()->query("SELECT * FROM categories ORDER BY sort_order,name")->fetchAll();ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Kategorien</h1></div></div><form method="post" class="panel form-grid"><?=csrf_field()?><input name="name" placeholder="Neue Kategorie" required><button class="btn">Kategorie anlegen</button></form><br><div class="table-wrap"><table><thead><tr><th>Name</th><th>System</th><th>Status</th><th></th></tr></thead><tbody><?php foreach($cats as $c):?><tr><td><?=e($c['name'])?></td><td><?=$c['is_system']?'Ja':'Nein'?></td><td><?=$c['is_active']?'Aktiv':'Deaktiviert'?></td><td><a href="<?=e(url('/admin/kategorie/'.$c['id']))?>">Bearbeiten / Felder</a></td></tr><?php endforeach;?></tbody></table></div><?php render('Kategorien',ob_get_clean());exit;
}
if($path==='/admin/kategorien'&&$method==='POST'){
 require_admin();$name=post('name');$slug=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',strtr($name,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'])),'-'));db()->prepare("INSERT INTO categories(name,slug,is_system,is_active,sort_order) VALUES(?,?,0,1,999)")->execute([$name,$slug]);flash('success','Kategorie angelegt.');redirect('/admin/kategorien');
}
if($path==='/admin/angebote'&&$method==='GET'){
 require_admin();
 $offers=db()->query("SELECT o.*,c.name category_name FROM offers o JOIN categories c ON c.id=o.category_id ORDER BY o.created_at DESC")->fetchAll();
 $cats=db()->query("SELECT id,name FROM categories WHERE is_active=1 ORDER BY sort_order,name")->fetchAll();
 ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Angebote</h1></div></div>
 <form method="post" class="panel"><?=csrf_field()?>
   <div class="form-grid">
     <label>Titel<input name="title" required></label>
     <label>Kategorie<select name="category_id"><?php foreach($cats as $cat):?><option value="<?=$cat['id']?>"><?=e($cat['name'])?></option><?php endforeach;?></select></label>
     <label>Vergütung (€)<input type="number" step=".01" min="0" name="compensation" required></label>
     <label>Dauer in Tagen<input type="number" min="1" name="duration_days"></label>
     <label>Erfüllungsart<select name="fulfillment_type"><option value="days">Tage</option><option value="units">Einheiten</option><option value="one_time">Einmalig</option><option value="digital">Digital</option><option value="mixed">Kombiniert</option></select></label>
     <label>Status<select name="status"><option value="draft">Entwurf</option><option value="active">Aktiv</option><option value="inactive">Deaktiviert</option></select></label>
   </div>
   <label>Beschreibung<textarea name="description" required></textarea></label>
   <h3>Nachweise</h3>
   <div class="form-grid">
     <label>Pflichtfotos Vorabkontrolle<input type="number" min="1" max="50" name="precheck_required_count" value="1" required></label>
     <label>Morgen 06:00–10:00<input type="number" min="0" max="20" name="morning_count" value="1" required></label>
     <label>Mittag 12:00–16:00<input type="number" min="0" max="20" name="midday_count" value="1" required></label>
     <label>Abend 18:00–23:59<input type="number" min="0" max="20" name="evening_count" value="1" required></label>
   </div>
   <button class="btn">Angebot anlegen</button>
 </form><br>
 <div class="table-wrap"><table><thead><tr><th>Titel</th><th>Kategorie</th><th>Version</th><th>Status</th><th>Vergütung</th><th></th></tr></thead><tbody><?php foreach($offers as $o):?><tr><td><?=e($o['title'])?></td><td><?=e($o['category_name'])?></td><td>V<?=e($o['current_version'])?></td><td><?=e($o['status'])?></td><td><?=money($o['compensation'])?></td><td><a href="<?=e(url('/admin/angebot/'.$o['id']))?>">Bearbeiten</a></td></tr><?php endforeach;?></tbody></table></div>
 <?php render('Angebote verwalten',ob_get_clean());exit;
}
if($path==='/admin/angebote'&&$method==='POST'){
 require_admin();
 $title=post('title');
 $slug=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',strtr($title,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'])),'-')).'-'.substr(bin2hex(random_bytes(3)),0,6);
 $comp=max(0,(float)post('compensation'));
 $days=post('duration_days')!==''?max(1,(int)post('duration_days')):null;
 $type=post('fulfillment_type',$days?'days':'one_time');
 $rules=[
   'precheck_required_count'=>max(1,(int)post('precheck_required_count','1')),
   'daily'=>[
     'morning'=>max(0,(int)post('morning_count','1')),
     'midday'=>max(0,(int)post('midday_count','1')),
     'evening'=>max(0,(int)post('evening_count','1')),
   ],
 ];
 $rulesJson=json_encode($rules,JSON_UNESCAPED_UNICODE);
 db()->beginTransaction();
 try{
   db()->prepare("INSERT INTO offers(category_id,title,slug,description,compensation,duration_days,fulfillment_type,evidence_rules_json,status) VALUES(?,?,?,?,?,?,?,?,?)")
     ->execute([(int)post('category_id'),$title,$slug,post('description'),$comp,$days,$type,$rulesJson,post('status','draft')]);
   $id=(int)db()->lastInsertId();
   $snap=json_encode(['title'=>$title,'category_id'=>(int)post('category_id'),'description'=>post('description'),'compensation'=>$comp,'duration_days'=>$days,'fulfillment_type'=>$type,'evidence_rules'=>$rules,'status'=>post('status','draft')],JSON_UNESCAPED_UNICODE);
   db()->prepare("INSERT INTO offer_versions(offer_id,version_no,snapshot_json) VALUES(?,1,?)")->execute([$id,$snap]);
   db()->commit();
 }catch(Throwable $e){db()->rollBack();throw $e;}
 flash('success','Angebot mit Nachweisplan angelegt.');
 redirect('/admin/angebot/'.$id);
}
if($path==='/admin/auftraege'&&$method==='GET'){
 require_admin();$orders=db()->query("SELECT o.*,f.title,CONCAT(s.first_name,' ',s.last_name) seller_name FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id ORDER BY o.created_at DESC")->fetchAll();ob_start();?><div class="dashboard-head"><div><div class="eyebrow">Administration</div><h1>Aufträge</h1></div></div><div class="table-wrap"><table><thead><tr><th>Nr.</th><th>Verkäuferin</th><th>Auftrag</th><th>Status</th><th>Wert</th><th></th></tr></thead><tbody><?php foreach($orders as $o):?><tr><td><?=e($o['order_no'])?></td><td><?=e($o['seller_name'])?></td><td><?=e($o['title'])?></td><td><?=e($o['status'])?></td><td><?=money($o['total_compensation'])?></td><td><a href="<?=e(url('/admin/auftrag/'.$o['order_no']))?>">Prüfen</a></td></tr><?php endforeach;?></tbody></table></div><?php render('Aufträge',ob_get_clean());exit;
}
if(preg_match('#^/admin/auftrag/(\\d{8})$#',$path,$m)&&$method==='GET'){
 require_admin();
 $st=db()->prepare("SELECT o.*,f.title,f.fulfillment_type,CONCAT(s.first_name,' ',s.last_name) seller_name,s.email FROM orders o JOIN offers f ON f.id=o.offer_id JOIN sellers s ON s.id=o.seller_id WHERE o.order_no=?");
 $st->execute([$m[1]]);$o=$st->fetch();if(!$o)not_found();
 $ev=db()->prepare("SELECT * FROM evidences WHERE order_id=? ORDER BY created_at");$ev->execute([$o['id']]);$evidences=$ev->fetchAll();
 $rtq=db()->prepare("SELECT r.*,e.evidence_type original_type,e.day_no original_day,e.window_key original_window FROM evidence_retake_requests r JOIN evidences e ON e.id=r.original_evidence_id WHERE r.order_id=? ORDER BY r.created_at DESC");$rtq->execute([$o['id']]);$retakeRequests=$rtq->fetchAll();
 $dc=db()->prepare("SELECT * FROM damage_cases WHERE order_id=? ORDER BY created_at DESC");$dc->execute([$o['id']]);$damageCases=$dc->fetchAll();
 $vi=db()->prepare("SELECT * FROM violations WHERE order_id=? ORDER BY created_at DESC");$vi->execute([$o['id']]);$violations=$vi->fetchAll();
 $xd=db()->prepare("SELECT * FROM extra_days WHERE order_id=? ORDER BY created_at");$xd->execute([$o['id']]);$extraDays=$xd->fetchAll();
 $sh=db()->prepare("SELECT * FROM shipments WHERE order_id=?");$sh->execute([$o['id']]);$shipment=$sh->fetch();
 $adminShippingSnapshot=order_shipping_snapshot($o);
 $srq=db()->prepare("SELECT * FROM spontaneous_requests WHERE order_id=? ORDER BY created_at DESC");$srq->execute([$o['id']]);$spontaneousRequests=$srq->fetchAll();
 $otq=db()->prepare("SELECT * FROM order_tasks WHERE order_id=? ORDER BY created_at DESC");$otq->execute([$o['id']]);$orderTasks=$otq->fetchAll();
 $taskTemplates=db()->query("SELECT id,title,default_compensation FROM task_library WHERE active=1 ORDER BY title")->fetchAll();
 $sdq=db()->prepare("SELECT * FROM order_start_date_requests WHERE order_id=? ORDER BY created_at DESC");$sdq->execute([$o['id']]);$adminStartDateRequests=$sdq->fetchAll();
 $oiq=db()->prepare("SELECT * FROM order_items WHERE order_id=? AND order_run_id<=>? ORDER BY id DESC LIMIT 1");$oiq->execute([$o['id'],current_run_id((int)$o['id'])]);$adminOrderItem=$oiq->fetch()?:null;
 $adminItemAttrs=$adminOrderItem?(json_decode($adminOrderItem['attributes_json']??'{}',true)?:[]):[];
 $cfq=db()->prepare("SELECT * FROM category_fields WHERE category_id=(SELECT category_id FROM offers WHERE id=?) AND is_active=1 ORDER BY sort_order,id");$cfq->execute([$o['offer_id']]);$adminCategoryFields=$cfq->fetchAll();
 $opq=db()->prepare("SELECT * FROM offer_options WHERE offer_id=? ORDER BY id");$opq->execute([$o['offer_id']]);$adminAvailableOptions=$opq->fetchAll();
 $ooq=db()->prepare("SELECT * FROM order_options WHERE order_id=? ORDER BY id");$ooq->execute([$o['id']]);$adminSelectedOptions=$ooq->fetchAll();$adminSelectedOptionIds=array_map(fn($x)=>(int)$x['offer_option_id'],$adminSelectedOptions);
 $boq=db()->prepare("SELECT * FROM order_bonuses WHERE order_id=? ORDER BY created_at DESC");$boq->execute([$o['id']]);$adminBonuses=$boq->fetchAll();
 $dv=db()->prepare("SELECT * FROM digital_versions WHERE order_id=? ORDER BY version_no DESC");$dv->execute([$o['id']]);$digitalVersions=$dv->fetchAll();
 $rv=db()->prepare("SELECT i.*,r.round_no,r.status round_status,r.due_at FROM revision_items i JOIN revision_rounds r ON r.id=i.revision_round_id WHERE r.order_id=? ORDER BY r.round_no DESC,i.id");$rv->execute([$o['id']]);$revisionItems=$rv->fetchAll();
 $currentRunId=current_run_id((int)$o['id']);$rules=offer_evidence_rules((int)$o['id']);$preRequired=max(1,(int)$rules['precheck_required_count']);
 $preTotal=0;$preAccepted=0;foreach($evidences as $x){if($x['evidence_type']==='precheck'&&(int)($x['order_run_id']??0)===(int)$currentRunId){$preTotal++;if($x['status']==='accepted')$preAccepted++;}}
 ob_start();?>
 <div class="dashboard-head"><div><div class="eyebrow">Auftrag <?=e($o['order_no'])?></div><h1><?=e($o['title'])?></h1><p class="meta"><?=e($o['seller_name'])?> · <?=e($o['email'])?></p><?php if($o['archived_at']):?><p><span class="badge">ARCHIVIERT · <?=e(date('d.m.Y H:i',strtotime($o['archived_at'])))?></span></p><?php endif;?></div><div><span class="badge"><?=e($o['status'])?></span><div class="price"><?=money($o['total_compensation'])?></div><?php if(!$o['archived_at'] && in_array($o['status'],['completed','rejected'],true)):?><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/archivieren'))?>" style="margin-top:8px"><?=csrf_field()?><button class="btn secondary">Archivieren</button></form><?php elseif($o['archived_at'] && $o['status']!=='rejected'):?><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/wiederherstellen'))?>" style="margin-top:8px"><?=csrf_field()?><button class="btn secondary">Wiederherstellen</button></form><?php endif;?></div></div><?php if($o['archived_at']):?><div class="panel"><strong>Dieser Auftrag ist archiviert und vollständig schreibgeschützt.</strong></div><?php endif;?>
 <div class="grid two">
   <section class="panel"><h2>Nachweise</h2>
   <?php foreach($evidences as $evd):?><div class="timeline"><div><strong><?=e($evd['evidence_type'])?></strong> · <?=e(date('d.m.Y H:i',strtotime($evd['created_at'])))?> · <span class="badge"><?=e($evd['status'])?></span><div class="actions" style="margin-top:8px"><a class="btn secondary" target="_blank" href="<?=e(url('/datei/'.$evd['id']))?>">Ansehen</a><?php if($evd['status']==='submitted'):?><form method="post" action="<?=e(url('/admin/nachweis/'.$evd['id'].'/freigeben'))?>"><?=csrf_field()?><button class="btn">Freigeben</button></form><details><summary class="btn danger">Beanstanden</summary><form method="post" action="<?=e(url('/admin/nachweis/'.$evd['id'].'/ablehnen'))?>" style="margin-top:10px;min-width:280px"><?=csrf_field()?>
<label>Beanstandungsgrund<input name="reason" placeholder="z. B. falscher Bildausschnitt" required></label>
<label>Neuaufnahme-Frist (optional)<input type="datetime-local" name="retake_due_at" min="<?=e(date('Y-m-d\TH:i'))?>"></label>
<label>Anweisung für Neuaufnahme<textarea name="retake_instructions" placeholder="Was genau soll neu aufgenommen werden?"></textarea></label>
<label style="display:flex;gap:8px"><input type="checkbox" style="width:auto" name="create_violation" value="1" checked> Möglichen Verstoß mit provisorischem Zusatztag anlegen</label>
<label style="display:flex;gap:8px"><input type="checkbox" style="width:auto" name="cure_violation" value="1" checked> Erfolgreiche Neuaufnahme hebt den offenen Verstoß automatisch auf</label>
<button class="btn danger">Beanstandung speichern</button></form></details><?php elseif($evd['status']==='rejected'):?><span class="meta"><?=e($evd['rejection_reason']??'')?></span><?php endif;?></div></div></div><?php endforeach;?>
   <?php if(!$evidences):?><p class="meta">Noch keine Nachweise.</p><?php endif;?>
   <?php if($retakeRequests):?><hr><h3>Neuaufnahmen</h3><div class="timeline"><?php foreach($retakeRequests as $rt):?><div><strong><?=e($rt['original_type'])?><?= $rt['original_day']?' · Tag '.e($rt['original_day']):'' ?><?= $rt['original_window']?' · '.e($rt['original_window']):'' ?></strong> · <span class="badge"><?=e($rt['status'])?></span><p><?=e($rt['instructions'])?></p><span class="meta">Frist <?=e(date('d.m.Y H:i',strtotime($rt['due_at'])))?> · Nachfrist bis <?=e(date('d.m.Y H:i',strtotime($rt['grace_ends_at'])))?><?=$rt['cure_violation_on_success']?' · erfolgreicher Retake kann offenen Verstoß heilen':''?></span></div><?php endforeach;?></div><?php endif;?></section>
   <section class="panel"><h2>Vorabkontrolle & Start</h2><p>Akzeptierte Pflichtnachweise im aktuellen Durchlauf: <strong><?=$preAccepted?> / <?=$preRequired?></strong></p>
   <p>Geplantes Startdatum: <strong><?=e($o['planned_start_date']?date('d.m.Y',strtotime($o['planned_start_date'])):'noch nicht festgelegt')?></strong></p>
   <?php if($o['precheck_approved_at']):?><p><span class="badge">VORABKONTROLLE FREIGEGEBEN</span><br><span class="meta">Freigegeben am <?=e(date('d.m.Y H:i',strtotime($o['precheck_approved_at'])))?>. Der Auftrag startet automatisch am vereinbarten Datum.</span></p><?php endif;?>
   <?php if($o['status']==='precheck' && !$o['precheck_approved_at']):?>
     <?php if(!$o['planned_start_date']):?><p class="meta">Die Verkäuferin muss zuerst ein Startdatum festlegen.</p>
     <?php elseif($preAccepted >= $preRequired && $preTotal===$preAccepted):?><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/vorabkontrolle-freigeben'))?>"><?=csrf_field()?><button class="btn">Vorabkontrolle vollständig freigeben</button></form>
     <?php else:?><p class="meta">Die Vorabkontrolle kann erst vollständig freigegeben werden, wenn alle Pflichtnachweise einzeln akzeptiert sind.</p><?php endif;?>
   <?php elseif($o['status']!=='precheck'):?><p class="meta">Gestartet: <?=e($o['started_at']?date('d.m.Y H:i',strtotime($o['started_at'])):'–')?></p><?php endif;?>
   <?php if($adminStartDateRequests):?><hr><h3>Startdatumsänderungen</h3><div class="timeline"><?php foreach($adminStartDateRequests as $r):?><div><strong><?=e(date('d.m.Y',strtotime($r['requested_date'])))?></strong> · <span class="badge"><?=e($r['status'])?></span><p><?=e($r['reason'])?></p><?php if($r['admin_note']):?><p class="meta">Adminnotiz: <?=e($r['admin_note'])?></p><?php endif;?><?php if($r['status']==='pending' && $o['status']==='precheck'):?><div class="actions"><form method="post" action="<?=e(url('/admin/startdatum/'.$r['id'].'/genehmigen'))?>"><?=csrf_field()?><input name="admin_note" placeholder="Notiz optional"><button class="btn">Genehmigen</button></form><form method="post" action="<?=e(url('/admin/startdatum/'.$r['id'].'/ablehnen'))?>"><?=csrf_field()?><input name="admin_note" placeholder="Begründung optional"><button class="btn secondary">Ablehnen</button></form></div><?php endif;?></div><?php endforeach;?></div><?php endif;?>
   <hr><div class="actions"><a class="btn secondary" href="<?=e(url('/admin/auftrag/'.$o['order_no'].'/chat'))?>">Auftragschat</a><a class="btn secondary" href="<?=e(url('/admin/auftrag/'.$o['order_no'].'/zwischenstaende'))?>">Zwischenstände</a></div></section>
 </div>
 <div class="grid two" style="margin-top:18px">
   <section class="panel"><h2>Konkreter Artikel</h2>
   <?php if($adminOrderItem):?><p><strong><?=e($adminOrderItem['label'])?></strong></p><div class="form-grid"><div><span class="meta">Größe</span><br><?=e($adminOrderItem['size_value']?:'–')?></div><div><span class="meta">Farbe</span><br><?=e($adminOrderItem['color_value']?:'–')?></div><div><span class="meta">Marke</span><br><?=e($adminOrderItem['brand_value']?:'–')?></div><div><span class="meta">Material</span><br><?=e($adminOrderItem['material_value']?:'–')?></div></div>
   <?php foreach($adminCategoryFields as $fld): $v=$adminItemAttrs[$fld['field_key']]??null; if($v!==null && $v!==''):?><p><span class="meta"><?=e($fld['label'])?></span><br><?=e(is_array($v)?implode(', ',$v):($v==='1'?'Ja':($v==='0'?'Nein':$v)))?></p><?php endif; endforeach;?>
   <p class="meta">Status: <?=$adminOrderItem['locked_at']?'seit Auftragsstart gesperrt':'bis Auftragsstart bearbeitbar'?></p>
   <?php else:?><p class="meta">Noch kein konkreter Artikel hinterlegt.</p><?php endif;?></section>
   <section class="panel"><h2>Zusatzoptionen</h2>
   <?php if(!$o['archived_at'] && in_array($o['status'],['precheck','running','shipping','review','payout'],true)):?><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/optionen'))?>"><?=csrf_field()?>
   <?php foreach($adminAvailableOptions as $opt):?><label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" style="width:auto;margin-top:5px" name="option_ids[]" value="<?=e($opt['id'])?>" <?=in_array((int)$opt['id'],$adminSelectedOptionIds,true)?'checked':''?>><span><?=e($opt['label'])?> · <?=$opt['price']>0?('+'.money($opt['price'])):'kostenlos'?><?=$opt['active']?'':' · deaktivierte Angebotsoption'?></span></label><?php endforeach;?>
   <?php if($adminAvailableOptions):?><button class="btn secondary">Optionen als Admin aktualisieren</button><?php else:?><p class="meta">Keine Optionen vorhanden.</p><?php endif;?></form>
   <?php else:?><div class="timeline"><?php foreach($adminSelectedOptions as $opt):?><div><?=e($opt['label_snapshot'])?> · <?=money($opt['price_snapshot'])?></div><?php endforeach;?></div><?php endif;?>
   <p><strong>Gesamtwert: <?=money($o['total_compensation'])?></strong></p>
   <hr><h3>Bonus</h3>
   <?php if(!$o['archived_at'] && in_array($o['status'],['precheck','running','shipping','review','payout'],true)):?><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/bonus'))?>"><?=csrf_field()?><div class="form-grid"><label>Betrag (€)<input type="number" name="amount" step=".01" min=".01" required></label><label>Hinweis (optional)<input name="note"></label></div><button class="btn">Bonus vormerken</button></form><?php endif;?>
   <?php if($adminBonuses):?><div class="timeline" style="margin-top:12px"><?php foreach($adminBonuses as $b):?><div><strong><?=money($b['amount'])?></strong> · <?=e($b['status'])?><?= $b['note']?' · '.e($b['note']):'' ?><?php if($b['status']==='reserved' && !$o['archived_at']):?><form method="post" action="<?=e(url('/admin/bonus/'.$b['id'].'/entfernen'))?>" style="margin-top:6px"><?=csrf_field()?><button class="btn secondary">Bonus entfernen</button></form><?php endif;?></div><?php endforeach;?></div><?php endif;?>
   </section>
 </div>
 <div class="grid two" style="margin-top:18px">
   <section class="panel"><h2>Zusatztage & Verstöße</h2><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/zusatztag'))?>"><?=csrf_field()?><label><input type="checkbox" name="paid" value="1" style="width:auto"> bezahlt</label><label>Betrag (€)<input type="number" step=".01" min="0" name="amount" value="0"></label><label>Grund (optional)<input name="reason"></label><button class="btn">Manuellen Zusatztag hinzufügen</button></form><hr><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/verstoss'))?>"><?=csrf_field()?><label>Typ<input name="violation_type" value="manual"></label><label>Grund<textarea name="reason" required></textarea></label><button class="btn danger">Verstoß bestätigen (+1 Tag)</button></form>
   <?php if($extraDays):?><h3>Zusätzliche Tage</h3><div class="timeline"><?php foreach($extraDays as $x):?><div><?=e($x['source_type'])?> · <strong><?=e($x['status']??'confirmed')?></strong> · <?=$x['paid']?('bezahlt '.money($x['amount'])):'unbezahlt'?><?= $x['reason']?' · '.e($x['reason']):'' ?></div><?php endforeach;?></div><?php endif;?>
   <?php if($violations):?><h3>Verstöße</h3><div class="timeline"><?php foreach($violations as $v):?><div><strong><?=e($v['status'])?></strong> · <?=e($v['reason']??$v['violation_type'])?><?php if(in_array($v['status'],['open','reviewed'],true)):?><div class="actions" style="margin-top:8px"><form method="post" action="<?=e(url('/admin/verstoss/'.$v['id'].'/bestaetigen'))?>"><?=csrf_field()?><button class="btn danger">Bestätigen (+1 Tag)</button></form><form method="post" action="<?=e(url('/admin/verstoss/'.$v['id'].'/verwerfen'))?>"><?=csrf_field()?><button class="btn secondary">Verwerfen</button></form></div><?php endif;?></div><?php endforeach;?></div><?php endif;?></section>
   <section class="panel"><h2>Versand / Digital / Abschluss</h2>
   <?php if($shipment):?><div class="timeline"><div><strong>Sendung: <?=e($shipment['status'])?></strong><?php if($shipment['tracking_number']):?><br>Tracking: <?=e($shipment['tracking_number'])?><?php endif;?><?php if($shipment['carrier']):?><br>Dienstleister: <?=e($shipment['carrier'])?><?php endif;?><?php if($shipment['claimed_shipping_cost']!==null):?><br>Beantragte Versandkostenerstattung: <?=money($shipment['claimed_shipping_cost'])?><?php endif;?><?php if($shipment['approved_reimbursement']!==null):?><br><span class="badge ok">Erstattung freigegeben: <?=money($shipment['approved_reimbursement'])?></span><?php endif;?><?php if($shipment['proof_evidence_id']):?><br><a href="<?=e(url('/datei/'.$shipment['proof_evidence_id']))?>" target="_blank">Versandbeleg ansehen</a><?php endif;?></div></div>
   <?php if(($adminShippingSnapshot['cost_mode']??'seller')==='reimburse' && $shipment['claimed_shipping_cost']!==null && $shipment['approved_reimbursement']===null):?><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/versanderstattung'))?>" style="margin-top:10px"><?=csrf_field()?><button class="btn">Versandkosten <?=money($shipment['claimed_shipping_cost'])?> freigeben</button></form><?php endif;?><hr><?php endif;?>
   <?php if($shipment):?><div class="timeline"><div><strong>Sendung: <?=e($shipment['status'])?></strong><?php if($shipment['carrier']):?> · <?=e($shipment['carrier'])?><?php endif;?><?php if($shipment['tracking_number']):?> · <?=e($shipment['tracking_number'])?><?php endif;?><?php if($shipment['proof_evidence_id']):?><br><a href="<?=e(url('/datei/'.$shipment['proof_evidence_id']))?>" target="_blank">Versand-/Kostenbeleg ansehen</a><?php endif;?></div></div><?php endif;?>
   <?php if($shipment && ($adminShippingSnapshot['cost_mode']??'seller')==='reimburse' && $shipment['claimed_shipping_cost']!==null):?>
     <div class="panel" style="margin:12px 0"><h3>Versandkostenerstattung</h3><p>Beantragt: <strong><?=money($shipment['claimed_shipping_cost'])?></strong><br>Bestätigt: <strong><?=$shipment['approved_reimbursement']!==null?money($shipment['approved_reimbursement']):'noch offen'?></strong></p>
     <?php if(!$o['archived_at'] && !in_array($o['status'],['completed','rejected'],true) && $shipment['approved_reimbursement']===null):?>
       <div class="actions"><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/versanderstattung'))?>"><?=csrf_field()?><input type="hidden" name="decision" value="approve"><button class="btn">Versandkosten vollständig erstatten</button></form><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/versanderstattung'))?>"><?=csrf_field()?><input type="hidden" name="decision" value="reject"><button class="btn secondary">Erstattung ablehnen</button></form></div>
     <?php endif;?></div>
   <?php endif;?>
   <?php if($shipment && $shipment['status']==='shipped'):?><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/wareneingang'))?>"><?=csrf_field()?><button class="btn secondary">Wareneingang bestätigen</button></form><hr><?php endif;?>
   <h3>Revision anfordern</h3><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/revision'))?>"><?=csrf_field()?><label>Änderungspunkte – eine Zeile je Punkt<textarea name="items" required></textarea></label><label>Frist (optional)<input type="datetime-local" name="due_at"></label><button class="btn secondary">Revision anfordern</button></form><hr>
   <h3>Abschlussentscheidung</h3><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/abschliessen'))?>"><?=csrf_field()?><label>Entscheidung<select name="decision"><option value="accept">Vollständig akzeptieren</option><option value="partial">Teilweise akzeptieren</option><option value="reject">Endgültig ablehnen</option></select></label><label>Freigabebetrag bei Teilannahme (€)<input type="number" name="partial_amount" step=".01" min="0" max="<?=e($o['total_compensation'])?>"></label><label>Begründung / Mitteilung<textarea name="reason"></textarea></label><button class="btn">Abschluss speichern</button></form></section>
 </div>
 <div class="grid two" style="margin-top:18px">
   <section class="panel"><h2>Spontaner Nachweis</h2>
     <form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/spontan'))?>"><?=csrf_field()?>
       <label>Anweisung<textarea name="instructions" required></textarea></label>
       <div class="form-grid"><label>Anzahl Fotos<input type="number" min="1" max="20" name="required_count" value="1" required></label><label>Frist<input type="datetime-local" name="due_at" required></label></div>
       <button class="btn">Anforderung senden</button>
     </form>
     <?php if($spontaneousRequests):?><h3>Bisherige Anforderungen</h3><div class="timeline"><?php foreach($spontaneousRequests as $r):?><div><strong><?=e($r['status'])?></strong> · <?=e($r['instructions'])?><br><span class="meta"><?=e(date('d.m.Y H:i',strtotime($r['due_at'])))?> · <?=e($r['required_count'])?> Foto(s)</span><?php if($r['status']==='uploaded'):?><form method="post" action="<?=e(url('/admin/spontan/'.$r['id'].'/abschliessen'))?>" style="margin-top:8px"><?=csrf_field()?><button class="btn secondary">Als vollständig geprüft abschließen</button></form><?php endif;?></div><?php endforeach;?></div><?php endif;?>
   </section>
   <section class="panel"><h2>Zusatzaufgabe</h2>
     <form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/aufgabe'))?>"><?=csrf_field()?>
       <label>Titel<input name="title" required></label><label>Beschreibung<textarea name="description"></textarea></label>
       <div class="form-grid"><label>Antworttyp<select name="response_type"><option value="text">Freitext</option><option value="number">Zahl</option><option value="scale10">Skala 1–10</option><option value="boolean">Ja/Nein</option></select></label><label>Frist<input type="datetime-local" name="due_at"></label><label>Pflichtfotos<input type="number" min="0" max="20" name="required_photos" value="0"></label><label>Zusatzvergütung (€)<input type="number" step=".01" min="0" name="compensation" value="0"></label><label><input type="checkbox" style="width:auto" name="violation_enabled" value="1" checked> Nichterfüllung kann Verstoß auslösen</label></div>
       <button class="btn">Aufgabe hinzufügen</button>
     </form>
     <?php if($taskTemplates):?><hr><form method="post" action="<?=e(url('/admin/auftrag/'.$o['order_no'].'/aufgabe-aus-vorlage'))?>"><?=csrf_field()?><label>Aus Aufgabenbibliothek<select name="template_id"><?php foreach($taskTemplates as $t):?><option value="<?=$t['id']?>"><?=e($t['title'])?> · <?=money($t['default_compensation'])?></option><?php endforeach;?></select></label><label>Frist<input type="datetime-local" name="due_at"></label><button class="btn secondary">Vorlage hinzufügen</button></form><?php endif;?>
     <?php if($orderTasks):?><h3>Aufgaben</h3><div class="timeline"><?php foreach($orderTasks as $t):?><div><strong><?=e($t['title'])?></strong> · <?=e($t['status'])?> · <?=money($t['compensation'])?><?php if($t['submission_json']):?><br><span class="meta">Antwort: <?=e((json_decode($t['submission_json'],true)['value']??'–'))?></span><?php endif;?><?php if($t['status']==='submitted'):?><div class="actions" style="margin-top:8px"><form method="post" action="<?=e(url('/admin/aufgabe/'.$t['id'].'/akzeptieren'))?>"><?=csrf_field()?><button class="btn">Akzeptieren</button></form><form method="post" action="<?=e(url('/admin/aufgabe/'.$t['id'].'/ablehnen'))?>"><?=csrf_field()?><button class="btn secondary">Zurückgeben</button></form></div><?php endif;?></div><?php endforeach;?></div><?php endif;?>
   </section>
 </div>
 <?php if($digitalVersions || $o['fulfillment_type']==='digital' || $o['fulfillment_type']==='mixed'):?>
 <h2>Digitale Versionen</h2>
 <div class="timeline">
 <?php foreach($digitalVersions as $dv):?>
   <article class="panel">
     <div class="dashboard-head"><div><strong>V<?=e($dv['version_no'])?></strong> · <span class="badge"><?=e($dv['status'])?></span></div><span class="meta"><?=e(date('d.m.Y H:i',strtotime($dv['created_at'])))?></span></div>
     <?php if($dv['text_content']):?><div style="white-space:pre-wrap"><?=e($dv['text_content'])?></div><?php endif;?>
     <?php if($dv['file_path']):?>
       <?php $mediaUrl=url('/digitale-datei/'.$dv['id']); $mime=(string)($dv['mime_type']??''); ?>
       <?php if(str_starts_with($mime,'audio/')):?><audio controls preload="metadata" style="width:100%"><source src="<?=e($mediaUrl)?>" type="<?=e($mime)?>"></audio>
       <?php elseif(str_starts_with($mime,'video/')):?><video controls preload="metadata" playsinline style="width:100%;max-height:520px;border-radius:12px"><source src="<?=e($mediaUrl)?>" type="<?=e($mime)?>"></video>
       <?php elseif(str_starts_with($mime,'image/')):?><img src="<?=e($mediaUrl)?>" alt="Digitale Version V<?=e($dv['version_no'])?>" style="max-width:100%;max-height:560px;border-radius:12px">
       <?php else:?><a class="btn secondary" target="_blank" href="<?=e($mediaUrl)?>">Datei öffnen</a><?php endif;?>
       
     <?php endif;?>
     <?php if($dv['review_note']):?><p><strong>Prüfnotiz:</strong> <?=e($dv['review_note'])?></p><?php endif;?>
     <?php if(!$o['archived_at']):?>
       <form method="post" action="<?=e(url('/admin/digital-version/'.$dv['id'].'/pruefen'))?>" style="margin-top:12px">
         <?=csrf_field()?>
         <div class="form-grid">
           <label>Bewertung<select name="decision"><option value="accepted">Akzeptiert</option><option value="revision_required">Revision erforderlich</option><option value="partial">Teilweise akzeptiert</option><option value="rejected">Abgelehnt</option></select></label>
           <label>Prüfnotiz<input name="review_note" value="<?=e($dv['review_note']??'')?>"></label>
         </div>
         <button class="btn secondary">Version bewerten</button>
       </form>
     <?php endif;?>
   </article>
 <?php endforeach;?>
 <?php if(!$digitalVersions):?><div class="empty">Noch keine digitale Version eingereicht.</div><?php endif;?>
 </div>
 <?php if($revisionItems):?><h3>Revisionspunkte</h3><div class="table-wrap"><table><thead><tr><th>Runde</th><th>Punkt</th><th>Status</th><th>Frist</th><th>Aktion</th></tr></thead><tbody><?php foreach($revisionItems as $ri):?><tr><td><?=e($ri['round_no'])?></td><td><?=e($ri['description'])?></td><td><?=e($ri['status'])?></td><td><?=e($ri['due_at']?date('d.m.Y H:i',strtotime($ri['due_at'])):'–')?></td><td><?php if(!$o['archived_at'] && $ri['status']!=='done'):?><div class="actions"><form method="post" action="<?=e(url('/admin/revisionspunkt/'.$ri['id'].'/erledigt'))?>"><?=csrf_field()?><button class="btn">Erledigt</button></form><form method="post" action="<?=e(url('/admin/revisionspunkt/'.$ri['id'].'/unzureichend'))?>"><?=csrf_field()?><button class="btn secondary">Unzureichend</button></form><form method="post" action="<?=e(url('/admin/revisionspunkt/'.$ri['id'].'/erneut'))?>"><?=csrf_field()?><button class="btn secondary">Erneut ändern</button></form></div><?php else:?>–<?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
 <?php endif;?>
 <?php if($damageCases):?><h2>Beschädigungsvorgänge</h2><div class="table-wrap"><table><thead><tr><th>Zeit</th><th>Grund</th><th>Status</th><th>Aktion</th></tr></thead><tbody><?php foreach($damageCases as $d):?><tr><td><?=e(date('d.m.Y H:i',strtotime($d['created_at'])))?></td><td><?=e($d['reason'])?></td><td><?=e($d['status'])?></td><td><?php if(in_array($d['status'],['reported','evidence_requested','review'],true)):?><div class="actions"><form method="post" action="<?=e(url('/admin/beschaedigung/'.$d['id'].'/anerkennen'))?>"><?=csrf_field()?><button class="btn">Anerkennen & neu starten</button></form><form method="post" action="<?=e(url('/admin/beschaedigung/'.$d['id'].'/ablehnen'))?>"><?=csrf_field()?><button class="btn danger">Ablehnen</button></form></div><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
 <?php render('Auftrag '.$o['order_no'],ob_get_clean());exit;
}
if(preg_match('#^/admin/nachweis/(\d+)/freigeben$#',$path,$m)&&$method==='POST'){
 require_admin();
 $st=db()->prepare("SELECT e.*,o.order_no,o.id order_id,o.seller_id FROM evidences e JOIN orders o ON o.id=e.order_id WHERE e.id=?");
 $st->execute([(int)$m[1]]);$ev=$st->fetch();if(!$ev)not_found();

 db()->beginTransaction();
 try{
   db()->prepare("UPDATE evidences SET status='accepted',reviewed_at=NOW(),rejection_reason=NULL WHERE id=?")->execute([$ev['id']]);

   $rq=db()->prepare("SELECT * FROM evidence_retake_requests WHERE replacement_evidence_id=? AND status IN('uploaded','rejected') ORDER BY id DESC LIMIT 1");
   $rq->execute([$ev['id']]);$retake=$rq->fetch();
   if($retake){
      db()->prepare("UPDATE evidence_retake_requests SET status='accepted',reviewed_at=NOW() WHERE id=?")->execute([$retake['id']]);
      if($retake['cure_violation_on_success'] && $retake['violation_id']){
         db()->prepare("UPDATE violations SET status='discarded',reviewed_at=NOW() WHERE id=? AND status IN('open','reviewed')")->execute([$retake['violation_id']]);
         db()->prepare("UPDATE extra_days SET status='cancelled' WHERE source_type='violation' AND source_id=? AND status='provisional'")->execute([$retake['violation_id']]);
      }
      db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
        ->execute([$ev['order_id'],'Neuaufnahme erfolgreich geprüft und akzeptiert.'.($retake['cure_violation_on_success']?' Ein noch offener, verknüpfter Verstoß wurde verworfen.':'')]);
   }
   db()->commit();
 }catch(Throwable $e){db()->rollBack();throw $e;}

 notify_seller((int)$ev['seller_id'],'evidence.accepted','Nachweis freigegeben','Ein Nachweis in Auftrag '.$ev['order_no'].' wurde freigegeben.','/auftrag/'.$ev['order_no'],null,true);
 flash('success',$ev['evidence_type']==='precheck'?'Vorabnachweis freigegeben. Der Auftrag startet erst nach ausdrücklicher Gesamtfreigabe.':'Nachweis freigegeben.');
 redirect('/admin/auftrag/'.$ev['order_no']);
}
if(preg_match('#^/admin/nachweis/(\d+)/ablehnen$#',$path,$m)&&$method==='POST'){
 require_admin();
 $st=db()->prepare("SELECT e.*,o.order_no,o.seller_id FROM evidences e JOIN orders o ON o.id=e.order_id WHERE e.id=?");
 $st->execute([(int)$m[1]]);$ev=$st->fetch();if(!$ev)not_found();
 $reason=post('reason');
 if($reason===''){flash('error','Bitte einen Ablehnungsgrund angeben.');redirect('/admin/auftrag/'.$ev['order_no']);}

 $retakeDue=post('retake_due_at');
 $retakeInstructions=post('retake_instructions');
 $createViolation=isset($_POST['create_violation']);
 $cureViolation=isset($_POST['cure_violation']);
 $violationId=null;$retakeId=null;
 $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));

 if($retakeDue!==''){
   try{$due=new DateTimeImmutable($retakeDue,$tz);}catch(Throwable){$due=false;}
   if(!$due || $due<=new DateTimeImmutable('now',$tz)){flash('error','Die Neuaufnahme-Frist muss in der Zukunft liegen.');redirect('/admin/auftrag/'.$ev['order_no']);}
 }

 db()->beginTransaction();
 try{
   db()->prepare("UPDATE evidences SET status='rejected',rejection_reason=?,reviewed_at=NOW() WHERE id=?")->execute([$reason,$ev['id']]);

   $existingRetake=db()->prepare("SELECT id FROM evidence_retake_requests WHERE replacement_evidence_id=? AND status='uploaded' ORDER BY id DESC LIMIT 1");
   $existingRetake->execute([$ev['id']]);$parentRetakeId=$existingRetake->fetchColumn();
   if($parentRetakeId){
      db()->prepare("UPDATE evidence_retake_requests SET status='rejected',reviewed_at=NOW() WHERE id=?")->execute([$parentRetakeId]);
   }

   if($createViolation){
      $violationId=ensure_provisional_violation((int)$ev['order_id'],'evidence-'.$ev['id'].'-insufficient','insufficient_evidence','Nachweis beanstandet: '.$reason);
   }

   if($retakeDue!==''){
      $due=new DateTimeImmutable($retakeDue,$tz);
      $grace=$due->modify('+'.max(0,(int)setting_value('grace_minutes','60')).' minutes');
      $instructions=$retakeInstructions!==''?$retakeInstructions:$reason;
      db()->prepare("INSERT INTO evidence_retake_requests(order_id,seller_id,original_evidence_id,violation_id,instructions,due_at,grace_ends_at,cure_violation_on_success) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$ev['order_id'],$ev['seller_id'],$ev['id'],$violationId,$instructions,$due->format('Y-m-d H:i:s'),$grace->format('Y-m-d H:i:s'),$cureViolation?1:0]);
      $retakeId=(int)db()->lastInsertId();
   }

   db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
     ->execute([$ev['order_id'],'Nachweis beanstandet: '.$reason.($retakeId?' Eine Neuaufnahme wurde mit eigener Frist angefordert.':'')]);
   db()->commit();
 }catch(Throwable $e){db()->rollBack();throw $e;}

 if($retakeId){
   notify_seller((int)$ev['seller_id'],'evidence.retake','Neuaufnahme erforderlich','Ein Nachweis wurde beanstandet. Bitte reiche die angeforderte Neuaufnahme fristgerecht ein.','/auftrag/'.$ev['order_no'].'/retake/'.$retakeId,null,true);
 }else{
   notify_seller((int)$ev['seller_id'],'evidence.rejected','Nachweis beanstandet','Ein Nachweis wurde beanstandet: '.$reason,'/auftrag/'.$ev['order_no'],null,true);
 }
 flash('success','Beanstandung gespeichert.'.($retakeId?' Neuaufnahme wurde angefordert.':'').($violationId?' Möglicher Verstoß wurde zur Prüfung angelegt.':''));
 redirect('/admin/auftrag/'.$ev['order_no']);
}
require __DIR__.'/app/FeatureRoutes.php';
require __DIR__.'/app/OperationsRoutes.php';
require __DIR__.'/app/OrderChangeRoutes.php';

if($path==='/so-funktioniert-es') legal_page('So funktioniert es','Vom Angebot bis zur Auszahlung – transparent und geführt.',['1. Registrieren'=>'Erstelle dein Verkäuferinnenkonto und bestätige deine E-Mail-Adresse.','2. Angebot auswählen'=>'Prüfe Vergütung, Dauer, Nachweise und Bedingungen vor der Annahme.','3. Vorabkontrolle'=>'Bei physischen Aufträgen wird der konkrete Artikel dokumentiert und vom Betreiber freigegeben.','4. Durchführung'=>'Dein Dashboard zeigt dir fällige Aufgaben und Nachweise.','5. Versand oder digitale Abgabe'=>'Je nach Auftrag folgt ein geführter Versand- oder Abgabeprozess.','6. Prüfung und Wallet'=>'Nach erfolgreichem Abschluss wird die freigegebene Vergütung verfügbar.']);
if($path==='/faq') legal_page('FAQ','Die wichtigsten Antworten zur Plattform.',['Wer kann sich registrieren?'=>'Ausschließlich volljährige Verkäuferinnen ab 18 Jahren.','Ist mein Profil öffentlich?'=>'Nein. Es gibt keine öffentlichen Verkäuferinnenprofile.','Wann startet ein Auftrag?'=>'Bei physischen Aufträgen nach vollständiger Freigabe der Vorabkontrolle.','Wann wird die Vergütung verfügbar?'=>'Nach vollständigem Abschluss und der vorgesehenen Prüfung.','Darf ich Artikel anderer Personen verwenden?'=>'Nein. Aufträge werden ausschließlich persönlich mit eigenen Artikeln bzw. eigenen Inhalten erfüllt.']);
if($path==='/regeln') legal_page('Plattform- und Auftragsregeln','Kurzfassung der zentralen Regeln. Die endgültigen Rechtstexte müssen vor Produktivbetrieb juristisch geprüft werden.',['Volljährigkeit'=>'Die Plattform ist ausschließlich für Personen ab 18 Jahren bestimmt.','Persönliche Erfüllung'=>'Aufträge, Artikel und digitale Inhalte müssen von der registrierten Verkäuferin selbst stammen bzw. persönlich erfüllt werden.','Nachweise'=>'Pflichtnachweise müssen fristgerecht und unverfälscht über die vorgesehenen Funktionen eingereicht werden.','Verstöße'=>'Nicht erfüllte Pflichten können geprüft und als Verstoß bestätigt oder verworfen werden.','Versand'=>'Es gelten die jeweiligen auftragsspezifischen Verpackungs- und Versandbedingungen.','Digitale Inhalte'=>'Digitale Inhalte müssen selbst erstellt sein; Rechtebedingungen werden beim konkreten Auftrag transparent vereinbart.']);
if($path==='/datenschutz') legal_page('Datenschutz','Entwurf – vor Produktivbetrieb mit den tatsächlich eingesetzten Diensten und Speicherfristen finalisieren.',['Verantwortlicher'=>'Die vollständigen Betreiberangaben werden im produktiven Rechtstext eingesetzt.','Verarbeitete Daten'=>'Registrierungs-, Vertrags-, Auftrags-, Nachweis-, Kommunikations- und Zahlungsdaten werden nur für definierte Plattformzwecke verarbeitet.','Private Dateien'=>'Nachweise werden außerhalb öffentlich erreichbarer Verzeichnisse gespeichert.','Betroffenenrechte'=>'Gesetzliche Auskunfts-, Berichtigungs-, Löschungs- und sonstige Betroffenenrechte bleiben unberührt.']);
if($path==='/impressum') legal_page('Impressum','Vor Produktivbetrieb mit den tatsächlichen Pflichtangaben des Betreibers vervollständigen.',['Anbieter'=>'Betreiberangaben noch einzusetzen.','Kontakt'=>'E-Mail-Adresse und weitere gesetzlich erforderliche Kontaktangaben noch einzusetzen.']);
if($path==='/kontakt') legal_page('Kontakt','Allgemeine Fragen können über die im Impressum angegebene Kontaktadresse gestellt werden.',['Auftragsfragen'=>'Für bestehende Aufträge soll der Auftragschat verwendet werden.','Sensible Dateien'=>'Bitte keine Nachweise per normaler E-Mail senden.']);
not_found();

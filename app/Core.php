<?php
declare(strict_types=1);

function app_config(?string $key = null, mixed $default = null): mixed {
    static $cfg;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/app.php';
        if (!is_file($file)) {
            if (str_starts_with($_SERVER['REQUEST_URI'] ?? '/', '/install')) return [];
            http_response_code(503);
            exit('Anwendung noch nicht installiert. Bitte /install/ aufrufen.');
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['app']['timezone'] ?? 'Europe/Berlin');
    }
    if ($key === null) return $cfg;
    $value = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

function db(): PDO {
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $c = app_config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $c['host'],
        $c['port'] ?? 3306,
        $c['name'],
        $c['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string {
    return rtrim((string)app_config('app.url', ''), '/') . '/' . ltrim($path, '/');
}

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('ANKAUFSESSID');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
session_boot();

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="'.e(csrf_token()).'">';
}

function csrf_verify(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('Ungültige oder abgelaufene Anfrage.');
    }
}

function flash(string $type, string $text): void {
    $_SESSION['_flash'][] = [$type, $text];
}

function pull_flashes(): array {
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($flashes) ? $flashes : [];
}

function redirect(string $path): never {
    header('Location: '.url($path));
    exit;
}

function seller(): ?array {
    $id = $_SESSION['seller_id'] ?? null;
    if (!$id) return null;
    $q = db()->prepare('SELECT * FROM sellers WHERE id=? AND deleted_at IS NULL LIMIT 1');
    $q->execute([(int)$id]);
    return $q->fetch() ?: null;
}

function admin(): ?array {
    $id = $_SESSION['admin_id'] ?? null;
    if (!$id) return null;
    $q = db()->prepare('SELECT * FROM admins WHERE id=? LIMIT 1');
    $q->execute([(int)$id]);
    return $q->fetch() ?: null;
}

function require_seller(): array {
    $s = seller();
    if (!$s) redirect('/login');
    return $s;
}

function require_admin(): array {
    $a = admin();
    if (!$a) redirect('/admin/login');
    return $a;
}

function render(string $title, string $content, array $data = []): void {
    $seller = seller();
    $admin = admin();
    $flashes = pull_flashes();
    extract($data, EXTR_SKIP);
    require __DIR__.'/View.php';
}

function post(string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
}

function send_app_mail(string $to, string $subject, string $html): bool {
    $from = (string)app_config('mail.from', 'noreply@localhost');
    $name = (string)app_config('mail.name', app_config('app.name', 'Ankaufsplattform'));
    $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\n";
    $headers .= 'From: '.mb_encode_mimeheader($name).' <'.$from.">\r\n";
    return @mail($to, $subject, $html, $headers);
}

function make_token(): array {
    $raw = bin2hex(random_bytes(32));
    return [$raw, hash('sha256', $raw)];
}

function order_number(): string {
    $year = date('Y');
    $pdo = db();
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT next_value FROM number_sequences WHERE sequence_key=? FOR UPDATE');
        $q->execute(['order_'.$year]);
        $row = $q->fetch();
        if (!$row) {
            $pdo->prepare('INSERT INTO number_sequences(sequence_key,next_value) VALUES(?,2)')
                ->execute(['order_'.$year]);
            $n = 1;
        } else {
            $n = (int)$row['next_value'];
            $pdo->prepare('UPDATE number_sequences SET next_value=? WHERE sequence_key=?')
                ->execute([$n + 1, 'order_'.$year]);
        }
        if ($owns) $pdo->commit();
        return $year.str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function money(float|int|string $value): string {
    return number_format((float)$value, 2, ',', '.').' €';
}

function setting(string $key, mixed $default = null): mixed {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $q = db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $q->execute([$key]);
        $value = $q->fetchColumn();
        return $cache[$key] = ($value === false ? $default : $value);
    } catch (Throwable) {
        return $default;
    }
}

function setting_value(string $key, mixed $default = null): mixed {
    return setting($key, $default);
}

function payout_processing_weekdays(): array {
    $raw=(string)setting_value('payout_processing_weekdays','1,4');
    $days=array_values(array_unique(array_filter(array_map('intval',preg_split('/[^0-9]+/',$raw)?:[]),fn($d)=>$d>=1&&$d<=7)));
    sort($days);
    return $days;
}

function payout_processing_label(): string {
    $labels=[1=>'Montag',2=>'Dienstag',3=>'Mittwoch',4=>'Donnerstag',5=>'Freitag',6=>'Samstag',7=>'Sonntag'];
    $days=payout_processing_weekdays();
    if(!$days) return 'individuell';
    return implode(', ',array_map(fn($d)=>$labels[$d],$days));
}

function next_payout_processing_date(?DateTimeImmutable $from=null): ?DateTimeImmutable {
    $days=payout_processing_weekdays();
    if(!$days) return null;
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    $cursor=($from??new DateTimeImmutable('now',$tz))->setTimezone($tz);
    for($i=0;$i<14;$i++){
        $candidate=$cursor->modify('+'.$i.' day');
        if(in_array((int)$candidate->format('N'),$days,true)) return $candidate->setTime(0,0);
    }
    return null;
}

function analyze_uploaded_image(string $tmpName, string $mime): array {
    $metadata=[];
    $flags=[];

    $info=@getimagesize($tmpName);
    if(!$info) return ['metadata'=>$metadata,'quality_flags'=>['invalid_image'=>true]];

    $width=(int)($info[0]??0);
    $height=(int)($info[1]??0);
    $metadata['width']=$width;
    $metadata['height']=$height;

    $minWidth=max(1,(int)setting('image_min_width',720));
    $minHeight=max(1,(int)setting('image_min_height',720));
    if($width<$minWidth || $height<$minHeight){
        throw new RuntimeException('Bildauflösung zu niedrig. Mindestens '.$minWidth.'×'.$minHeight.' Pixel erforderlich.');
    }

    if($mime==='image/jpeg' && function_exists('exif_read_data')){
        try{
            $exif=@exif_read_data($tmpName,'IFD0,EXIF',true,false);
            if(is_array($exif)){
                $ifd=$exif['IFD0']??[];
                $ex=$exif['EXIF']??[];
                foreach([
                    'make'=>$ifd['Make']??null,
                    'model'=>$ifd['Model']??null,
                    'orientation'=>$ifd['Orientation']??null,
                    'software'=>$ifd['Software']??null,
                    'datetime_original'=>$ex['DateTimeOriginal']??null,
                    'pixel_x_dimension'=>$ex['ExifImageWidth']??null,
                    'pixel_y_dimension'=>$ex['ExifImageLength']??null,
                ] as $key=>$value){
                    if($value!==null && $value!=='') $metadata['exif_'.$key]=is_scalar($value)?(string)$value:null;
                }
            }
        }catch(Throwable){
            // EXIF is optional and must never block an otherwise valid upload.
        }
    }

    $source=null;
    if($mime==='image/jpeg' && function_exists('imagecreatefromjpeg')) $source=@imagecreatefromjpeg($tmpName);
    elseif($mime==='image/png' && function_exists('imagecreatefrompng')) $source=@imagecreatefrompng($tmpName);
    elseif($mime==='image/webp' && function_exists('imagecreatefromwebp')) $source=@imagecreatefromwebp($tmpName);

    if($source){
        $sampleSize=32;
        $sample=imagecreatetruecolor($sampleSize,$sampleSize);
        if($sample && imagecopyresampled($sample,$source,0,0,0,0,$sampleSize,$sampleSize,$width,$height)){
            $luma=[];
            $sum=0.0;
            for($y=0;$y<$sampleSize;$y++){
                $row=[];
                for($x=0;$x<$sampleSize;$x++){
                    $rgb=imagecolorat($sample,$x,$y);
                    $r=($rgb>>16)&255;$g=($rgb>>8)&255;$b=$rgb&255;
                    $v=0.2126*$r+0.7152*$g+0.0722*$b;
                    $row[]=$v;$sum+=$v;
                }
                $luma[]=$row;
            }
            $avg=$sum/($sampleSize*$sampleSize);
            $metadata['average_luminance']=round($avg,2);
            $darkThreshold=(float)setting('image_dark_luminance_threshold',28);
            if($avg<$darkThreshold) $flags['possibly_too_dark']=true;

            $lap=[];
            for($y=1;$y<$sampleSize-1;$y++){
                for($x=1;$x<$sampleSize-1;$x++){
                    $lap[]=
                        4*$luma[$y][$x]
                        -$luma[$y-1][$x]
                        -$luma[$y+1][$x]
                        -$luma[$y][$x-1]
                        -$luma[$y][$x+1];
                }
            }
            if($lap){
                $mean=array_sum($lap)/count($lap);
                $variance=0.0;
                foreach($lap as $v)$variance+=($v-$mean)**2;
                $variance/=count($lap);
                $metadata['blur_variance']=round($variance,2);
                $blurThreshold=(float)setting('image_blur_variance_threshold',45);
                if($variance<$blurThreshold) $flags['possibly_blurry']=true;
            }
        }
        if(is_resource($sample??null) || $sample instanceof GdImage) @imagedestroy($sample);
        if(is_resource($source) || $source instanceof GdImage) @imagedestroy($source);
    }else{
        $metadata['quality_analysis']='gd_unavailable';
    }

    return ['metadata'=>$metadata,'quality_flags'=>$flags];
}

function private_upload(array $file, string $folder): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload fehlgeschlagen.');
    }

    $maxMb = max(1, (int)setting('upload_max_mb', 50));
    $maxBytes = $maxMb * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Datei ist zu groß. Maximal '.$maxMb.' MB erlaubt.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];
    if (!isset($allowed[$mime])) throw new RuntimeException('Dateiformat ist nicht erlaubt.');

    $metadata=[];
    $qualityFlags=[];
    if(str_starts_with($mime,'image/')){
        $analysis=analyze_uploaded_image((string)$file['tmp_name'],$mime);
        $metadata=$analysis['metadata']??[];
        $qualityFlags=$analysis['quality_flags']??[];
    }

    $baseRoot = realpath(__DIR__.'/../storage/private') ?: __DIR__.'/../storage/private';
    $safeFolder = trim(str_replace(['..', '\\'], ['', '/'], $folder), '/');
    $base = $baseRoot.'/'.$safeFolder;
    if (!is_dir($base) && !mkdir($base, 0770, true) && !is_dir($base)) {
        throw new RuntimeException('Speicherordner nicht verfügbar.');
    }

    $name = bin2hex(random_bytes(24)).'.'.$allowed[$mime];
    $target = $base.'/'.$name;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Datei konnte nicht gespeichert werden.');
    }

    return [
        'path' => $safeFolder.'/'.$name,
        'mime' => $mime,
        'size' => (int)$file['size'],
        'sha256' => (string)hash_file('sha256', $target),
        'metadata' => $metadata,
        'quality_flags' => $qualityFlags,
    ];
}

function upload_metadata_json(array $upload): ?string {
    $value=$upload['metadata']??[];
    return $value ? json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
}

function upload_quality_flags_json(array $upload): ?string {
    $value=$upload['quality_flags']??[];
    return $value ? json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
}

function current_run_id(int $orderId): ?int {
    $q = db()->prepare('SELECT id FROM order_runs WHERE order_id=? ORDER BY run_no DESC LIMIT 1');
    $q->execute([$orderId]);
    $id = $q->fetchColumn();
    return $id === false ? null : (int)$id;
}


function order_offer_snapshot(array|int $order): array {
    if(is_int($order)){
        $q=db()->prepare('SELECT offer_id,offer_version FROM orders WHERE id=?');
        $q->execute([$order]);
        $order=$q->fetch() ?: [];
    }

    $offerId=(int)($order['offer_id']??0);
    $version=(int)($order['offer_version']??0);
    if($offerId && $version){
        $q=db()->prepare('SELECT snapshot_json FROM offer_versions WHERE offer_id=? AND version_no=? LIMIT 1');
        $q->execute([$offerId,$version]);
        $raw=$q->fetchColumn();
        if($raw){
            $snapshot=json_decode((string)$raw,true);
            if(is_array($snapshot)) return $snapshot;
        }
    }

    if($offerId){
        $q=db()->prepare('SELECT * FROM offers WHERE id=?');
        $q->execute([$offerId]);
        $offer=$q->fetch();
        if($offer){
            $rules=json_decode((string)($offer['evidence_rules_json']??''),true);
            return [
                'title'=>$offer['title']??null,
                'category_id'=>(int)($offer['category_id']??0),
                'description'=>$offer['description']??null,
                'compensation'=>(float)($offer['compensation']??0),
                'duration_days'=>$offer['duration_days']??null,
                'fulfillment_type'=>$offer['fulfillment_type']??null,
                'evidence_rules'=>is_array($rules)?$rules:[],
                'digital_rules'=>normalize_digital_rules(json_decode((string)($offer['digital_rules_json']??''),true)?:[]),
                'status'=>$offer['status']??null,
            ];
        }
    }

    return [];
}

function offer_evidence_rules(array|int $offerOrOrderId): array {
    $rules = [];
    if (is_int($offerOrOrderId)) {
        $snapshot=order_offer_snapshot($offerOrOrderId);
        if(is_array($snapshot['evidence_rules']??null)) $rules=$snapshot['evidence_rules'];
        elseif(!empty($snapshot['evidence_rules_json'])){
            $decoded=json_decode((string)$snapshot['evidence_rules_json'],true);
            if(is_array($decoded)) $rules=$decoded;
        }
    } else {
        if(is_array($offerOrOrderId['evidence_rules']??null)) $rules=$offerOrOrderId['evidence_rules'];
        elseif(!empty($offerOrOrderId['evidence_rules_json'])){
            $decoded=json_decode((string)$offerOrOrderId['evidence_rules_json'],true);
            if(is_array($decoded)) $rules=$decoded;
        }
    }

    $precheck = (int)($rules['precheck_required_count'] ?? $rules['precheck_required'] ?? 1);
    $daily = is_array($rules['daily'] ?? null) ? $rules['daily'] : [];

    return [
        'precheck_required_count' => max(1, $precheck),
        'daily' => [
            'morning' => max(0, (int)($daily['morning'] ?? 1)),
            'midday' => max(0, (int)($daily['midday'] ?? 1)),
            'evening' => max(0, (int)($daily['evening'] ?? 1)),
        ],
    ];
}


function normalize_digital_rules(?array $rules): array {
    $rules=is_array($rules)?$rules:[];
    $allowed=is_array($rules['allowed']??null)?$rules['allowed']:[];
    $required=is_array($rules['required']??null)?$rules['required']:[];
    $text=is_array($rules['text']??null)?$rules['text']:[];
    $media=is_array($rules['media']??null)?$rules['media']:[];
    $deadline=is_array($rules['deadline']??null)?$rules['deadline']:[];
    $revision=is_array($rules['revision']??null)?$rules['revision']:[];

    $allowText=(bool)($allowed['text']??true);
    $allowAudio=(bool)($allowed['audio']??true);
    $allowVideo=(bool)($allowed['video']??true);
    if(!$allowText && !$allowAudio && !$allowVideo) $allowText=true;

    $requireText=$allowText && (bool)($required['text']??false);
    $requireAudio=$allowAudio && (bool)($required['audio']??false);
    $requireVideo=$allowVideo && (bool)($required['video']??false);

    return [
        'allowed'=>['text'=>$allowText,'audio'=>$allowAudio,'video'=>$allowVideo],
        'required'=>['text'=>$requireText,'audio'=>$requireAudio,'video'=>$requireVideo],
        'text'=>[
            'min_chars'=>max(0,(int)($text['min_chars']??0)),
            'max_chars'=>max(0,(int)($text['max_chars']??0)),
        ],
        'media'=>[
            'max_file_mb'=>max(1,(int)($media['max_file_mb']??50)),
        ],
        'deadline'=>[
            'hours_after_acceptance'=>max(1,(int)($deadline['hours_after_acceptance']??72)),
            'grace_minutes'=>max(0,(int)($deadline['grace_minutes']??60)),
            'violation_effect'=>in_array(($deadline['violation_effect']??'log_only'),['log_only','extension_day'],true)?$deadline['violation_effect']:'log_only',
        ],
        'revision'=>[
            'deadline_hours'=>max(1,(int)($revision['deadline_hours']??48)),
            'grace_minutes'=>max(0,(int)($revision['grace_minutes']??60)),
            'violation_effect'=>in_array(($revision['violation_effect']??'log_only'),['log_only','extension_day'],true)?$revision['violation_effect']:'log_only',
        ],
    ];
}

function offer_digital_rules(array|int $offerOrOrder): array {
    if(is_int($offerOrOrder)){
        $q=db()->prepare('SELECT digital_rules_snapshot_json,offer_id,offer_version FROM orders WHERE id=?');
        $q->execute([$offerOrOrder]);$order=$q->fetch();
        if($order){
            if(!empty($order['digital_rules_snapshot_json'])){
                $decoded=json_decode((string)$order['digital_rules_snapshot_json'],true);
                if(is_array($decoded)) return normalize_digital_rules($decoded);
            }
            $snapshot=order_offer_snapshot($order);
            if(is_array($snapshot['digital_rules']??null)) return normalize_digital_rules($snapshot['digital_rules']);
            $offerOrOrder=(int)$order['offer_id'];
        }
    }

    if(is_array($offerOrOrder)){
        if(is_array($offerOrOrder['digital_rules']??null)) return normalize_digital_rules($offerOrOrder['digital_rules']);
        $raw=$offerOrOrder['digital_rules_json']??null;
    }else{
        $q=db()->prepare('SELECT digital_rules_json FROM offers WHERE id=?');
        $q->execute([(int)$offerOrOrder]);$raw=$q->fetchColumn();
    }

    $decoded=$raw?json_decode((string)$raw,true):[];
    return normalize_digital_rules(is_array($decoded)?$decoded:[]);
}

function digital_rules_summary(array $rules): string {
    $rules=normalize_digital_rules($rules);
    $formats=[];
    foreach(['text'=>'Text','audio'=>'Audio','video'=>'Video'] as $key=>$label){
        if($rules['allowed'][$key]) $formats[]=$label.($rules['required'][$key]?' (Pflicht)':'');
    }
    return implode(', ',$formats);
}

function parse_window_setting(string $key, string $fallback): array {
    $value = (string)setting($key, $fallback);
    $parts = array_map('trim', explode('-', $value, 2));
    if (
        count($parts) !== 2 ||
        !preg_match('/^\d{2}:\d{2}$/', $parts[0]) ||
        !preg_match('/^\d{2}:\d{2}$/', $parts[1])
    ) {
        $parts = explode('-', $fallback, 2);
    }
    return $parts;
}

function bump_offer_version(int $offerId, string $reason = 'configuration_changed'): int {
    $pdo = db();
    $q = $pdo->prepare("SELECT * FROM offers WHERE id=?");
    $q->execute([$offerId]);
    $offer = $q->fetch();
    if (!$offer) throw new RuntimeException('Angebot nicht gefunden.');

    $next = (int)$offer['current_version'] + 1;

    $q = $pdo->prepare("SELECT id,label,price,requirements_json,active FROM offer_options WHERE offer_id=? ORDER BY id");
    $q->execute([$offerId]);$options=$q->fetchAll();

    $q = $pdo->prepare("SELECT id,sort_order,title,instructions,required_photos,requires_text,requires_checkbox,is_dispatch_step,deadline_hours,active FROM offer_shipping_steps WHERE offer_id=? ORDER BY sort_order,id");
    $q->execute([$offerId]);$shippingSteps=$q->fetchAll();

    $q = $pdo->prepare("SELECT id,sort_order,title,description,fields_json,required_photos,compensation,violation_enabled,schedule_type,day_no,start_day,interval_days,due_time,active FROM offer_task_plans WHERE offer_id=? ORDER BY sort_order,id");
    $q->execute([$offerId]);$taskPlans=$q->fetchAll();

    $snapshot=[
        'reason'=>$reason,
        'title'=>$offer['title'],
        'category_id'=>(int)$offer['category_id'],
        'description'=>$offer['description'],
        'compensation'=>(float)$offer['compensation'],
        'duration_days'=>$offer['duration_days']!==null?(int)$offer['duration_days']:null,
        'fulfillment_type'=>$offer['fulfillment_type'],
        'status'=>$offer['status'],
        'visibility'=>$offer['visibility'],
        'evidence_rules'=>json_decode($offer['evidence_rules_json']?:'{}',true)?:[],
        'digital_rules'=>normalize_digital_rules(json_decode($offer['digital_rules_json']?:'{}',true)?:[]),
        'shipping'=>[
            'address_id'=>$offer['shipping_address_id']!==null?(int)$offer['shipping_address_id']:null,
            'cost_mode'=>$offer['shipping_cost_mode'],
            'allowance'=>(float)$offer['shipping_allowance'],
            'preferred_carrier'=>$offer['preferred_carrier'],
            'rules'=>json_decode($offer['shipping_rules_json']?:'{}',true)?:[],
            'steps'=>$shippingSteps,
        ],
        'options'=>$options,
        'task_plans'=>$taskPlans,
    ];

    $pdo->prepare("UPDATE offers SET current_version=?,updated_at=NOW() WHERE id=?")->execute([$next,$offerId]);
    $pdo->prepare("INSERT INTO offer_versions(offer_id,version_no,snapshot_json) VALUES(?,?,?)")
        ->execute([$offerId,$next,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    return $next;
}

function task_plan_occurrence_days(array $plan, int $duration): array {
    $duration = max(1, $duration);
    if (($plan['schedule_type'] ?? 'day') === 'interval') {
        $start = max(1, (int)($plan['start_day'] ?? 1));
        $interval = max(1, (int)($plan['interval_days'] ?? 1));
        $days = [];
        for ($day = $start; $day <= $duration; $day += $interval) $days[] = $day;
        return $days;
    }
    $day = max(1, (int)($plan['day_no'] ?? 1));
    return $day <= $duration ? [$day] : [];
}

function offer_task_plan_summary(int $offerId, ?int $durationDays = null): array {
    $q = db()->prepare("SELECT * FROM offer_task_plans WHERE offer_id=? AND active=1 ORDER BY sort_order,id");
    $q->execute([$offerId]);
    $plans = $q->fetchAll();

    if ($durationDays === null) {
        $d = db()->prepare("SELECT duration_days FROM offers WHERE id=?");
        $d->execute([$offerId]);
        $durationDays = (int)($d->fetchColumn() ?: 1);
    }
    $duration = max(1, (int)$durationDays);
    $executions = 0;$photos = 0;$compensation = 0.0;
    foreach ($plans as &$plan) {
        $days = task_plan_occurrence_days($plan, $duration);
        $plan['_occurrence_days'] = $days;
        $count = count($days);
        $executions += $count;
        $photos += $count * max(0, (int)$plan['required_photos']);
        $compensation += $count * max(0, (float)$plan['compensation']);
    }
    unset($plan);

    return [
        'plans' => $plans,
        'executions' => $executions,
        'required_photos' => $photos,
        'compensation' => round($compensation, 2),
    ];
}

function snapshot_offer_task_plans(int $offerId, int $orderId): array {
    $summary = offer_task_plan_summary($offerId);
    $insert = db()->prepare("INSERT INTO order_task_specs(order_id,source_plan_id,sort_order,title,description,fields_json,required_photos,compensation,violation_enabled,schedule_type,day_no,start_day,interval_days,due_time) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    foreach ($summary['plans'] as $plan) {
        $insert->execute([
            $orderId,$plan['id'],$plan['sort_order'],$plan['title'],$plan['description'],$plan['fields_json'],
            $plan['required_photos'],$plan['compensation'],$plan['violation_enabled'],$plan['schedule_type'],
            $plan['day_no'],$plan['start_day'],$plan['interval_days'],$plan['due_time'],
        ]);
    }
    return $summary;
}

function instantiate_planned_order_tasks(int $orderId): void {
    $q = db()->prepare("SELECT o.duration_days,o.started_at,o.planned_start_date FROM orders o WHERE o.id=?");
    $q->execute([$orderId]);$order=$q->fetch();if(!$order)return;

    $duration=max(1,(int)($order['duration_days']?:1));
    $dayRows=db()->prepare("SELECT day_no,calendar_date FROM order_days WHERE order_id=? ORDER BY day_no");
    $dayRows->execute([$orderId]);$calendar=[];
    foreach($dayRows->fetchAll() as $row)$calendar[(int)$row['day_no']]=$row['calendar_date'];

    $fallbackDate=$order['started_at']?date('Y-m-d',strtotime($order['started_at'])):($order['planned_start_date']?:date('Y-m-d'));
    $specs=db()->prepare("SELECT * FROM order_task_specs WHERE order_id=? ORDER BY sort_order,id");
    $specs->execute([$orderId]);
    $insert=db()->prepare("INSERT IGNORE INTO order_tasks(order_id,source_spec_id,title,description,due_at,planned_day_no,fields_json,required_photos,compensation,violation_enabled,status) VALUES(?,?,?,?,?,?,?,?,?,?,'open')");

    foreach($specs->fetchAll() as $spec){
        foreach(task_plan_occurrence_days($spec,$duration) as $dayNo){
            $date=$calendar[$dayNo]??date('Y-m-d',strtotime($fallbackDate.' +'.($dayNo-1).' day'));
            $time=$spec['due_time']?:'23:59:00';
            $insert->execute([
                $orderId,$spec['id'],$spec['title'],$spec['description'],$date.' '.$time,$dayNo,
                $spec['fields_json'],$spec['required_photos'],$spec['compensation'],$spec['violation_enabled']
            ]);
        }
    }
}

function sync_option_requirement_tasks(int $orderId, ?int $effectiveDayOverride = null): void {
    $q=db()->prepare("SELECT o.duration_days,o.started_at,o.planned_start_date FROM orders o WHERE o.id=?");
    $q->execute([$orderId]);$order=$q->fetch();if(!$order)return;

    $dayRows=db()->prepare("SELECT day_no,calendar_date FROM order_days WHERE order_id=? ORDER BY day_no");
    $dayRows->execute([$orderId]);$calendar=[];
    foreach($dayRows->fetchAll() as $row)$calendar[(int)$row['day_no']]=$row['calendar_date'];

    $fallback=$order['started_at']?date('Y-m-d',strtotime($order['started_at'])):($order['planned_start_date']?:date('Y-m-d'));
    $q=db()->prepare("SELECT * FROM order_options WHERE order_id=? ORDER BY id");
    $q->execute([$orderId]);$options=$q->fetchAll();
    $selectedIds=array_map(fn($x)=>(int)$x['offer_option_id'],$options);

    if($selectedIds){
        $ph=implode(',',array_fill(0,count($selectedIds),'?'));
        $args=array_merge([$orderId],$selectedIds);
        db()->prepare("DELETE FROM order_tasks WHERE order_id=? AND source_offer_option_id IS NOT NULL AND status IN('open','rejected') AND source_offer_option_id NOT IN ($ph)")
            ->execute($args);
    }else{
        db()->prepare("DELETE FROM order_tasks WHERE order_id=? AND source_offer_option_id IS NOT NULL AND status IN('open','rejected')")
            ->execute([$orderId]);
    }

    foreach($options as $opt){
        $req=json_decode((string)($opt['requirements_snapshot_json']??''),true);
        if(!is_array($req))$req=[];
        $instructions=trim((string)($req['instructions']??''));
        $requiredPhotos=max(0,(int)($req['required_photos']??0));
        if($instructions==='' && $requiredPhotos<1) continue;

        $dayNo=$effectiveDayOverride!==null?max(1,$effectiveDayOverride):max(1,(int)($req['day_no']??1));
        $date=$calendar[$dayNo]??date('Y-m-d',strtotime($fallback.' +'.($dayNo-1).' day'));
        $time=(string)($req['due_time']??'23:59:00');
        if(preg_match('/^\d{2}:\d{2}$/',$time))$time.=':00';
        if(!preg_match('/^\d{2}:\d{2}:\d{2}$/',$time))$time='23:59:00';
        $violation=array_key_exists('violation_enabled',$req)?((bool)$req['violation_enabled']?1:0):1;
        $fields=json_encode(['response_type'=>'none'],JSON_UNESCAPED_UNICODE);

        $existing=db()->prepare("SELECT id,status FROM order_tasks WHERE order_id=? AND source_offer_option_id=? ORDER BY id DESC LIMIT 1");
        $existing->execute([$orderId,$opt['offer_option_id']]);$task=$existing->fetch();
        if($task && in_array($task['status'],['submitted','accepted'],true)) continue;

        if($task){
            db()->prepare("UPDATE order_tasks SET title=?,description=?,due_at=?,planned_day_no=?,fields_json=?,required_photos=?,violation_enabled=?,status='open' WHERE id=?")
              ->execute(['Zusatzoption: '.$opt['label_snapshot'],$instructions,$date.' '.$time,$dayNo,$fields,$requiredPhotos,$violation,$task['id']]);
        }else{
            db()->prepare("INSERT INTO order_tasks(order_id,source_offer_option_id,title,description,due_at,planned_day_no,fields_json,required_photos,compensation,violation_enabled,status) VALUES(?,?,?,?,?,?,?,?,0,?,'open')")
              ->execute([$orderId,$opt['offer_option_id'],'Zusatzoption: '.$opt['label_snapshot'],$instructions,$date.' '.$time,$dayNo,$fields,$requiredPhotos,$violation]);
        }
    }
}

function schedule_order_days(int $orderId, ?DateTimeImmutable $startedAt = null): void {
    $q = db()->prepare('SELECT o.duration_days FROM orders o WHERE o.id=?');
    $q->execute([$orderId]);
    $duration = (int)$q->fetchColumn();
    if ($duration < 1) return;

    $runId = current_run_id($orderId);
    if (!$runId) return;

    $exists = db()->prepare('SELECT COUNT(*) FROM order_days WHERE order_id=? AND order_run_id=?');
    $exists->execute([$orderId, $runId]);
    if ((int)$exists->fetchColumn() > 0) return;

    $rules = offer_evidence_rules($orderId);
    $defs = [
        'morning' => parse_window_setting('window_morning', '06:00-10:00'),
        'midday' => parse_window_setting('window_midday', '12:00-16:00'),
        'evening' => parse_window_setting('window_evening', '18:00-23:59'),
    ];

    $active = [];
    foreach ($defs as $key => $range) {
        $count = (int)$rules['daily'][$key];
        if ($count > 0) $active[$key] = ['range' => $range, 'count' => $count];
    }

    $tz = new DateTimeZone((string)app_config('app.timezone', 'Europe/Berlin'));
    $startedAt = ($startedAt ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
    $sameDate = $startedAt->format('Y-m-d');
    $futureSameDay = [];

    foreach ($active as $key => $def) {
        $windowStart = new DateTimeImmutable($sameDate.' '.$def['range'][0].':00', $tz);
        if ($windowStart > $startedAt) $futureSameDay[$key] = $def;
    }

    if (!$active) {
        $firstDate = new DateTimeImmutable($sameDate.' 00:00:00', $tz);
    } else {
        $firstDate = $futureSameDay
            ? new DateTimeImmutable($sameDate.' 00:00:00', $tz)
            : (new DateTimeImmutable($sameDate.' 00:00:00', $tz))->modify('+1 day');
    }

    $grace = max(0, (int)setting('grace_minutes', 60));
    $dayInsert = db()->prepare(
        "INSERT INTO order_days(order_id,order_run_id,day_no,day_type,calendar_date,status)
         VALUES(?,?,?,'regular',?,'planned')"
    );
    $windowInsert = db()->prepare(
        "INSERT INTO evidence_windows(order_id,order_run_id,day_no,window_key,starts_at,ends_at,grace_ends_at,required_count,status)
         VALUES(?,?,?,?,?,?,?,?,?)"
    );

    for ($day = 1; $day <= $duration; $day++) {
        $date = $firstDate->modify('+'.($day - 1).' day');
        $dayInsert->execute([$orderId, $runId, $day, $date->format('Y-m-d')]);

        $use = ($day === 1 && $firstDate->format('Y-m-d') === $sameDate)
            ? $futureSameDay
            : $active;

        foreach ($use as $key => $def) {
            $range = $def['range'];
            $start = new DateTimeImmutable($date->format('Y-m-d').' '.$range[0].':00', $tz);
            $end = new DateTimeImmutable($date->format('Y-m-d').' '.$range[1].':00', $tz);
            $graceEnd = $end->modify('+'.$grace.' minutes');
            $status = $start <= new DateTimeImmutable('now', $tz) ? 'open' : 'planned';
            $windowInsert->execute([
                $orderId,
                $runId,
                $day,
                $key,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $graceEnd->format('Y-m-d H:i:s'),
                $def['count'],
                $status,
            ]);
        }
    }
}

function create_order_schedule(int $orderId): void {
    schedule_order_days($orderId);
}

function start_order_on_planned_date(int $orderId, ?DateTimeImmutable $now = null): bool {
    $tz = new DateTimeZone((string)app_config('app.timezone', 'Europe/Berlin'));
    $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);

    $q = db()->prepare("SELECT * FROM orders WHERE id=?");
    $q->execute([$orderId]);
    $order = $q->fetch();
    if (
        !$order ||
        $order['status'] !== 'precheck' ||
        empty($order['precheck_approved_at']) ||
        empty($order['planned_start_date'])
    ) return false;

    $planned = new DateTimeImmutable($order['planned_start_date'].' 00:00:00', $tz);
    if ($planned > $now) return false;

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare("UPDATE orders SET status='running',started_at=?,updated_at=NOW() WHERE id=? AND status='precheck' AND precheck_approved_at IS NOT NULL");
        $u->execute([$planned->format('Y-m-d H:i:s'), $orderId]);
        if ($u->rowCount() < 1) {
            $pdo->rollBack();
            return false;
        }

        $runId = current_run_id($orderId);
        if ($runId) {
            $pdo->prepare("UPDATE order_runs SET status='running',started_at=COALESCE(started_at,?) WHERE id=?")
                ->execute([$planned->format('Y-m-d H:i:s'), $runId]);
            $pdo->prepare("UPDATE order_items SET locked_at=COALESCE(locked_at,?) WHERE order_id=? AND order_run_id<=>?")
                ->execute([$planned->format('Y-m-d H:i:s'), $orderId, $runId]);
        }

        schedule_order_days($orderId, $planned);
        instantiate_planned_order_tasks($orderId);
        sync_option_requirement_tasks($orderId);
        schedule_existing_extra_days($orderId);
        $pdo->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system',?)")
            ->execute([$orderId,'Der vereinbarte Starttag ist erreicht. Der Auftrag wurde automatisch gestartet.']);
        log_event('order.started',(int)$order['seller_id'],$orderId,['planned_start_date'=>$order['planned_start_date'],'started_at'=>$planned->format(DATE_ATOM)]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    notify_seller(
        (int)$order['seller_id'],
        'order.started',
        'Auftrag gestartet',
        'Auftrag '.$order['order_no'].' ist am vereinbarten Startdatum gestartet.',
        '/auftrag/'.$order['order_no'],
        'order-started-'.$orderId,
        true
    );
    return true;
}

function try_start_order_after_precheck(int $orderId): bool {
    return start_order_on_planned_date($orderId);
}

function append_order_day(int $orderId, string $dayType, ?string $sourceRef = null): ?int {
    $runId = current_run_id($orderId);
    if (!$runId) return null;

    $q = db()->prepare(
        'SELECT MAX(day_no) max_day,MAX(calendar_date) max_date
         FROM order_days WHERE order_id=? AND order_run_id=?'
    );
    $q->execute([$orderId, $runId]);
    $last = $q->fetch() ?: [];

    $dayNo = max(1, (int)($last['max_day'] ?? 0) + 1);
    $tz = new DateTimeZone((string)app_config('app.timezone', 'Europe/Berlin'));
    $date = !empty($last['max_date'])
        ? (new DateTimeImmutable($last['max_date'].' 00:00:00', $tz))->modify('+1 day')
        : new DateTimeImmutable('tomorrow 00:00:00', $tz);

    db()->prepare(
        "INSERT INTO order_days(order_id,order_run_id,day_no,day_type,calendar_date,status,source_ref)
         VALUES(?,?,?,?,?,'planned',?)"
    )->execute([$orderId, $runId, $dayNo, $dayType, $date->format('Y-m-d'), $sourceRef]);

    $rules = offer_evidence_rules($orderId);
    $defs = [
        'morning' => parse_window_setting('window_morning', '06:00-10:00'),
        'midday' => parse_window_setting('window_midday', '12:00-16:00'),
        'evening' => parse_window_setting('window_evening', '18:00-23:59'),
    ];
    $grace = max(0, (int)setting('grace_minutes', 60));
    $insert = db()->prepare(
        "INSERT INTO evidence_windows(order_id,order_run_id,day_no,window_key,starts_at,ends_at,grace_ends_at,required_count,status)
         VALUES(?,?,?,?,?,?,?,?, 'planned')"
    );

    foreach ($defs as $key => $range) {
        $count = (int)$rules['daily'][$key];
        if ($count < 1) continue;
        $start = new DateTimeImmutable($date->format('Y-m-d').' '.$range[0].':00', $tz);
        $end = new DateTimeImmutable($date->format('Y-m-d').' '.$range[1].':00', $tz);
        $insert->execute([
            $orderId,
            $runId,
            $dayNo,
            $key,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            $end->modify('+'.$grace.' minutes')->format('Y-m-d H:i:s'),
            $count,
        ]);
    }

    return $dayNo;
}

function schedule_extra_day(int $extraDayId, ?int $runId = null): void {
    $q = db()->prepare('SELECT * FROM extra_days WHERE id=?');
    $q->execute([$extraDayId]);
    $extra = $q->fetch();
    if (!$extra || ($extra['status'] ?? 'confirmed') !== 'confirmed') return;

    $orderId = (int)$extra['order_id'];
    $runId = $runId ?: current_run_id($orderId);
    if (!$runId) return;

    $sourceRef = 'extra:'.$extraDayId;
    $exists = db()->prepare(
        'SELECT COUNT(*) FROM order_days WHERE order_id=? AND order_run_id=? AND source_ref=?'
    );
    $exists->execute([$orderId, $runId, $sourceRef]);
    if ((int)$exists->fetchColumn() > 0) return;

    append_order_day($orderId, (string)$extra['source_type'], $sourceRef);
}

function schedule_existing_extra_days(int $orderId): void {
    $runId = current_run_id($orderId);
    if (!$runId) return;

    try {
        $q = db()->prepare("SELECT id FROM extra_days WHERE order_id=? AND status='confirmed' ORDER BY created_at,id");
        $q->execute([$orderId]);
    } catch (PDOException) {
        $q = db()->prepare('SELECT id FROM extra_days WHERE order_id=? ORDER BY created_at,id');
        $q->execute([$orderId]);
    }

    foreach ($q->fetchAll() as $row) {
        schedule_extra_day((int)$row['id'], $runId);
    }
}

function notify_seller(
    int $sellerId,
    string $type,
    string $title,
    string $body,
    ?string $link = null,
    ?string $dedupeKey = null,
    bool $email = false
): void {
    try {
        $q = db()->prepare(
            'INSERT INTO notifications(seller_id,notification_type,title,body,link,dedupe_key)
             VALUES(?,?,?,?,?,?)'
        );
        $q->execute([$sellerId, $type, $title, $body, $link, $dedupeKey]);
    } catch (PDOException $e) {
        if ($dedupeKey !== null && (int)($e->errorInfo[1] ?? 0) === 1062) return;
        try {
            $q = db()->prepare(
                'INSERT INTO notifications(seller_id,notification_type,title,body,link)
                 VALUES(?,?,?,?,?)'
            );
            $q->execute([$sellerId, $type, $title, $body, $link]);
        } catch (Throwable) {
            // Notifications must not break the business transaction.
        }
    }

    if ($email) {
        $q = db()->prepare('SELECT email FROM sellers WHERE id=? AND deleted_at IS NULL');
        $q->execute([$sellerId]);
        $to = $q->fetchColumn();
        if ($to) {
            $linkHtml = $link ? '<p><a href="'.e(url($link)).'">In der Plattform öffnen</a></p>' : '';
            send_app_mail((string)$to, $title, '<p>'.e($body).'</p>'.$linkHtml);
        }
    }
}

function log_event(string $type, ?int $sellerId = null, ?int $orderId = null, array $payload = []): void {
    db()->prepare(
        'INSERT INTO system_events(seller_id,order_id,event_type,payload_json) VALUES(?,?,?,?)'
    )->execute([
        $sellerId,
        $orderId,
        $type,
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
    ]);
}


function ensure_provisional_violation(int $orderId, string $sourceKey, string $type, string $reason): int {
    $q = db()->prepare('SELECT id FROM violations WHERE source_key=? LIMIT 1');
    $q->execute([$sourceKey]);
    $existing = $q->fetchColumn();
    if ($existing !== false) return (int)$existing;

    $pdo = db();
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO violations(order_id,violation_type,source_key,status,reason,extension_days) VALUES(?,?,?,'open',?,1)")
            ->execute([$orderId,$type,$sourceKey,$reason]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO extra_days(order_id,source_type,source_id,status,paid,amount,reason) VALUES(?,'violation',?,'provisional',0,0,?)")
            ->execute([$orderId,$id,$reason]);
        if ($owns) $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function reject_if_archived_route(string $path, string $method): void {
    if($method!=='POST') return;
    $orderId=null;$orderNo=null;

    if(preg_match('#/auftrag/(\d{8})(?:/|$)#',$path,$m)){
        if(str_ends_with($path,'/wiederherstellen')) return;
        $orderNo=$m[1];
    }elseif(preg_match('#^/admin/nachweis/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.id,o.order_no,o.archived_at FROM evidences e JOIN orders o ON o.id=e.order_id WHERE e.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/verstoss/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM violations v JOIN orders o ON o.id=v.order_id WHERE v.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/aufgabe/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM order_tasks t JOIN orders o ON o.id=t.order_id WHERE t.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/spontan/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM spontaneous_requests r JOIN orders o ON o.id=r.order_id WHERE r.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/beschaedigung/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM damage_cases d JOIN orders o ON o.id=d.order_id WHERE d.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }elseif(preg_match('#^/admin/revisionspunkt/(\d+)/#',$path,$m)){
        $q=db()->prepare('SELECT o.order_no,o.archived_at FROM revision_items i JOIN revision_rounds r ON r.id=i.revision_round_id JOIN orders o ON o.id=r.order_id WHERE i.id=?');$q->execute([(int)$m[1]]);$o=$q->fetch();
        if($o && $o['archived_at']){flash('error','Der archivierte Auftrag ist schreibgeschützt.');redirect('/admin/auftrag/'.$o['order_no']);}
        return;
    }

    if($orderNo!==null){
        $q=db()->prepare('SELECT archived_at FROM orders WHERE order_no=?');$q->execute([$orderNo]);$archived=$q->fetchColumn();
        if($archived){flash('error','Der archivierte Auftrag ist schreibgeschützt. Stelle ihn im Adminbereich zuerst wieder her.');redirect(str_starts_with($path,'/admin/')?'/admin/auftrag/'.$orderNo:'/auftrag/'.$orderNo);}
    }
}



function build_shipping_snapshot(array|int $offer): array {
    if(is_int($offer)){
        $q=db()->prepare('SELECT * FROM offers WHERE id=?');
        $q->execute([$offer]);
        $offer=$q->fetch() ?: [];
    }

    $rules=[];
    if(!empty($offer['shipping_rules_json'])){
        $decoded=json_decode((string)$offer['shipping_rules_json'],true);
        if(is_array($decoded)) $rules=$decoded;
    }

    $address=null;
    if(!empty($offer['shipping_address_id'])){
        $q=db()->prepare('SELECT id,label,recipient_name,street,address_extra,postal_code,city,country_code FROM shipping_addresses WHERE id=?');
        $q->execute([(int)$offer['shipping_address_id']]);
        $address=$q->fetch() ?: null;
    }

    $steps=[];
    if(!empty($offer['id'])){
        $q=db()->prepare('SELECT id,sort_order,title,instructions,required_photos,requires_text,requires_checkbox,is_dispatch_step,deadline_hours FROM offer_shipping_steps WHERE offer_id=? AND active=1 ORDER BY sort_order,id');
        $q->execute([(int)$offer['id']]);
        $steps=$q->fetchAll();
    }

    return [
        'address'=>$address,
        'cost_mode'=>$offer['shipping_cost_mode'] ?? 'seller',
        'allowance'=>(float)($offer['shipping_allowance'] ?? 0),
        'preferred_carrier'=>$offer['preferred_carrier'] ?? null,
        'instructions'=>$rules['instructions'] ?? null,
        'steps'=>$steps,
        'snapshotted_at'=>date(DATE_ATOM),
    ];
}

function order_shipping_snapshot(array|int $order): array {
    if(is_int($order)){
        $q=db()->prepare('SELECT shipping_snapshot_json,offer_id FROM orders WHERE id=?');
        $q->execute([$order]);
        $order=$q->fetch() ?: [];
    }

    if(!empty($order['shipping_snapshot_json'])){
        $decoded=json_decode((string)$order['shipping_snapshot_json'],true);
        if(is_array($decoded)) return $decoded;
    }

    if(!empty($order['offer_id'])) return build_shipping_snapshot((int)$order['offer_id']);

    return [
        'address'=>null,
        'cost_mode'=>'seller',
        'allowance'=>0.0,
        'preferred_carrier'=>null,
        'instructions'=>null,
        'steps'=>[],
    ];
}

function ensure_order_shipping_steps(int $orderId): void {
    $q=db()->prepare('SELECT COUNT(*) FROM order_shipping_steps WHERE order_id=?');
    $q->execute([$orderId]);
    if((int)$q->fetchColumn()>0) return;

    $q=db()->prepare('SELECT * FROM orders WHERE id=?');
    $q->execute([$orderId]);
    $order=$q->fetch();
    if(!$order) return;

    $snapshot=order_shipping_snapshot($order);
    $steps=is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [];

    if(!$steps){
        $steps=[
            ['id'=>null,'sort_order'=>10,'title'=>'Nutzung beenden','instructions'=>'Beende die Nutzung des Artikels und bestätige den Schritt.','required_photos'=>0,'requires_text'=>0,'requires_checkbox'=>1,'is_dispatch_step'=>0,'deadline_hours'=>null],
            ['id'=>null,'sort_order'=>20,'title'=>'Abschlusszustand dokumentieren','instructions'=>'Erstelle ein aktuelles Foto des Artikels unmittelbar vor dem Verpacken.','required_photos'=>1,'requires_text'=>0,'requires_checkbox'=>0,'is_dispatch_step'=>0,'deadline_hours'=>null],
            ['id'=>null,'sort_order'=>30,'title'=>'Artikel verpacken','instructions'=>'Verpacke den Artikel entsprechend der Auftragsvorgaben und bestätige den Schritt.','required_photos'=>1,'requires_text'=>0,'requires_checkbox'=>1,'is_dispatch_step'=>0,'deadline_hours'=>null],
            ['id'=>null,'sort_order'=>40,'title'=>'Versand durchführen','instructions'=>'Versende die Sendung und hinterlege Trackingnummer oder Einlieferungsnachweis.','required_photos'=>0,'requires_text'=>0,'requires_checkbox'=>1,'is_dispatch_step'=>1,'deadline_hours'=>24],
        ];
    }

    $ins=db()->prepare("INSERT INTO order_shipping_steps(order_id,source_step_id,sort_order,title,instructions,required_photos,requires_text,requires_checkbox,is_dispatch_step,deadline_hours,due_at,status)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
    $first=true;
    foreach($steps as $step){
        $deadlineHours=!empty($step['deadline_hours']) ? (int)$step['deadline_hours'] : null;
        $due=($first && $deadlineHours)
            ? (new DateTimeImmutable('now',new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'))))->modify('+'.$deadlineHours.' hours')->format('Y-m-d H:i:s')
            : null;
        $ins->execute([
            $orderId,
            $step['id'] ?? null,
            (int)$step['sort_order'],
            (string)$step['title'],
            $step['instructions'] ?? null,
            (int)$step['required_photos'],
            (int)$step['requires_text'],
            (int)$step['requires_checkbox'],
            (int)$step['is_dispatch_step'],
            $deadlineHours,
            $due,
            $first?'open':'locked',
        ]);
        $first=false;
    }
}

function unlock_next_shipping_step(int $orderId, int $completedSortOrder): void {
    $q=db()->prepare("SELECT id,deadline_hours FROM order_shipping_steps WHERE order_id=? AND status='locked' AND sort_order>? ORDER BY sort_order,id LIMIT 1");
    $q->execute([$orderId,$completedSortOrder]);
    $step=$q->fetch();
    if(!$step) return;
    $due=!empty($step['deadline_hours'])
        ? (new DateTimeImmutable('now',new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'))))->modify('+'.(int)$step['deadline_hours'].' hours')->format('Y-m-d H:i:s')
        : null;
    db()->prepare("UPDATE order_shipping_steps SET status='open',due_at=? WHERE id=?")->execute([$due,$step['id']]);
}

function order_ready_for_shipping(int $orderId): bool {
    $runId=current_run_id($orderId);
    if(!$runId) return false;

    $q=db()->prepare("SELECT COUNT(*) FROM order_days WHERE order_id=? AND order_run_id=? AND status IN('planned','active')");
    $q->execute([$orderId,$runId]);
    if((int)$q->fetchColumn()>0) return false;

    $q=db()->prepare("SELECT COUNT(*) FROM evidence_windows WHERE order_id=? AND order_run_id=? AND status IN('planned','open')");
    $q->execute([$orderId,$runId]);
    if((int)$q->fetchColumn()>0) return false;

    // Physische Aufträge wechseln erst am Kalendertag nach dem letzten
    // regulären oder zusätzlichen Durchführungstag in den Versand.
    $q=db()->prepare("SELECT MAX(calendar_date) FROM order_days WHERE order_id=? AND order_run_id=?");
    $q->execute([$orderId,$runId]);
    $lastExecutionDate=$q->fetchColumn();
    if($lastExecutionDate){
        $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
        $shippingRelease=(new DateTimeImmutable((string)$lastExecutionDate.' 00:00:00',$tz))->modify('+1 day');
        if(new DateTimeImmutable('now',$tz)<$shippingRelease) return false;
    }

    $q=db()->prepare("SELECT COUNT(*) FROM violations WHERE order_id=? AND status IN('open','reviewed')");
    $q->execute([$orderId]);
    if((int)$q->fetchColumn()>0) return false;

    $q=db()->prepare("SELECT COUNT(*) FROM spontaneous_requests WHERE order_id=? AND status NOT IN('reviewed','missed')");
    $q->execute([$orderId]);
    if((int)$q->fetchColumn()>0) return false;

    $q=db()->prepare("SELECT COUNT(*) FROM order_tasks WHERE order_id=? AND status<>'accepted'");
    $q->execute([$orderId]);
    if((int)$q->fetchColumn()>0) return false;

    $q=db()->prepare("SELECT COUNT(*) FROM damage_cases WHERE order_id=? AND status IN('reported','evidence_requested','review')");
    $q->execute([$orderId]);
    if((int)$q->fetchColumn()>0) return false;

    return true;
}

function advance_order_to_shipping_if_ready(int $orderId): bool {
    $q=db()->prepare("SELECT o.*,f.fulfillment_type FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.id=?");
    $q->execute([$orderId]);
    $o=$q->fetch();
    if(!$o || $o['status']!=='running') return false;
    if($o['fulfillment_type']==='digital') return false;
    if(!order_ready_for_shipping($orderId)) return false;

    db()->prepare("UPDATE orders SET status='shipping',updated_at=NOW() WHERE id=? AND status='running'")->execute([$orderId]);
    ensure_order_shipping_steps($orderId);
    db()->prepare("INSERT INTO chat_messages(order_id,sender_type,message) VALUES(?,'system','Die Durchführung ist abgeschlossen. Der Versandworkflow wurde freigeschaltet.')")->execute([$orderId]);
    notify_seller((int)$o['seller_id'],'shipping.open','Versand freigeschaltet','Die Durchführung von Auftrag '.$o['order_no'].' ist abgeschlossen. Der Versandworkflow ist jetzt verfügbar.','/auftrag/'.$o['order_no'].'/versand',null,true);
    return true;
}


function outage_shift_datetime(string $value, int $seconds): string {
    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    return (new DateTimeImmutable($value,$tz))->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s');
}

function apply_system_outage(int $outageId): array {
    $pdo=db();
    $q=$pdo->prepare('SELECT * FROM system_outages WHERE id=?');
    $q->execute([$outageId]);
    $outage=$q->fetch();
    if(!$outage) throw new RuntimeException('Systemausfall nicht gefunden.');
    if(!empty($outage['applied_at'])) return ['orders'=>0,'entities'=>0,'seconds'=>0];

    $tz=new DateTimeZone((string)app_config('app.timezone','Europe/Berlin'));
    $start=new DateTimeImmutable($outage['starts_at'],$tz);
    $end=new DateTimeImmutable($outage['ends_at'],$tz);
    $seconds=max(0,$end->getTimestamp()-$start->getTimestamp());
    if($seconds<60) throw new RuntimeException('Der Ausfallzeitraum muss mindestens eine Minute umfassen.');

    $impactedOrders=[];
    $entityCount=0;
    $recordImpact=$pdo->prepare('INSERT IGNORE INTO outage_impacts(outage_id,entity_type,entity_id,seconds_shifted) VALUES(?,?,?,?)');

    $pdo->beginTransaction();
    try{
        $qw=$pdo->prepare("SELECT ew.*,o.seller_id,o.order_no
            FROM evidence_windows ew JOIN orders o ON o.id=ew.order_id
            WHERE ew.status IN('planned','open','missed')
              AND ew.starts_at < ?
              AND COALESCE(ew.grace_ends_at,ew.ends_at) > ?");
        $qw->execute([$end->format('Y-m-d H:i:s'),$start->format('Y-m-d H:i:s')]);
        foreach($qw->fetchAll() as $w){
            $newStart=$w['starts_at'];
            if(strtotime($w['starts_at']) >= $start->getTimestamp()) $newStart=outage_shift_datetime($w['starts_at'],$seconds);
            $newEnd=outage_shift_datetime($w['ends_at'],$seconds);
            $baseGrace=$w['grace_ends_at'] ?: $w['ends_at'];
            $newGrace=outage_shift_datetime($baseGrace,$seconds);
            $newStatus=$w['status'];
            if($newStatus==='missed'){
                $newStatus=strtotime($newStart)<=time()?'open':'planned';
                $vq=$pdo->prepare("SELECT id FROM violations WHERE source_key LIKE ? AND status IN('open','reviewed','confirmed')");
                $vq->execute(['window-'.$w['id'].'-missing-%']);
                foreach($vq->fetchAll() as $vr){
                    $pdo->prepare("UPDATE violations SET status='discarded',reviewed_at=NOW() WHERE id=?")->execute([$vr['id']]);
                    $pdo->prepare("UPDATE extra_days SET status='cancelled' WHERE source_type='violation' AND source_id=? AND status IN('provisional','confirmed')")->execute([$vr['id']]);
                }
            }
            $pdo->prepare("UPDATE evidence_windows SET starts_at=?,ends_at=?,grace_ends_at=?,status=? WHERE id=?")
                ->execute([$newStart,$newEnd,$newGrace,$newStatus,$w['id']]);
            $recordImpact->execute([$outageId,'evidence_window',$w['id'],$seconds]);
            $impactedOrders[(int)$w['order_id']]=[(int)$w['seller_id'],$w['order_no']];
            $entityCount++;
        }

        $simple=[
          ['spontaneous_requests','due_at','grace_ends_at',"status IN('requested','seen','confirmed','uploaded')",'spontaneous_request'],
          ['order_tasks','due_at',null,"status IN('open','rejected')",'order_task'],
          ['revision_rounds','due_at',null,"status IN('open','submitted')",'revision_round'],
          ['order_shipping_steps','due_at',null,"status='open'",'shipping_step'],
          ['evidence_retake_requests','due_at',null,"status IN('requested','submitted')",'retake_request'],
        ];
        foreach($simple as [$table,$dueCol,$graceCol,$statusWhere,$entityType]){
            try{
                $sql="SELECT x.*,o.seller_id,o.order_no FROM {$table} x JOIN orders o ON o.id=x.order_id
                      WHERE x.{$dueCol} IS NOT NULL AND x.{$dueCol}>=? AND x.created_at<=? AND {$statusWhere}";
                $qq=$pdo->prepare($sql);
                $qq->execute([$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
                foreach($qq->fetchAll() as $row){
                    $newDue=outage_shift_datetime($row[$dueCol],$seconds);
                    if($graceCol){
                        $newGrace=!empty($row[$graceCol])?outage_shift_datetime($row[$graceCol],$seconds):null;
                        $pdo->prepare("UPDATE {$table} SET {$dueCol}=?,{$graceCol}=? WHERE id=?")->execute([$newDue,$newGrace,$row['id']]);
                    }else{
                        $pdo->prepare("UPDATE {$table} SET {$dueCol}=? WHERE id=?")->execute([$newDue,$row['id']]);
                    }
                    $recordImpact->execute([$outageId,$entityType,$row['id'],$seconds]);
                    $impactedOrders[(int)$row['order_id']]=[(int)$row['seller_id'],$row['order_no']];
                    $entityCount++;
                }
            }catch(PDOException){
                // A migration may not yet have introduced one optional operational table/column.
            }
        }

        try{
            $qa=$pdo->prepare("SELECT a.*,s.id seller_id FROM offer_assignments a JOIN sellers s ON s.id=a.seller_id
                WHERE a.status='assigned' AND a.acceptance_deadline>=? AND a.created_at<=?");
            $qa->execute([$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s')]);
            foreach($qa->fetchAll() as $a){
                $pdo->prepare("UPDATE offer_assignments SET acceptance_deadline=?,updated_at=NOW() WHERE id=?")
                    ->execute([outage_shift_datetime($a['acceptance_deadline'],$seconds),$a['id']]);
                $recordImpact->execute([$outageId,'offer_assignment',$a['id'],$seconds]);
                $entityCount++;
            }
        }catch(PDOException){}

        $pdo->prepare("UPDATE system_outages SET status='ended',applied_at=NOW() WHERE id=?")->execute([$outageId]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    foreach($impactedOrders as $orderId=>[$sellerId,$orderNo]){
        notify_seller(
            $sellerId,
            'system.outage',
            'Fristen wegen Systemausfall verlängert',
            'Ein bestätigter technischer Plattformausfall wurde berücksichtigt. Betroffene Fristen in Auftrag '.$orderNo.' wurden automatisch um '.round($seconds/60).' Minuten verlängert.',
            '/auftrag/'.$orderNo,
            'outage-'.$outageId.'-order-'.$orderId,
            true
        );
        log_event('system.outage.applied',$sellerId,$orderId,['outage_id'=>$outageId,'seconds'=>$seconds]);
    }

    return ['orders'=>count($impactedOrders),'entities'=>$entityCount,'seconds'=>$seconds];
}

function build_interim_summary(int $orderId, int $completedDays): array {
    $q=db()->prepare("SELECT o.order_no,o.total_compensation,o.released_amount,f.title FROM orders o JOIN offers f ON f.id=o.offer_id WHERE o.id=?");
    $q->execute([$orderId]);$o=$q->fetch() ?: [];

    $q=db()->prepare("SELECT COUNT(*) FROM order_days WHERE order_id=?");
    $q->execute([$orderId]);$totalDays=(int)$q->fetchColumn();

    $q=db()->prepare("SELECT COUNT(*) FROM evidence_windows WHERE order_id=? AND status='missed'");
    $q->execute([$orderId]);$missedWindows=(int)$q->fetchColumn();

    $q=db()->prepare("SELECT COUNT(*) FROM violations WHERE order_id=? AND status='confirmed'");
    $q->execute([$orderId]);$confirmedViolations=(int)$q->fetchColumn();

    $q=db()->prepare("SELECT COUNT(*) FROM extra_days WHERE order_id=? AND status='confirmed'");
    $q->execute([$orderId]);$extraDays=(int)$q->fetchColumn();

    $q=db()->prepare("SELECT COUNT(*) FROM spontaneous_requests WHERE order_id=? AND status NOT IN('reviewed','missed')");
    $q->execute([$orderId]);$openSpontaneous=(int)$q->fetchColumn();

    $q=db()->prepare("SELECT COUNT(*) FROM order_tasks WHERE order_id=? AND status NOT IN('accepted')");
    $q->execute([$orderId]);$openTasks=(int)$q->fetchColumn();

    return [
        'order_no'=>$o['order_no'] ?? '',
        'title'=>$o['title'] ?? '',
        'completed_days'=>$completedDays,
        'total_scheduled_days'=>$totalDays,
        'missed_windows'=>$missedWindows,
        'confirmed_violations'=>$confirmedViolations,
        'extra_days'=>$extraDays,
        'open_spontaneous'=>$openSpontaneous,
        'open_tasks'=>$openTasks,
        'current_order_value'=>(float)($o['total_compensation'] ?? 0),
        'created_at'=>date(DATE_ATOM),
    ];
}


function rate_limit_key(string $scope, string $identifier=''): string {
    $ip=(string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return hash('sha256',$scope.'|'.$ip.'|'.mb_strtolower(trim($identifier)));
}

function rate_limit_consume(string $scope, string $identifier='', int $limit=5, int $windowSeconds=900, int $blockSeconds=900): bool {
    $key=rate_limit_key($scope,$identifier);
    $pdo=db();$owns=!$pdo->inTransaction();
    if($owns)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT * FROM rate_limits WHERE rate_key=? FOR UPDATE');$q->execute([$key]);$row=$q->fetch();
        $now=new DateTimeImmutable('now',new DateTimeZone((string)app_config('app.timezone','Europe/Berlin')));
        if(!$row){
            $pdo->prepare('INSERT INTO rate_limits(rate_key,hits,window_started_at) VALUES(?,1,?)')->execute([$key,$now->format('Y-m-d H:i:s')]);
            if($owns)$pdo->commit();
            return true;
        }

        if(!empty($row['blocked_until']) && new DateTimeImmutable($row['blocked_until'])>$now){
            if($owns)$pdo->commit();
            return false;
        }

        $started=new DateTimeImmutable($row['window_started_at']);
        if(($now->getTimestamp()-$started->getTimestamp()) >= $windowSeconds){
            $pdo->prepare('UPDATE rate_limits SET hits=1,window_started_at=?,blocked_until=NULL WHERE rate_key=?')
                ->execute([$now->format('Y-m-d H:i:s'),$key]);
            if($owns)$pdo->commit();
            return true;
        }

        $hits=(int)$row['hits']+1;$blocked=null;
        if($hits>$limit)$blocked=$now->modify('+'.$blockSeconds.' seconds')->format('Y-m-d H:i:s');
        $pdo->prepare('UPDATE rate_limits SET hits=?,blocked_until=? WHERE rate_key=?')->execute([$hits,$blocked,$key]);
        if($owns)$pdo->commit();
        return $blocked===null;
    }catch(Throwable $e){
        if($owns && $pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function rate_limit_clear(string $scope, string $identifier=''): void {
    try{db()->prepare('DELETE FROM rate_limits WHERE rate_key=?')->execute([rate_limit_key($scope,$identifier)]);}catch(Throwable){}
}

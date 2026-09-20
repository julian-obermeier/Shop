<?php
declare(strict_types=1);
session_start();
$root=dirname(__DIR__);
if (is_file($root.'/config/app.php')) {
    exit('<h1>Bereits installiert</h1><p>Die Konfiguration existiert bereits. Entferne oder sperre nun den Ordner <code>install</code>.</p>');
}
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $csrf=$_SESSION['install_csrf']??'';
    if (!hash_equals($csrf,(string)($_POST['_csrf']??''))) $error='Ungültige Anfrage.';
    else {
        $host=trim((string)$_POST['db_host']); $port=(int)$_POST['db_port']; $name=trim((string)$_POST['db_name']);
        $user=trim((string)$_POST['db_user']); $pass=(string)$_POST['db_pass']; $base=rtrim(trim((string)$_POST['app_url']),'/');
        $adminEmail=trim((string)$_POST['admin_email']); $adminPass=(string)$_POST['admin_password']; $from=trim((string)$_POST['mail_from']);
        try {
            if (!filter_var($adminEmail,FILTER_VALIDATE_EMAIL)||strlen($adminPass)<12) throw new RuntimeException('Admin-E-Mail prüfen; Passwort muss mindestens 12 Zeichen haben.');
            $pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $sql=file_get_contents($root.'/database/schema.sql');
            $pdo->exec($sql);
            foreach(glob($root.'/database/migrations/*.sql') ?: [] as $mf){
                $mn=basename($mf,'.sql');
                $pdo->prepare('INSERT IGNORE INTO migrations(migration) VALUES(?)')->execute([$mn]);
            }
            $cats=['Getragene Socken','Schuhe','Slips','Tops','BHs','Strumpfhosen','Nylons','Feinstrümpfe','Kniestrümpfe','Overknees','Leggings','Shorts','Hotpants','Sportkleidung','T-Shirts','Pullover','Hoodies','Schlafkleidung','Pyjamas','Bodys','Bikinis','Badeanzüge','Handschuhe','Mützen','Caps','Schals','Einlegesohlen','Schnürsenkel','Arbeitskleidung','Berufskleidung','Kostüm-/Cosplay-Kleidung','Dessous','Persönliche Accessoires','Haare/Haarsträhnen','Spucke','Individuelle Sets','Digitale Inhalte','Wichsanleitung','Sonstiges'];
            $ins=$pdo->prepare('INSERT IGNORE INTO categories(name,slug,is_system,is_active,sort_order) VALUES(?,?,?,?,?)');
            foreach($cats as $i=>$c){$slug=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',strtr($c,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue'])),'-'));$ins->execute([$c,$slug,1,1,$i+1]);}
            $pdo->prepare('INSERT INTO admins(email,password_hash) VALUES(?,?)')->execute([$adminEmail,password_hash($adminPass,PASSWORD_DEFAULT)]);
            $cfg="<?php\nreturn ".var_export(['app'=>['name'=>'Private Ankauf','url'=>$base,'timezone'=>'Europe/Berlin','debug'=>false],'db'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'],'mail'=>['from'=>$from,'name'=>'Private Ankauf']],true).";\n";
            if (!is_dir($root.'/config')) mkdir($root.'/config',0770,true);
            if (file_put_contents($root.'/config/app.php',$cfg)===false) throw new RuntimeException('config/app.php konnte nicht geschrieben werden.');
            header('Location: '.$base.'/admin/login?installed=1'); exit;
        } catch(Throwable $e){$error=$e->getMessage();}
    }
}
$_SESSION['install_csrf']=$_SESSION['install_csrf']??bin2hex(random_bytes(24));
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Installation</title><style>body{font-family:system-ui;background:#f7f5f8;margin:0;color:#211d25}.box{max-width:760px;margin:40px auto;background:white;padding:30px;border-radius:18px;box-shadow:0 15px 50px #0001}form{display:grid;gap:12px}.g{display:grid;grid-template-columns:1fr 1fr;gap:12px}input{padding:11px;border:1px solid #ccc;border-radius:8px}button{padding:12px;border:0;border-radius:9px;background:#8d3d6e;color:#fff;font-weight:700}.err{background:#fee;color:#900;padding:10px;border-radius:8px}@media(max-width:650px){.g{grid-template-columns:1fr}}</style></head><body><div class="box"><h1>Private Ankauf – Installation</h1><p>PHP <?=htmlspecialchars(PHP_VERSION)?> · V1 Neuinstallation</p><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($_SESSION['install_csrf'])?>"><div class="g"><label>DB Host<input name="db_host" value="localhost" required></label><label>Port<input name="db_port" value="3306" required></label><label>Datenbank<input name="db_name" required></label><label>DB Benutzer<input name="db_user" required></label></div><label>DB Passwort<input type="password" name="db_pass"></label><label>Basis-URL<input name="app_url" placeholder="https://example.de" required></label><div class="g"><label>Admin-E-Mail<input type="email" name="admin_email" required></label><label>Admin-Passwort (min. 12 Zeichen)<input type="password" name="admin_password" required></label></div><label>Mail-Absender<input type="email" name="mail_from" required></label><button>V1 installieren</button></form></div></body></html>

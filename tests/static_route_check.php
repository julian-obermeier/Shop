<?php
declare(strict_types=1);

$files=[
    'index.php',
    'app/FeatureRoutes.php',
    'app/SellerAdminRoutes.php',
    'app/OperationsRoutes.php',
    'app/OrderChangeRoutes.php',
    'app/PublicPages.php',
];

$routes=[];
foreach($files as $file){
    $content=file_get_contents(__DIR__.'/../'.$file);
    if($content===false) throw new RuntimeException('Cannot read '.$file);

    if(preg_match_all('/if\s*\(\s*\$path===([\'"])([^\'"]+)\1[\s\S]{0,180}?\$method===([\'"])(GET|POST)\3/',$content,$matches,PREG_SET_ORDER)){
        foreach($matches as $m){
            $sig=$m[4].' '.$m[2];
            $routes[$sig][]=$file;
        }
    }

    if(preg_match_all('/if\s*\(\s*preg_match\(\'([^\']+)\'[\s\S]{0,220}?\$method===([\'"])(GET|POST)\2/',$content,$matches,PREG_SET_ORDER)){
        foreach($matches as $m){
            $sig=$m[3].' REGEX '.$m[1];
            $routes[$sig][]=$file;
        }
    }
}

$errors=[];
foreach($routes as $sig=>$owners){
    if(count($owners)>1) $errors[]='Duplicate route '.$sig.' in '.implode(', ',$owners);
}

$required=[
    'GET /angebote',
    'GET /dashboard',
    'GET /wallet',
    'GET /admin/angebote',
    'GET /admin/verkaeuferinnen',
    'GET /admin/auftraege',
    'GET /admin/ausfaelle',
    'GET /admin/versandadressen',
    'GET /admin/kalender',
    'GET /admin/fristen',
    'GET /admin/entscheidungen',
];
foreach($required as $sig){
    if(empty($routes[$sig])) $errors[]='Required route missing: '.$sig;
}

$view=file_get_contents(__DIR__.'/../app/View.php') ?: '';
$forbiddenLinks=[
    '/admin/systemausfaelle'=>'/admin/ausfaelle',
];
foreach($forbiddenLinks as $bad=>$good){
    if(str_contains($view,$bad)) $errors[]='Dead navigation link '.$bad.' found; use '.$good;
}

if($errors){
    fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);
    exit(1);
}

echo 'Route static check OK: '.count($routes).' unique route signatures'.PHP_EOL;

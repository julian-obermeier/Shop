<?php
declare(strict_types=1);

require __DIR__.'/app/Core.php';
require __DIR__.'/app/Migrations.php';

run_migrations();

if(PHP_SAPI!=='cli'){
    $provided=(string)($_GET['token']??($_SERVER['HTTP_X_CRON_TOKEN']??''));
    if($provided===''||!hash_equals(cron_token(),$provided)){
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status'=>'forbidden'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}

header('Content-Type: application/json; charset=UTF-8');

try{
    $result=run_scheduled_notifications();
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode([
        'status'=>'error',
        'message'=>$e->getMessage(),
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit(1);
}

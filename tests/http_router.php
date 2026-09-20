<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/';
$candidate=realpath($root.$path);

if($candidate!==false && str_starts_with($candidate,$root) && is_file($candidate)){
    return false;
}

require $root.'/index.php';

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$lock=fopen($storage.'/illustrator.lock','c');
if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))exit;
try{
    file_put_contents($storage.'/illustrator-heartbeat',(string)time(),LOCK_EX);
    illustration_run_one();
}finally{flock($lock,LOCK_UN);fclose($lock);}

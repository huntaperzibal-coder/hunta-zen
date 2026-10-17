<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Cache-Control: no-store');header('Content-Type: application/json; charset=utf-8');
if(!authenticated()){http_response_code(401);echo '{"error":"Вход истёк. Сохрани текст и войди заново."}';exit;}
try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        verify_csrf();$id=proofread_enqueue((int)($_POST['id']??0),(int)($_POST['revision']??0),(string)($_POST['body']??''));
    }elseif($_SERVER['REQUEST_METHOD']==='GET')$id=(int)($_GET['job']??0);
    else{http_response_code(405);exit;}
    $q=$db->prepare('SELECT * FROM illustration_jobs WHERE id=? AND post_id=?');$q->execute([$id,(int)($_REQUEST['id']??0)]);$job=$q->fetch();
    $snapshot=$job?json_decode($job['snapshot'],true):[];
    if(!$job||($snapshot['mode']??'')!=='proofread')throw new RuntimeException('Проверка не найдена.');
    $plan=$job['state']==='done'?proofread_validate(json_decode($job['plan'],true)?:[],$snapshot['segments']):null;
    echo json_encode(['job'=>$id,'state'=>$job['state'],'error'=>$job['error'],'cost'=>(float)$job['cost'],'plan'=>$plan],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(409);echo json_encode(['error'=>$e instanceof RuntimeException?$e->getMessage():'Не удалось проверить текст. Рассказ не изменён.'],JSON_UNESCAPED_UNICODE);}

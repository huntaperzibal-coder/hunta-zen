<?php
// Offline provider stub for the real Actions worker; never accepts HTTP.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$packet=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
require dirname(__DIR__).'/app/bootstrap.php';
echo json_encode(illustration_actions_execute($packet,static function($path,$payload,$key){
    if($path==='chat/completions')return ['choices'=>[['message'=>['content'=>'{"scene":"Вечер у костра","prompt":"Один холодный уголёк в пламени.","conflict":"Общее тепло исключает одного","composition":"Контраст общего огня и холодного уголька","device":"ирония","anchor":"Холодный уголёк","anchor_reason":"Нарушает обещание общего тепла"}']]],'usage'=>['cost'=>0.001]];
    return ['data'=>[['b64_json'=>'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6ioAAAAASUVORK5CYII=']],'usage'=>['cost'=>0.04]];
}),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

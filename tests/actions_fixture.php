<?php
// Offline provider stub for the real Actions worker; never accepts HTTP.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$packet=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
require dirname(__DIR__).'/app/bootstrap.php';
echo json_encode(illustration_actions_execute($packet,static function($path,$payload,$key)use($packet){
    if($path==='chat/completions' && array_key_exists('cycle_memory',$packet['snapshot'])){
        $input=json_decode($payload['messages'][1]['content'],true);
        if(($input['cycle_memory']??null)!==$packet['snapshot']['cycle_memory'])throw new RuntimeException('Cycle memory lost in Actions worker');
    }
    if($path==='chat/completions')return ['choices'=>[['message'=>['content'=>'{"scene":"Вечер у костра","prompt":"Тихий вечер у огня.","style_id":"impressionism","style_reason":"Свет и мгновение раскрывают настроение рассказа"}']]],'usage'=>['cost'=>0.001]];
    if(($payload['aspect_ratio']??'')!=='16:9')throw new RuntimeException('Landscape format missing from Actions request');
    return ['data'=>[['b64_json'=>'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6ioAAAAASUVORK5CYII=']],'usage'=>['cost'=>0.04]];
}),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

<?php
// Offline provider stub for the real Actions worker; never accepts HTTP.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$packet=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
require dirname(__DIR__).'/app/bootstrap.php';
echo json_encode(illustration_actions_execute($packet,static function($path,$payload,$key){
    if($path==='chat/completions' && str_contains($payload['messages'][0]['content'],'бережный корректор'))return ['choices'=>[['message'=>['content'=>'{"patches":[{"id":0,"text":"Привет, мир!"}]}']]],'usage'=>['cost'=>0.002]];
    if($path==='chat/completions')return ['choices'=>[['message'=>['content'=>'{"scene":"Вечер у костра","prompt":"Тихий вечер у огня."}']]],'usage'=>['cost'=>0.001]];
    if(($payload['aspect_ratio']??'')!=='16:9')throw new RuntimeException('Landscape format missing from Actions request');
    return ['data'=>[['b64_json'=>'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6ioAAAAASUVORK5CYII=']],'usage'=>['cost'=>0.04]];
}),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

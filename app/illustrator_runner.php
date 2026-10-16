<?php
declare(strict_types=1);
function illustration_actions_execute(array $packet,?callable $transport=null): array {
    if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only');
    global $db,$storage;$transport??='illustration_request';
    if(($packet['neutral_test']??false)===true){
        try{
            $result=$transport('images',['model'=>'google/gemini-3.1-flash-image','n'=>1,'prompt'=>'Заснеженная равнина на рассвете. Мягкая акварель. Без людей, животных и надписей.'],$packet['api_key']);
            if(!is_string($result['data'][0]['b64_json']??null))throw new RuntimeException('Ответ не содержит изображения.');
            illustration_store_image($result['data'][0]['b64_json']);
            return ['state'=>'done','cost'=>max(0,(float)($result['usage']['cost']??0)),'error'=>'','image_b64'=>$result['data'][0]['b64_json']];
        }catch(RuntimeException $e){return ['state'=>'failed','cost'=>0,'error'=>$e->getMessage()];}
    }
$db->exec(file_get_contents(ROOT.'/database.sql'));
$db->prepare('INSERT INTO posts(id,slug,created_at,updated_at) VALUES(1,?,?,?)')->execute(['actions-only',now(),now()]);
$db->prepare('INSERT INTO illustration_jobs(id,post_id,revision,snapshot,created_at,updated_at) VALUES(1,1,?,?,?,?)')->execute([$packet['revision'],json_encode($packet['snapshot'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),now(),now()]);
file_put_contents($storage.'/openrouter-key.php','<?php return '.var_export($packet['api_key'],true).';',LOCK_EX);chmod($storage.'/openrouter-key.php',0600);
mkdir($storage.'/photos',0700);
foreach($packet['references'] as $name=>$encoded){
    if(!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$name))throw new RuntimeException('Invalid reference');
    $bytes=base64_decode($encoded,true);if($bytes===false||strlen($bytes)>4*1024*1024)throw new RuntimeException('Invalid reference size');
    file_put_contents($storage.'/photos/'.$name,$bytes,LOCK_EX);chmod($storage.'/photos/'.$name,0600);
}
illustration_run_one(function($path,$payload,$key)use($db,$storage,$transport){
    if($path==='images'){
        $job=$db->query('SELECT plan,cost FROM illustration_jobs WHERE id=1')->fetch();
        file_put_contents($storage.'/progress.json',json_encode(['plan'=>json_decode($job['plan'],true),'cost'=>(float)$job['cost']],JSON_THROW_ON_ERROR),LOCK_EX);
    }
    return $transport($path,$payload,$key);
});
$job=$db->query('SELECT * FROM illustration_jobs WHERE id=1')->fetch();
return ['state'=>$job['state'],'plan'=>json_decode($job['plan'],true)?:[],'cost'=>(float)$job['cost'],'error'=>$job['error'],
    'image_b64'=>$job['image']?base64_encode(file_get_contents($storage.'/photos/'.$job['image'])):''];

}

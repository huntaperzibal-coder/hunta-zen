<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/hunta-illustrator-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test','telegram_token'=>'','telegram_channel'=>''];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';
function verify_illustration(bool $ok,string $message):void{if(!$ok)throw new LogicException($message);echo "OK: $message\n";}
function expect_illustration_error(callable $call):void{try{$call();throw new LogicException('expected rejection');}catch(RuntimeException $e){}}
try{
    verify_illustration(str_contains(illustration_error_detail(403,'{ "success": false, "error": "Access denied by security policy." }','secret'),'Access denied by security policy.'),'edge security error string is shown');
    $detail=illustration_error_detail(403,json_encode(['error'=>['message'=>'Country not supported','metadata'=>['provider_name'=>'Example','raw'=>json_encode(['error'=>['message'=>'Blocked region local-test-secret sk-test-private'],'headers'=>['Authorization'=>'hidden']])]]]),'local-test-secret');
    verify_illustration(str_contains($detail,'Country not supported') && str_contains($detail,'Blocked region') && !str_contains($detail,'local-test-secret') && !str_contains($detail,'sk-test-private'),'provider error explains region denial without leaking key');
    verify_illustration(!str_contains(illustration_error_detail(403,'<html>blocked</html>','secret'),'<html>'),'non-JSON error has safe fallback');
    foreach([false,true] as $encoded){
        $raw=['promptFeedback'=>['blockReason'=>'OTHER'],'candidates'=>[['finishReason'=>'IMAGE_SAFETY','safetyRatings'=>[['category'=>'HARM_CATEGORY_DANGEROUS_CONTENT','blocked'=>true]],'content'=>['parts'=>[['text'=>'PRIVATE_STORY']]]]],'headers'=>['Authorization'=>'PRIVATE_HEADER']];
        $detail=illustration_error_detail(400,json_encode(['error'=>['message'=>'Blocked','metadata'=>['raw'=>$encoded?json_encode($raw):$raw]]]),'secret');
        verify_illustration(str_contains($detail,'OTHER')&&str_contains($detail,'IMAGE_SAFETY')&&str_contains($detail,'HARM_CATEGORY_DANGEROUS_CONTENT')&&!str_contains($detail,'PRIVATE_'),'Google block details shown without response content or headers');
    }
    $probeCalls=[];
    $probe=illustration_actions_execute(['neutral_test'=>true,'api_key'=>'local-test-secret'],function($path,$payload,$key)use(&$probeCalls){
        $probeCalls[]=[$path,$payload];
        throw new RuntimeException('neutral-test-rejection');
    });
    verify_illustration(count($probeCalls)===1&&$probeCalls[0][0]==='images'&&array_keys($probeCalls[0][1])===['model','n','prompt']&&$probeCalls[0][1]['model']==='google/gemini-3.1-flash-image'&&!str_contains($probeCalls[0][1]['prompt'],'Винни')&&$probe['error']==='neutral-test-rejection','neutral Actions probe sends only one fixed image request without story or references');
    $db->exec(file_get_contents(ROOT.'/database.sql'));$db->exec(file_get_contents(ROOT.'/database.sql'));
    $db->prepare('INSERT INTO posts(title,body,slug,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(['У костра',"Гена и Винни сидят у костра.\nПоследняя строка — звёзды.",'fire',now(),now()]);$p=post((int)$db->lastInsertId());
    expect_illustration_error(fn()=>illustration_enqueue($p,''));
    illustration_save_settings([...illustration_defaults(),'api_key'=>'local-test-secret','characters'=>'Гена — человек; Винни — пёс.'],[]);
    verify_illustration(illustration_key()==='local-test-secret' && ((fileperms($dir.'/openrouter-key.php')&0777)===0600),'key private and outside database');
    verify_illustration(!str_contains((string)$db->query("SELECT value FROM settings WHERE key='illustrator'")->fetchColumn(),'local-test-secret'),'no key in settings database');
    $id=illustration_enqueue($p,'Тихий вечер');verify_illustration(illustration_enqueue($p,'Другой запрос')===$id,'double click keeps one active job');
    $p=save_post((int)$p['id'],['title'=>$p['title'],'body'=>'Новая версия текста','revision'=>$p['revision']]);
    $calls=[];
    $png='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6ioAAAAASUVORK5CYII=';
    $mock=function(string $path,array $payload,string $key)use(&$calls,$png):array{
        $calls[]=[$path,$payload];verify_illustration($key==='local-test-secret','transport receives server key');
        if($path==='chat/completions')return ['choices'=>[['message'=>['content'=>json_encode(['scene'=>'Вечер у костра','prompt'=>'Two friends by a campfire under stars.','characters'=>['Гена','Винни']])]]],'usage'=>['cost'=>0.001]];
        return ['data'=>[['b64_json'=>$png]],'usage'=>['cost'=>0.04]];
    };
    illustration_run_one($mock);$job=illustration_result($id,(int)$p['id']);
    verify_illustration(count($calls)===2 && str_contains($calls[0][1]['messages'][1]['content'],'Последняя строка') && !str_contains($calls[0][1]['messages'][1]['content'],'Новая версия'),'reads entire immutable snapshot');
    verify_illustration($calls[1][1]['n']===1 && is_file($dir.'/photos/'.$job['image']) && abs((float)$job['cost']-.041)<.00001,'one image persisted with reported costs');
    verify_illustration(post((int)$p['id'])['body']==='Новая версия текста' && post((int)$p['id'])['cover']==='','generation never changes current text or cover');
    $old=$p;$p=illustration_apply($p,$id,'inline');verify_illustration(str_contains($p['body'],$job['image']) && str_contains($p['body'],'Новая версия текста'),'explicit insert preserves latest text');
    expect_illustration_error(fn()=>illustration_apply($old,$id,'cover'));
    expect_illustration_error(fn()=>illustration_result($id,99999));
    $p=illustration_apply($p,$id,'cover');verify_illustration($p['cover']===$job['image'],'explicit cover selection');
    $failed=illustration_enqueue($p,'Новый вариант');$before=$p;
    illustration_run_one(function(){throw new RuntimeException('Связь прервалась. Автоматического повтора нет.');});
    verify_illustration($db->query('SELECT state FROM illustration_jobs WHERE id='.$failed)->fetchColumn()==='failed','transport failure terminal');
    verify_illustration(!illustration_run_one($mock) && post((int)$p['id'])['revision']===$before['revision'],'failed job not retried and post untouched');
    $stale=illustration_enqueue($p,'');$db->exec("UPDATE illustration_jobs SET state='generating' WHERE id=$stale");illustration_run_one($mock);
    verify_illustration($db->query('SELECT state FROM illustration_jobs WHERE id='.$stale)->fetchColumn()==='unknown','crashed worker not replayed');
    expect_illustration_error(fn()=>illustration_store_image(base64_encode('<svg onload="alert(1)"></svg>')));
    expect_illustration_error(fn()=>illustration_store_image('invalid'));
    illustration_save_settings([...illustration_defaults(),'daily_limit'=>1],[]);
    expect_illustration_error(fn()=>illustration_enqueue($p,''));
    verify_illustration(illustration_key()==='local-test-secret','blank key preserves existing secret');
    // Character references are copied into the request from local files, never fetched from arbitrary URLs.
    illustration_save_settings([...illustration_defaults(),'references'=>[],'daily_limit'=>50],[]);
    $s=illustration_settings();$s['references']=[$job['image']];
    $db->prepare("UPDATE settings SET value=? WHERE key='illustrator'")->execute([json_encode($s)]);
    $refJob=illustration_enqueue($p,'Сохранить внешность');$calls=[];illustration_run_one($mock);
    verify_illustration(str_starts_with($calls[1][1]['input_references'][0]['image_url']['url'],'data:image/png;base64,'),'reference passed as embedded local raster');
    verify_illustration(illustration_result($refJob,(int)$p['id'])['state']==='done','reference generation completed');
}finally{
    $db=null;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);
}

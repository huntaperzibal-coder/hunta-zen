<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/hunta-proof-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/photos',0700);
file_put_contents($dir.'/config.php','<?php return '.var_export(['storage'=>$dir,'site_url'=>'https://example.test','telegram_token'=>'','telegram_channel'=>''],true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));
function proof_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'OK: '.$label."\n";}
function proof_reject(callable $f):void{try{$f();}catch(RuntimeException $e){return;}throw new RuntimeException('Unsafe operation accepted');}
try{
 file_put_contents($storage.'/openrouter-key.php',"<?php return 'offline-test-key';");
 $db->prepare('INSERT INTO posts(title,body,slug,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(['Рассказ',DAO_RICH_PREFIX.'<p>Превет <b>мир</b>!</p>','story',now(),now()]);$p=post((int)$db->lastInsertId());
 $html='<p>Превет <b>мир</b>!<br>Ещё строка.</p><p></p><pre>teh code</pre><p><a href="https://example.test">Сылка</a><img src="/media.php?file='.str_repeat('a',32).'.png"></p>';
 $segments=proofread_segments($html);proof_check($segments===['Превет ','мир','!','Ещё строка.','Сылка'],'segments preserve formatting boundaries and skip code, empty paragraphs and image attributes');
 $id=proofread_enqueue((int)$p['id'],(int)$p['revision'],$html);
 proof_check(proofread_enqueue((int)$p['id'],(int)$p['revision'],$html)===$id,'double click reuses active correction');
 proof_reject(fn()=>proofread_enqueue((int)$p['id'],0,$html));proof_reject(fn()=>illustration_enqueue($p,''));
 $calls=[];$mock=function($path,$payload,$key)use(&$calls){$calls[]=$path;return ['choices'=>[['message'=>['content'=>'{"patches":[{"id":0,"text":"Привет"},{"id":4,"text":"Ссылка"}]}']]],'usage'=>['cost'=>0.0012]];};
 illustration_run_one($mock);$q=$db->query('SELECT * FROM illustration_jobs WHERE id='.$id);$job=$q->fetch();$plan=json_decode($job['plan'],true);
 proof_check($calls===['chat/completions']&&$job['state']==='done'&&$job['image']===''&&abs((float)$job['cost']-.0012)<.0000001,'one text request, no painter, result and cost recorded');
 proof_check($plan['patches'][0]['text']==='Привет '&&post((int)$p['id'])===$p,'node-edge whitespace preserved; proofreading never changes saved story');
 proof_reject(fn()=>illustration_result($id,(int)$p['id']));
 foreach([['patches'=>[['id'=>99,'text'=>'X']]],['patches'=>[['id'=>0,'text'=>'']]],['patches'=>[['id'=>0,'text'=>'Пока'],['id'=>0,'text'=>'Ещё']]]] as $bad)proof_reject(fn()=>proofread_validate($bad,$segments));
 proof_check(proofread_validate(['patches'=>[]],$segments)===['patches'=>[]],'no errors is a valid unchanged result');
 $failed=proofread_enqueue((int)$p['id'],(int)$p['revision'],$html);$count=0;
 illustration_run_one(function()use(&$count){$count++;throw new RuntimeException('Connection interrupted');});illustration_run_one($mock);
 proof_check($count===1&&$db->query('SELECT state FROM illustration_jobs WHERE id='.$failed)->fetchColumn()==='failed','uncertain API call is not retried');
}finally{$db=null;$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);}

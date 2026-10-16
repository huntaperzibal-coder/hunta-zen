<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/hunta-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/photos',0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test','login'=>'author','password_hash'=>password_hash('test-password-only',PASSWORD_DEFAULT),'telegram_token'=>'fake-token','telegram_channel'=>'@fake'];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));
$db->prepare('INSERT INTO orda_users(login,password_hash) VALUES(?,?)')->execute([$config['login'],$config['password_hash']]);
function check(bool $ok,string $name): void { if (!$ok) throw new RuntimeException('FAIL: '.$name); echo 'OK: '.$name."\n"; }
function fails(callable $f,string $name):void{try{$f();}catch(RuntimeException $e){echo 'OK: '.$name."\n";return;}throw new RuntimeException('Expected failure: '.$name);}
try {
    $db->prepare('INSERT INTO posts(slug,created_at,updated_at) VALUES(?,?,?)')->execute(['test',now(),now()]);$id=(int)$db->lastInsertId();
    $input=['revision'=>1,'title'=>'Мысли на перевале','body'=>"Первая строка\n\n<script>alert(1)</script>",'status'=>'draft'];
    $p=save_post($id,$input);check($p['slug']==='mysli-na-perevale','Cyrillic URL');
    fails(fn()=>save_post($id,$input),'stale revision cannot overwrite');
    $input['revision']=$p['revision'];$input['status']='published';$p=save_post($id,$input);check($p['published_at']!==null,'publish');
    $input['revision']=$p['revision'];$input['title']='Новое название';$p=save_post($id,$input);check($p['slug']==='mysli-na-perevale','published URL stable');
    check(!str_contains(render_body($p['body']),'<script>'),'HTML escaped');
    $calls=[];$mock=function($method,$payload)use(&$calls){$calls[]=$method;return ['ok'=>true,'result'=>['message_id'=>42]];};
    telegram_send($id,$mock);telegram_send($id,$mock);check($calls===['sendMessage','editMessageText'],'second send edits original');
    fails(fn()=>telegram_send($id,fn()=>throw new RuntimeException('timeout')),'timeout recorded');
    $count=0;fails(function()use($id,&$count){telegram_send($id,function()use(&$count){$count++;});},'unknown result blocks duplicate');check($count===0,'no automatic retry');
    $db->exec("UPDATE telegram_deliveries SET state='sent'");
    fails(fn()=>telegram_send($id,fn()=>['ok'=>false,'error_code'=>403]),'API rejection');
    check($db->query('SELECT state FROM telegram_deliveries')->fetchColumn()==='failed','known failure recorded');
    $input['revision']=$p['revision'];$input['telegram_text']=str_repeat('я',4097);$p=save_post($id,$input);$calls=[];telegram_send($id,$mock);check(count($calls)===2,'long story split without truncation');
    $input['revision']=$p['revision'];$input['telegram_text']='';$input['status']='draft';$p=save_post($id,$input);fails(fn()=>telegram_send($id,$mock),'draft cannot go to channel');
    $db->exec(file_get_contents(ROOT.'/database.sql'));check(post($id)['body']===$input['body'],'migration preserves content');
    $db->exec('VACUUM INTO '.$db->quote($dir.'/copy.sqlite'));$copy=new PDO('sqlite:'.$dir.'/copy.sqlite');check($copy->query('PRAGMA integrity_check')->fetchColumn()==='ok','consistent SQLite snapshot');
} finally {
    $db=null; $copy=null;
    foreach(glob($dir.'/*') as $file){if(is_file($file))unlink($file);}rmdir($dir.'/photos');rmdir($dir);
}

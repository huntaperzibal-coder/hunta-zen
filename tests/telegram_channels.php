<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/zen-channels-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/photos',0700);
$config=['storage'=>$dir,'database_backup_dir'=>$dir.'/backups','site_url'=>'https://example.test','telegram_token'=>'123456:'.str_repeat('a',35),'telegram_channel'=>'-100111','telegram_channel_title'=>'Тестовый'];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));
function channels_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'OK: '.$label."\n";}
try{
    $db->prepare("INSERT INTO posts(slug,title,body,created_at,updated_at) VALUES('two','Один пост','Общий текст',?,?)")->execute([now(),now()]);$id=(int)$db->lastInsertId();
    $calls=[];$mock=function($method,$payload)use(&$calls,$config){$calls[]=[$method,$payload,$GLOBALS['config']['telegram_token']];return ['ok'=>true,'result'=>['message_id'=>$payload['message_id']??(count($calls)+100)]];};
    telegram_send($id,$mock);
    $token2='654321:'.str_repeat('b',35);
    $readCalls=[];$readOnly=function($method,$payload)use(&$readCalls){$readCalls[]=$method;return ['ok'=>true,'result'=>match($method){'getMe'=>['id'=>10,'username'=>'main_bot'],'getChat'=>['id'=>-100222,'type'=>'channel','title'=>'Основной канал'],'getChatMember'=>['status'=>'administrator','can_post_messages'=>true,'can_edit_messages'=>true]}];};
    telegram_save_connection(['telegram_channel'=>'@main_channel','telegram_token'=>$token2,'channel_label'=>'Основной','make_default'=>'1'],$readOnly);
    channels_check($readCalls===['getMe','getChat','getChatMember'],'adding channel never publishes');
    channels_check(count(telegram_channels())===2,'legacy channel preserved with its token');
    $saved=require $dir.'/telegram-connection.php';channels_check($saved['telegram_channel']==='-100222'&&(fileperms($dir.'/telegram-connection.php')&0777)===0600,'new default stored privately');
    $states=$db->prepare('SELECT state FROM telegram_deliveries WHERE post_id=? AND channel=?');$states->execute([$id,'-100222']);channels_check(!$states->fetchColumn(),'main starts unsent without resetting posts or test history');
    telegram_send($id,$mock);channels_check($calls[1][1]['chat_id']==='-100222'&&$calls[1][2]===$token2,'new channel uses its own bot');
    telegram_select_channel('-100111');telegram_send($id,$mock);channels_check(isset($calls[2][1]['message_id'])&&$calls[2][1]['chat_id']==='-100111','test channel edits its original message');
    telegram_schedule_post($id,date('Y-m-d\TH:i',time()+3600));telegram_select_channel('-100222');telegram_schedule_post($id,date('Y-m-d\TH:i',time()+3600));
    channels_check(telegram_cancel_schedule($id,'-100111')===1&&(int)$db->query("SELECT count(*) FROM telegram_schedule WHERE channel='-100222' AND state='scheduled'")->fetchColumn()===1,'cancel affects only selected channel');
    telegram_select_channel('-100111');telegram_schedule_post($id,date('Y-m-d\TH:i',time()+3600));$db->exec("UPDATE telegram_schedule SET due_at=0 WHERE state='scheduled'");$calls=[];
    channels_check(telegram_run_due($mock)===2,'one worker processes schedules of both channels');
    channels_check($calls[0][1]['chat_id']==='-100222'&&$calls[0][2]===$token2&&$calls[1][1]['chat_id']==='-100111'&&$config['telegram_channel']==='-100111','worker uses pinned channel and token then restores context');
    $calls=[];post_manage($id,1,'telegram_delete',$mock);
    $states->execute([$id,'-100222']);channels_check(count($calls)===1&&$calls[0][1]['chat_id']==='-100111'&&$states->fetchColumn()==='sent','selected channel deletion preserves main publication');
    telegram_store_channel(telegram_channels()['-100111'],true);$saved=require $dir.'/telegram-connection.php';channels_check($saved['telegram_channel']==='-100111'&&count($saved['telegram_channels'])===2,'default can change without losing channels');
    telegram_select_channel('-100222');ob_start();telegram_editor(post($id),null);$html=ob_get_clean();channels_check(str_contains($html,'Основной')&&str_contains($html,'Тестовый')&&!str_contains($html,$token2),'editor has independent statuses without exposing secrets');
    telegram_select_channel('-100111');
    try{telegram_bind_existing($id,'35',false);throw new LogicException('unchecked adoption accepted');}catch(RuntimeException $e){channels_check(true,'manual check required before adoption');}
    telegram_bind_existing($id,'35',true);$states->execute([$id,'-100111']);channels_check($states->fetchColumn()==='sent','existing message adopted without publishing');
    $calls=[];telegram_send($id,$mock);channels_check(count($calls)===1&&($calls[0][1]['message_id']??null)===35,'adopted post edits exact message instead of sending duplicate');
    $db->prepare("INSERT INTO posts(slug,title,body,created_at,updated_at) VALUES('other','Другой пост','Текст',?,?)")->execute([now(),now()]);$other=(int)$db->lastInsertId();
    try{telegram_bind_existing($other,'35',true);throw new LogicException('duplicate binding accepted');}catch(RuntimeException $e){channels_check(true,'one Telegram message cannot bind to two posts');}
    telegram_schedule_post($other,date('Y-m-d\TH:i',time()+3600));
    try{telegram_bind_existing($other,'36',true);throw new LogicException('pending adoption accepted');}catch(RuntimeException $e){channels_check(true,'scheduled destination must be cancelled before adoption');}
    telegram_cancel_schedule($other,'-100111');telegram_bind_existing($other,'36',true);
    $mediaCalls=[];
    try{telegram_send($other,function($method,$payload)use(&$mediaCalls){$mediaCalls[]=[$method,$payload];return ['ok'=>false,'error_code'=>400,'description'=>'Bad Request: there is no text in the message to edit'];});throw new LogicException('media edit accepted');}catch(RuntimeException $e){channels_check(str_contains($e->getMessage(),'Привязка сохранена'),'media edit refusal explains preserved binding');}
    $mediaRecord=$db->query('SELECT * FROM telegram_deliveries WHERE post_id='.$other)->fetch();channels_check($mediaRecord['state']==='sent'&&(int)$mediaRecord['message_id']===36&&count($mediaCalls)===1,'media refusal keeps message id and never creates replacement');
    try{telegram_send($other,function()use(&$mediaCalls){$mediaCalls[]='unexpected';return ['ok'=>true];});throw new LogicException('known media retry accepted');}catch(RuntimeException $e){channels_check(count($mediaCalls)===1,'known non-text post does not repeat unsupported edit');}
    try{telegram_schedule_post($other,date('Y-m-d\TH:i',time()+3600));throw new LogicException('known media scheduled');}catch(RuntimeException $e){channels_check(true,'unsupported media update cannot be scheduled');}
    ob_start();telegram_editor(post($other),$mediaRecord);$mediaHtml=ob_get_clean();channels_check(str_contains($mediaHtml,'✓ Пост размещён в канале')&&!str_contains($mediaHtml,'value="telegram"'),'media binding stays published and hides unsupported send action');
    $db->prepare("INSERT INTO posts(slug,title,body,created_at,updated_at) VALUES('photo-bound','Фото','Текст',?,?)")->execute([now(),now()]);$photoPost=(int)$db->lastInsertId();telegram_bind_existing($photoPost,'37',true,'media');
    channels_check(telegram_nontext_message((string)$db->query('SELECT error FROM telegram_deliveries WHERE post_id='.$photoPost)->fetchColumn()),'media can be adopted directly without an API edit');
    telegram_select_channel('-100222');
    try{telegram_select_channel('-100999');throw new LogicException('unknown channel accepted');}catch(RuntimeException $e){channels_check($config['telegram_channel']==='-100222','unknown destination rejected without changing context');}
}finally{$db=null;$walk=function($path)use(&$walk){foreach(glob($path.'/*') as $f){if(is_dir($f))$walk($f);else unlink($f);}rmdir($path);};$walk($dir);}

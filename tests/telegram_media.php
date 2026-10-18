<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/zen-media-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/photos',0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test','telegram_token'=>'123456:'.str_repeat('a',35),'telegram_channel'=>'-100123'];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));
function media_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'OK: '.$label."\n";}
function media_fails(callable $call,string $label):void{try{$call();}catch(RuntimeException $e){echo 'OK: '.$label."\n";return;}throw new LogicException($label);}
function media_post(string $slug,string $body):array{global $db;$db->prepare('INSERT INTO posts(slug,title,body,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([$slug,'Заголовок',$body,now(),now()]);return post((int)$db->lastInsertId());}
try{
    $p=media_post('legacy',DAO_RICH_PREFIX.'<p>🧸 <strong>Мысль</strong><br>Новая строка</p><blockquote>Цитата</blockquote>');$id=(int)$p['id'];
    telegram_bind_existing($id,'35',true,'media');
    $calls=[];$mock=function($method,$payload)use(&$calls){$calls[]=[$method,$payload];return ['ok'=>true,'result'=>['message_id'=>$payload['message_id']]];};
    telegram_send($id,$mock,null,'caption');$call=$calls[0];
    media_check(count($calls)===1&&$call[0]==='editMessageCaption'&&$call[1]['message_id']===35,'caption edits original message in one API request');
    media_check(isset($call[1]['caption_entities'])&&str_contains($call[1]['caption'],"Мысль\nНовая строка"),'caption formatting and line breaks retained');
    $bold=array_values(array_filter($call[1]['caption_entities'],fn($e)=>$e['type']==='bold'));$encoded=mb_convert_encoding($call[1]['caption'],'UTF-16LE','UTF-8');
    media_check(in_array('Мысль',array_map(fn($e)=>mb_convert_encoding(substr($encoded,$e['offset']*2,$e['length']*2),'UTF-8','UTF-16LE'),$bold),true),'UTF-16 entity offsets stay correct after emoji');
    $meta=telegram_message_meta($id,$config['telegram_channel']);media_check($meta['format']==='media'&&$meta['synced_hash']!==''&&$meta['synced_at'],'format survives successful edit and sync is recorded only after confirmation');
    $photo=str_repeat('a',32).'.png';file_put_contents($dir.'/photos/'.$photo,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jN1cAAAAASUVORK5CYII='));$db->prepare('UPDATE posts SET cover=? WHERE id=?')->execute([$photo,$id]);
    $calls=[];telegram_send($id,$mock);$payload=$calls[0][1];media_check($calls[0][0]==='editMessageMedia'&&$payload['media']['type']==='photo'&&$payload['replacement'] instanceof CURLFile&&$payload['message_id']===35,'photo and caption replace existing media without changing message id');
    media_check($payload['media']['media']==='attach://replacement'&&isset($payload['media']['caption_entities']),'photo upload and formatted caption share one atomic edit');
    $p=post($id);$before=telegram_message_meta($id,$config['telegram_channel']);
    media_fails(fn()=>telegram_send($id,fn()=>['ok'=>false,'error_code'=>400,'description'=>'message cannot be edited']), 'provider rejection does not create replacement');
    $after=telegram_message_meta($id,$config['telegram_channel']);media_check($before['synced_hash']===$after['synced_hash']&&$before['synced_at']===$after['synced_at'],'failed edit never advances confirmed sync');
    $calls=[];telegram_send($id,$mock,null,'caption');media_check($calls[0][0]==='editMessageCaption'&&!isset($calls[0][1]['replacement']),'explicit caption-only mode preserves original photo');
    $video=str_repeat('b',32).'.mp4';file_put_contents($dir.'/photos/'.$video,pack('Na4',16,'ftyp').'isom'.pack('N',0));
    $db->prepare('UPDATE posts SET body=? WHERE id=?')->execute([DAO_RICH_PREFIX.'<p>Видео</p><video src="'.photo_url($video).'"></video>',$id]);
    $calls=[];media_fails(fn()=>telegram_send($id,$mock),'ambiguous photo plus video rejected before network');media_check($calls===[],'ambiguous media makes no API call');
    telegram_send($id,$mock,null,'video');$payload=$calls[0][1];media_check($calls[0][0]==='editMessageMedia'&&$payload['media']['type']==='video'&&$payload['message_id']===35&&$payload['replacement']->getMimeType()==='video/mp4','video replacement uses exact message and multipart upload');
    $p=post($id);$p['telegram_footer']='off';$p['title']='A';$p['body']=str_repeat('x',1021);$doc=telegram_media_plan($p,'caption');media_check($doc['length']===1024,'exact caption limit accepted');$p['body'].='x';media_fails(fn()=>telegram_media_plan($p,'caption'),'over-limit caption never silently truncated');
    $db->prepare('UPDATE posts SET body=? WHERE id=?')->execute([str_repeat('x',2000),$id]);$before=telegram_message_meta($id,$config['telegram_channel']);$calls=[];
    media_fails(fn()=>telegram_send($id,$mock,null,'caption'),'long caption stopped before API');media_check($calls===[]&&telegram_message_meta($id,$config['telegram_channel'])['synced_hash']===$before['synced_hash'],'preflight failure preserves old message and sync');
    $db->prepare('UPDATE posts SET body=? WHERE id=?')->execute(['Короткий текст',$id]);telegram_send($id,$mock,null,'caption');
    telegram_schedule_post($id,date('Y-m-d\TH:i',time()+3600),'caption');$job=$db->query('SELECT * FROM telegram_schedule ORDER BY id DESC LIMIT 1')->fetch();media_check(json_decode($job['snapshot'],true)['_telegram_media_mode']==='caption','schedule freezes requested media edit mode');$db->exec('UPDATE telegram_schedule SET due_at=0');$calls=[];media_check(telegram_run_due($mock)===1&&$calls[0][0]==='editMessageCaption','worker edits legacy caption without publishing a new post');
    $db->prepare('UPDATE posts SET body=? WHERE id=?')->execute(['Изменённый после отправки текст',$id]);
    $delivery=$db->query('SELECT * FROM telegram_deliveries WHERE post_id='.$id)->fetch();ob_start();telegram_editor(post($id),$delivery);$changed=ob_get_clean();media_check(str_contains($changed,'Есть изменения для Telegram'),'saved edits are not mislabeled synchronized');
    $prior=telegram_message_meta($id,$config['telegram_channel']);media_fails(fn()=>telegram_send($id,fn()=>throw new RuntimeException('timeout'),null,'caption'),'uncertain edit blocks retries');$calls=[];media_fails(fn()=>telegram_send($id,$mock,null,'caption'),'uncertain edit is not repeated');media_check($calls===[]&&telegram_message_meta($id,$config['telegram_channel'])['synced_at']===$prior['synced_at'],'timeout does not claim synchronization');
    telegram_resolve($id,0);$calls=[];
    telegram_send($id,function($method,$payload)use(&$calls){$calls[]=[$method,$payload];return ['ok'=>true,'result'=>['message_id'=>900]];});
    media_check(count($calls)===1&&$calls[0][0]==='sendRichMessage','new publication requires explicit manual confirmation of old message absence');
    $db->exec(file_get_contents(ROOT.'/database.sql'));media_check((int)$db->query('SELECT count(*) FROM telegram_message_meta')->fetchColumn()===1,'migration preserves existing metadata idempotently');
}finally{$db=null;$walk=function($path)use(&$walk){foreach(glob($path.'/*') as $f){if(is_dir($f))$walk($f);else unlink($f);}rmdir($path);};$walk($dir);}

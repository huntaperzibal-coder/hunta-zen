<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/zen-management-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/photos',0700);
$config=['storage'=>$dir,'database_backup_dir'=>$dir.'/backups','site_url'=>'https://example.test','telegram_token'=>'dummy-test-token','telegram_channel'=>'-100123'];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));
function check_manage(bool $ok,string $text):void{if(!$ok)throw new RuntimeException($text);echo 'OK: '.$text."\n";}
function manage_fails(callable $fn,string $text):void{try{$fn();}catch(RuntimeException $e){echo 'OK: '.$text."\n";return;}throw new RuntimeException($text);}
try{
    $db->prepare("INSERT INTO posts(slug,title,body,created_at,updated_at) VALUES('only-tg','Только Telegram','Текст',?,?)")->execute([now(),now()]);$id=(int)$db->lastInsertId();
    $calls=[];$mock=function($method,$payload)use(&$calls){$calls[]=[$method,$payload];return ['ok'=>true,'result'=>['message_id'=>101]];};
    telegram_send($id,$mock);check_manage(post($id)['status']==='draft'&&$calls[0][0]==='sendRichMessage','draft can be sent to Telegram without website publication');
    check_manage(!str_contains($calls[0][1]['rich_message']['html'],'Читать на сайте')&&!str_contains(telegram_text(post($id)),'/?p='),'Telegram-only post has no broken website link in send or preview');
    telegram_schedule_post($id,date('Y-m-d\TH:i',time()+3600));
    post_manage($id,1,'site_publish');check_manage(post($id)['status']==='published','list can publish on site independently');
    $calls=[];post_manage($id,2,'site_hide',$mock);check_manage(post($id)['status']==='draft'&&$calls===[],'hiding site leaves Telegram untouched');
    $calls=[];manage_fails(fn()=>post_manage($id,2,'post_delete',$mock),'stale revision stops deletion');check_manage($calls===[],'stale delete makes no API requests');
    post_manage($id,3,'telegram_delete',$mock);check_manage(post($id)['status']==='draft'&&$calls[0][0]==='deleteMessage','Telegram deletion keeps system post');
    check_manage(!$db->query("SELECT 1 FROM telegram_schedule WHERE state='scheduled'")->fetchColumn(),'Telegram deletion cancels pending publication');
    $calls=[];telegram_send($id,$mock);
    manage_fails(fn()=>post_manage($id,3,'post_delete',fn()=>['ok'=>false,'description'=>"Bad Request: message can't be deleted"]),'Telegram rejection prevents full deletion');check_manage(post($id)['id']===$id,'post survives failed remote deletion');
    $db->prepare("UPDATE telegram_deliveries SET state='unknown' WHERE post_id=?")->execute([$id]);
    manage_fails(fn()=>post_manage($id,3,'post_delete',$mock),'uncertain delivery cannot silently vanish');
    $db->prepare("UPDATE telegram_deliveries SET state='sent' WHERE post_id=?")->execute([$id]);
    $db->prepare("INSERT INTO telegram_deliveries(post_id,channel,message_id,state,updated_at) VALUES(?,'-100OLD',202,'sent',?)")->execute([$id,now()]);
    $calls=[];post_manage($id,3,'post_delete',$mock);check_manage(count($calls)===2,'full deletion removes known posts across channels without duplicate deletes');
    check_manage(!$db->query('SELECT 1 FROM posts')->fetchColumn()&&!$db->query('SELECT 1 FROM telegram_schedule')->fetchColumn(),'full deletion clears post and dependent records');
    $backups=glob($dir.'/backups/*.sqlite');check_manage(count($backups)>0,'verified database snapshot created before full deletion');$copy=new PDO('sqlite:'.end($backups));check_manage((int)$copy->query('SELECT count(*) FROM posts')->fetchColumn()===1,'pre-delete snapshot contains deleted post');$copy=null;
}finally{$db=null;$walk=function($path)use(&$walk){foreach(glob($path.'/*') as $f){if(is_dir($f))$walk($f);else unlink($f);}rmdir($path);};$walk($dir);}

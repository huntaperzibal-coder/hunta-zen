<?php
declare(strict_types=1);
require dirname(__DIR__).'/photo-desk/app.php';
use function PhotoDesk\{connect,schema,save,record,source_url};
$dir=sys_get_temp_dir().'/desk-test-'.bin2hex(random_bytes(6));mkdir($dir,0700);$db=connect($dir);schema($db);
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejects(callable $fn):void{try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected rejection');}
$id=save($db,null,0,['title'=>'Фото','company'=>'Компания','request_note'=>'Нужен исходник','source_url'=>'https://example.org/image'],'admin','owner');
check($id===1,'Stable ID');$row=record($db,$id);
save($db,$id,1,['ready'=>1],'photographer','owner');$row=record($db,$id);check($row['ready']===1 && $row['closed']===0,'Photographer does not close');
rejects(fn()=>save($db,$id,2,['closed'=>1],'photographer','owner'));
rejects(fn()=>save($db,$id,1,['response_note'=>'stale'],'admin','owner'));
save($db,$id,2,['closed'=>1],'lawyer','lawyer');$row=record($db,$id);check($row['closed']===1,'Lawyer closes');
save($db,$id,3,['ready'=>0],'photographer','owner');check(record($db,$id)['closed']===1,'Closure independent');
rejects(fn()=>save($db,$id,4,['ready'=>1],'lawyer','lawyer'));
save($db,$id,4,['closed'=>0],'lawyer','lawyer');check(record($db,$id)['closed']===0,'Reopen');
rejects(fn()=>source_url('javascript:alert(1)'));rejects(fn()=>source_url('https://user:pass@example.org'));rejects(fn()=>save($db,null,0,['title'=>'x'],'photographer','owner'));
save($db,$id,5,['response_note'=>'Ответ'],'photographer','owner',[['side'=>'response','name'=>'test.txt','mime'=>'text/plain','size'=>5,'storage_key'=>'test']]);
rejects(fn()=>save($db,$id,6,[],'lawyer','lawyer',[],1));check((int)$db->query('SELECT count(*) FROM attachments')->fetchColumn()===1,'Foreign side attachment preserved');
check(record($db,$id)['revision']===6,'Failed operation rolls back revision');
$db=null;unlink($dir.'/desk.sqlite');rmdir($dir);echo "Photo desk domain checks passed\n";

<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/hunta-categories-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test','telegram_token'=>'','telegram_channel'=>''];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';
function check_category(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'OK: '.$message."\n";}
try {
    $sql=file_get_contents(ROOT.'/database.sql');$old=explode('CREATE TABLE IF NOT EXISTS categories',$sql)[0];$db->exec($old);
    $db->prepare("INSERT INTO posts(title,body,slug,status,created_at,updated_at,published_at) VALUES(?,?,?,'published',?,?,?)")->execute(['Прежняя история','Текст до обновления','old',now(),now(),now()]);$id=(int)$db->lastInsertId();
    $db->exec($sql);$db->exec($sql);
    check_category(count(post_categories())===2 && post($id)['body']==='Текст до обновления' && (int)post($id)['category_id']===0,'idempotent migration preserves existing posts');
    $vinnie=category_by_slug('vinnie');$gena=category_by_slug('gena');
    check_category($vinnie['title']==='Диалоги Винни' && $gena['title']==='Диалоги Гены','two named categories');
    $input=['revision'=>1,'title'=>'Прежняя история','body'=>'Текст до обновления','category_id'=>$vinnie['id']];$p=save_post($id,$input,true);
    check_category((int)$p['category_id']===(int)$vinnie['id'],'autosave keeps category');
    $before=$p;
    try { save_post($id,[...$input,'revision'=>$p['revision'],'body'=>'Нельзя сохранять частично','category_id'=>999999]);throw new LogicException('invalid category accepted'); }catch(RuntimeException $e){}
    check_category(post($id)['body']===$before['body'] && post($id)['revision']===$before['revision'],'invalid category does not change text');
    try {save_post($id,[...$input,'category_id'=>$gena['id']]);throw new LogicException('stale revision accepted');}catch(RuntimeException $e){}
    check_category((int)post($id)['category_id']===(int)$vinnie['id'],'stale tab cannot change category');
    $p=save_post($id,['revision'=>$p['revision'],'title'=>$p['title'],'body'=>$p['body']],true);
    check_category((int)$p['category_id']===(int)$vinnie['id'],'old open form does not clear category');
    $p=save_post($id,['revision'=>$p['revision'],'title'=>$p['title'],'body'=>$p['body'],'category_id'=>0]);
    check_category((int)$p['category_id']===0 && $p['status']==='published','category can be cleared without unpublishing');
} finally{$db=null;foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);}

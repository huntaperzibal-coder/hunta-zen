<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/hunta-memory-'.bin2hex(random_bytes(6));mkdir($dir,0700);
file_put_contents($dir.'/config.php','<?php return '.var_export(['storage'=>$dir,'site_url'=>'https://example.test','telegram_token'=>'','telegram_channel'=>''],true).';');
putenv('HUNTA_CONFIG='.$dir.'/config.php');require dirname(__DIR__).'/app/bootstrap.php';
function memory_check(bool $ok,string $label):void{if(!$ok)throw new LogicException($label);echo "OK: $label\n";}
function memory_post(string $series,string $title,bool $published=true):array{
    global $db;
    $db->prepare('INSERT INTO posts(title,body,slug,status,created_at,updated_at,published_at) VALUES(?,?,?,?,?,?,?)')->execute([$title,'Текст '.$title,'memory-'.bin2hex(random_bytes(4)),$published?'published':'draft',now(),now(),now()]);$id=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO post_categories(post_id,category_id) VALUES(?,?)')->execute([$id,category_by_slug($series)['id']]);return post($id);
}
function memory_job(array $p,bool $legacy=false):int{
    global $db;
    $snapshot=['title'=>$p['title'],'text'=>body_plain($p['body']),'style_profile'=>illustration_style_profile($p['category_slug'])];
    if(!$legacy)$snapshot['series']=$p['category_slug'];
    $plan=['scene'=>'Мысль '.$p['title'],'prompt'=>'Образ '.$p['title'],'art_style'=>['title'=>'Импрессионизм']];
    if(!$legacy)$plan['memory']=['thought'=>'Краткая мысль '.$p['title'],'image'=>'Краткий образ '.$p['title']];
    $db->prepare("INSERT INTO illustration_jobs(post_id,revision,snapshot,state,image,plan,created_at,updated_at) VALUES(?,?,?,'done',?,?,?,?)")->execute([$p['id'],$p['revision'],json_encode($snapshot),bin2hex(random_bytes(16)).'.png',json_encode($plan),now(),now()]);return (int)$db->lastInsertId();
}
try{
    $db->exec(file_get_contents(ROOT.'/database.sql'));
    $target=memory_post('vinnie','Текущий',false);
    $first=memory_post('vinnie','Первый');$firstJob=memory_job($first);
    memory_check(illustration_memory_cards($target)===[],'unused generated variant is not memory');
    $old=$first;$first=illustration_apply($first,$firstJob,'inline');
    memory_check(count(illustration_memory_cards($target))===1 && setting('illustration_memory:'.$first['id'])!=='','accepted inline image stores a card atomically');
    $before=setting('illustration_memory:'.$first['id']);
    try{illustration_apply($old,$firstJob,'cover');throw new LogicException('stale selection allowed');}catch(RuntimeException $e){}
    memory_check(setting('illustration_memory:'.$first['id'])===$before && post($first['id'])['cover']==='','stale revision changes neither selection nor memory');
    $other=memory_post('gena','Чужой цикл');illustration_apply($other,memory_job($other),'cover');
    $draft=memory_post('vinnie','Черновик',false);illustration_apply($draft,memory_job($draft),'cover');
    memory_check(count(illustration_memory_cards($target))===1,'other cycle, draft and current post excluded');
    $first=save_post($first['id'],['title'=>$first['title'],'body'=>'Изменённая мысль','revision'=>$first['revision'],'category_id'=>$first['category_id']]);
    memory_check(illustration_memory_cards($target)===[],'edited story invalidates its old interpretation');
    $first=illustration_apply($first,memory_job($first),'cover');
    memory_check(count(illustration_memory_cards($target))===1,'new analysis and acceptance refresh memory');
    $legacy=memory_post('vinnie','Старая обложка');$legacyJob=memory_job($legacy,true);
    $db->prepare('UPDATE posts SET cover=(SELECT image FROM illustration_jobs WHERE id=?) WHERE id=?')->execute([$legacyJob,$legacy['id']]);
    memory_check(count(illustration_memory_cards($target))===2 && illustration_memory_cards($target)[0]['thought']==='Мысль Старая обложка','old accepted cover recovered without API calls or writes');
    memory_check(setting('illustration_memory:'.$legacy['id'])==='','read-only legacy recovery does not write the database');
    $extra=[];for($i=0;$i<7;$i++){$p=memory_post('vinnie','Предыдущий '.$i);$extra[]=illustration_apply($p,memory_job($p),'cover');}
    $cards=illustration_memory_cards($target);
    memory_check(count($cards)===5 && $cards[0]['title']==='Предыдущий 6' && $cards[4]['title']==='Предыдущий 2','latest five unique accepted stories, ordered deterministically');
    $changed=$extra[6];$db->prepare('UPDATE posts SET cover=? WHERE id=?')->execute(['',$changed['id']]);
    memory_check(illustration_memory_cards($target)[0]['title']==='Предыдущий 5','removed illustration is no longer used as accepted memory');
    $db->prepare('UPDATE post_categories SET category_id=? WHERE post_id=?')->execute([category_by_slug('gena')['id'],$extra[5]['id']]);
    memory_check(illustration_memory_cards($target)[0]['title']==='Предыдущий 4','changed category does not carry stale cycle interpretation');
    mkdir($dir.'/photos',0700);file_put_contents($dir.'/openrouter-key.php',"<?php return 'memory-test-key';");
    illustration_save_settings([...illustration_defaults(),'daily_limit'=>50],[]);
    $jobId=illustration_enqueue($target,'');$snapshot=json_decode($db->query('SELECT snapshot FROM illustration_jobs WHERE id='.$jobId)->fetchColumn(),true);
    memory_check(count($snapshot['cycle_memory'])===5 && count($snapshot['style_choices'])===4,'memory is frozen in snapshot without restricting styles');
    $db->prepare('UPDATE posts SET title=? WHERE id=?')->execute(['Правка названия',$extra[4]['id']]);
    memory_check(illustration_memory_cards($target)[0]['title']!==$snapshot['cycle_memory'][0]['title'],'future edits change future context only');
    $calls=[];illustration_run_one(function($path,$payload,$key)use(&$calls,$snapshot){
        $calls[]=[$path,$payload];
        if($path==='chat/completions'){
            $input=json_decode($payload['messages'][1]['content'],true);
            memory_check($input['cycle_memory']===$snapshot['cycle_memory'] && $input['story']===$snapshot['text'],'reader gets immutable cards and full current story');
            memory_check(str_contains($payload['messages'][0]['content'],'не придумывай отсылки'),'reader treats memory as reference, not new instructions');
            return ['choices'=>[['message'=>['content'=>json_encode(['scene'=>'Текущая мысль','prompt'=>'Текущий образ','style_id'=>'magritte','style_reason'=>'Визуальный парадокс','memory'=>['thought'=>str_repeat('а',1000),'image'=>'Текущий образ']])]]],'usage'=>['cost'=>0.001]];
        }
        memory_check(!str_contains($payload['prompt'],'Предыдущий'),'painter receives the chosen scene, not past story cards');
        return ['data'=>[['b64_json'=>'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j6ioAAAAASUVORK5CYII=']],'usage'=>['cost'=>0.04]];
    });
    $result=illustration_result($jobId,$target['id']);$plan=json_decode($result['plan'],true);
    memory_check(count($calls)===2 && mb_strlen($plan['memory']['thought'])===500 && abs((float)$result['cost']-.041)<.000001,'bounded card uses the existing two paid calls and cost ledger');
    memory_check(setting('illustration_memory:'.$target['id'])==='','generation alone does not accept its own card');
    $target=illustration_apply($target,$jobId,'cover');
    memory_check(setting('illustration_memory:'.$target['id'])!=='','acceptance persists the generated card');
    memory_check(illustration_memory_cards(['id'=>$target['id'],'category_slug'=>''])===[],'uncategorized posts receive no cycle context');
}finally{
    $db=null;$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($dir);
}

<?php
declare(strict_types=1);
const ILLUSTRATOR_REPOSITORY='huntaperzibal-coder/hunta-zen';
function illustration_backend(): string { return setting('illustrator_backend','local'); }
function illustration_remote_expire(): void {
    global $db;
    $db->prepare("UPDATE illustration_jobs SET state='unknown',error='Обработчик Actions не вернул результат вовремя. Проверь запуск в GitHub перед новым вариантом.',updated_at=? WHERE state IN ('planning','generating') AND id IN (SELECT job_id FROM illustration_remote_leases) AND julianday(updated_at)<julianday('now','-30 minutes')")->execute([now()]);
}
function illustration_bridge(array $request): array {
    global $db,$storage;
    $lock=fopen($storage.'/illustrator.lock','c');
    if(!$lock || !flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Обработчик занят.');
    try {
        if(illustration_backend()!=='actions')throw new RuntimeException('Режим Actions ещё не подключён.');
        illustration_remote_expire();
        file_put_contents($storage.'/illustrator-heartbeat',(string)time(),LOCK_EX);
        $op=(string)($request['op']??'');
        if($op==='probe_claim'){
            if(setting('illustrator_probe_state')!=='queued')return ['ok'=>true,'probe'=>null];
            $key=illustration_key();if($key==='')throw new RuntimeException('Не задан ключ OpenRouter.');
            $lease=bin2hex(random_bytes(32));
            $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')->execute(['illustrator_probe_lease',hash('sha256',$lease)]);
            $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')->execute(['illustrator_probe_state','running']);
            return ['ok'=>true,'probe'=>['neutral_test'=>true,'api_key'=>$key,'lease'=>$lease]];
        }
        if($op==='probe_finish'){
            if(!hash_equals(setting('illustrator_probe_lease','missing'),hash('sha256',(string)($request['lease']??''))))throw new RuntimeException('Нет разрешения.');
            if(in_array(setting('illustrator_probe_state'),['done','failed'],true))return ['ok'=>true,'already_saved'=>true];
            $result=$request['result']??[];
            $state=($result['state']??'')==='done'?'done':'failed';
            $error=mb_substr(str_replace(illustration_key(),'[ключ скрыт]',(string)($result['error']??'')),0,1600);
            $image='';
            if($state==='done')$image=illustration_store_image((string)($result['image_b64']??''));
            $value=json_encode(['image'=>$image,'state'=>$state,'cost'=>max(0,(float)($result['cost']??0)),'error'=>$error,'time'=>now()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')->execute(['illustrator_probe_result',$value]);
            $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')->execute(['illustrator_probe_state',$state]);
            return ['ok'=>true];
        }
        if($op==='claim'){
            illustration_close_obsolete_proofreads();
            $key=illustration_key();if($key==='')throw new RuntimeException('Не задан ключ OpenRouter.');
            $job=$db->query("SELECT * FROM illustration_jobs WHERE state='queued' ORDER BY id LIMIT 1")->fetch();
            if(!$job)return ['ok'=>true,'job'=>null];
            $snapshot=json_decode($job['snapshot'],true,512,JSON_THROW_ON_ERROR);$references=[];
            foreach($snapshot['settings']['references'] as $name){
                if(!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$name))throw new RuntimeException('Некорректное имя референса.');
                $file=$storage.'/photos/'.$name;
                if(!is_file($file)||filesize($file)>4*1024*1024)throw new RuntimeException('Референс недоступен.');
                $references[$name]=base64_encode(file_get_contents($file));
            }
            $token=bin2hex(random_bytes(32));$db->beginTransaction();
            try{
                $q=$db->prepare("UPDATE illustration_jobs SET state='planning',error='',updated_at=? WHERE id=? AND state='queued'");$q->execute([now(),$job['id']]);
                if(!$q->rowCount())throw new RuntimeException('Задание уже забрано.');
                $db->prepare('INSERT INTO illustration_remote_leases(job_id,token_hash,created_at) VALUES(?,?,?)')->execute([$job['id'],hash('sha256',$token),now()]);
                $db->commit();
            }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            file_put_contents($storage.'/illustrator-heartbeat',(string)time(),LOCK_EX);
            return ['ok'=>true,'job'=>['id'=>(int)$job['id'],'revision'=>(int)$job['revision'],'snapshot'=>$snapshot,'references'=>$references,'api_key'=>$key,'lease'=>$token]];
        }
        $id=(int)($request['id']??0);$token=(string)($request['lease']??'');
        $q=$db->prepare('SELECT j.*,l.token_hash FROM illustration_jobs j JOIN illustration_remote_leases l ON l.job_id=j.id WHERE j.id=?');$q->execute([$id]);$job=$q->fetch();
        if(!$job || !hash_equals($job['token_hash'],hash('sha256',$token)))throw new RuntimeException('Задание или разрешение не найдено.');
        if(in_array($job['state'],['done','failed'],true) && $op==='finish')return ['ok'=>true,'already_saved'=>true];
        if(!in_array($job['state'],['planning','generating'],true))throw new RuntimeException('Задание уже закрыто.');
        if($op==='progress'){
            $plan=$request['plan']??[];
            if(!is_array($plan)||strlen(json_encode($plan,JSON_THROW_ON_ERROR))>24000)throw new RuntimeException('Некорректное описание сцены.');
            $cost=(float)($request['cost']??0);if(!is_finite($cost)||$cost<0||$cost>1000)throw new RuntimeException('Некорректная стоимость.');
            $db->prepare("UPDATE illustration_jobs SET state='generating',plan=?,cost=?,updated_at=? WHERE id=?")->execute([json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$cost,now(),$id]);
        }elseif($op==='finish'){
            $state=(string)($request['state']??'');
            if(!in_array($state,['done','failed'],true))throw new RuntimeException('Некорректный результат.');
            $plan=$request['plan']??[];
            if(!is_array($plan)||strlen(json_encode($plan,JSON_THROW_ON_ERROR))>24000)throw new RuntimeException('Некорректное описание сцены.');
            $cost=(float)($request['cost']??0);if(!is_finite($cost)||$cost<0||$cost>1000)throw new RuntimeException('Некорректная стоимость.');
            $cost=max($cost,(float)$job['cost']);
            if(!$plan && $job['plan'])$plan=json_decode($job['plan'],true)?:[];
            $error=mb_substr((string)($request['error']??''),0,2000);$key=illustration_key();if($key!=='')$error=str_replace($key,'[ключ скрыт]',$error);
            $snapshot=json_decode($job['snapshot'],true);
            $proofread=($snapshot['mode']??'')==='proofread';
            if($proofread){$state='failed';$plan=[];$error='Проверка текста в админке отключена.';}
            $image=$state==='done'&&!$proofread?illustration_store_image((string)($request['image_b64']??'')):'';
            $db->prepare('UPDATE illustration_jobs SET state=?,plan=?,cost=?,error=?,image=?,updated_at=? WHERE id=?')->execute([$state,json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$cost,$error,$image,now(),$id]);
            audit('illustrator:actions:'.$state,(int)$job['post_id']);
        }else throw new RuntimeException('Неизвестная команда.');
        file_put_contents($storage.'/illustrator-heartbeat',(string)time(),LOCK_EX);
        return ['ok'=>true];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function illustration_github_token(): string {
    global $storage;$file=$storage.'/illustrator-github.php';return is_file($file)?(string)(require $file):'';
}
function illustration_save_github_token(string $token): void {
    global $storage;$token=trim($token);if($token==='')return;
    if(strlen($token)>512||preg_match('/\s/',$token))throw new RuntimeException('Некорректный токен GitHub.');
    $file=tempnam($storage,'github-');if(!$file)throw new RuntimeException('Не удалось сохранить токен.');chmod($file,0600);
    if(file_put_contents($file,'<?php return '.var_export($token,true).';',LOCK_EX)===false||!rename($file,$storage.'/illustrator-github.php')){@unlink($file);throw new RuntimeException('Не удалось сохранить токен.');}
}
function illustration_dispatch(bool $neutralTest=false): bool {
    global $db;
    if(illustration_backend()!=='actions')return false;
    if(!$neutralTest && !$db->query("SELECT 1 FROM illustration_jobs WHERE state='queued' LIMIT 1")->fetchColumn())return true;
    $token=illustration_github_token();
    $error='';
    if($token==='')$error='Добавь токен запуска GitHub в настройках. Очередь также можно запустить кнопкой Run workflow в GitHub.';
    else{
        $c=curl_init('https://api.github.com/repos/'.ILLUSTRATOR_REPOSITORY.'/actions/workflows/illustrator.yml/dispatches');
        curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($neutralTest?['ref'=>'main','inputs'=>['neutral_test'=>true]]:['ref'=>'main']),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/vnd.github+json','Content-Type: application/json','X-GitHub-Api-Version: 2022-11-28','User-Agent: hunta-zen-illustrator']]);
        $result=curl_exec($c);$code=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
        if($result===false||$code<200||$code>=300)$error='Не удалось подтвердить запуск Actions (HTTP '.$code.'). Проверь токен: нужен доступ Actions — Read and write к hunta-zen. Повторный запуск очереди не создаёт новый вариант.';
    }
    if($neutralTest && $error!=='')throw new RuntimeException($error);
    if(!$neutralTest)$db->prepare("UPDATE illustration_jobs SET error=? WHERE state='queued'")->execute([$error]);return $error==='';
}

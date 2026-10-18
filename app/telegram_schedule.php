<?php
declare(strict_types=1);
function telegram_save_connection(array $input,?callable $transport=null): void {
    global $config,$storage,$db;
    if(PHP_SAPI!=='cli' && (($_SERVER['HTTPS']??'')!=='on') && (int)($_SERVER['SERVER_PORT']??0)!==443)throw new RuntimeException('Подключай бота через HTTPS — открой https://hunta-zen.ru/admin.php?view=telegram');
    $token=trim((string)($input['telegram_token']??''));if($token==='')$token=(string)($config['telegram_token']??'');
    $channel=trim((string)($input['telegram_channel']??''));
    if(preg_match('~^https://t\.me/([A-Za-z0-9_]+)/*$~',$channel,$m))$channel='@'.$m[1];
    if(!preg_match('/^[0-9]{5,}:[A-Za-z0-9_-]{25,}$/D',$token))throw new RuntimeException('Вставь токен бота из BotFather.');
    if(!preg_match('/^(?:@[A-Za-z0-9_]{5,}|-100[0-9]+)$/D',$channel))throw new RuntimeException('Укажи @имя_канала или числовой ID вида -100… для закрытого канала.');
    $old=$config;$config['telegram_token']=$token;
    try{
        $call=$transport??'telegram_request';$me=$call('getMe',[]);$chat=$call('getChat',['chat_id'=>$channel]);
        if(empty($me['ok']) || empty($chat['ok']) || ($chat['result']['type']??'')!=='channel')throw new RuntimeException('Не удалось проверить бота или канал. Проверь токен, адрес и добавление бота в канал.');
        $member=$call('getChatMember',['chat_id'=>$chat['result']['id'],'user_id'=>$me['result']['id']]);
        $rights=$member['result']??[];
        if(empty($member['ok']) || !in_array($rights['status']??'', ['administrator','creator'],true) || (($rights['status']??'')!=='creator' && (empty($rights['can_post_messages']) || empty($rights['can_edit_messages']))))throw new RuntimeException('Добавь бота администратором канала с правами публикации и редактирования сообщений.');
        // Pin the numeric channel id: a changed public username cannot redirect scheduled posts.
        $connection=['telegram_token'=>$token,'telegram_channel'=>(string)$chat['result']['id'],'telegram_channel_title'=>(string)($chat['result']['title']??$channel),'telegram_bot_name'=>(string)($me['result']['username']??'')];
        $label=trim((string)($input['channel_label']??''));
        if(mb_strlen($label)>100)throw new RuntimeException('Название канала — максимум 100 символов.');
        if($label!=='')$connection['telegram_channel_title']=$label;
        $config=$old;telegram_store_channel($connection,!empty($input['make_default']));telegram_select_channel($connection['telegram_channel']);audit('telegram:connection-checked');
    }catch(Throwable $e){$config=$old;throw new RuntimeException($e instanceof RuntimeException?$e->getMessage():'Не удалось проверить Telegram. Подключение не изменено.');}
}
function telegram_schedule_post(int $id,string $value,string $mediaMode='auto'): void {
    global $db,$config;
    if(empty($config['telegram_token'])||empty($config['telegram_channel']))throw new RuntimeException('Сначала подключи Telegram.');
    $known=$db->prepare('SELECT * FROM telegram_deliveries WHERE post_id=? AND channel=?');$known->execute([$id,$config['telegram_channel']]);$delivery=$known->fetch();
    $meta=telegram_message_meta($id,$config['telegram_channel'],$delivery?:null);
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('Asia/Novosibirsk'));
    if(!$date || $date->format('Y-m-d\TH:i')!==$value || $date->getTimestamp()<=time()+30)throw new RuntimeException('Выбери будущее время, минимум через минуту. Часовой пояс — Новосибирск (UTC+7).');
    $p=post($id);if(trim($p['title'])===''||trim(body_plain($p['body']))==='')throw new RuntimeException('Для отправки нужны заголовок и текст.');if($meta['format']==='media'){telegram_media_plan($p,$mediaMode);$p['_telegram_media_mode']=$mediaMode;}else telegram_plan($p);
    $q=$db->prepare("SELECT 1 FROM telegram_deliveries WHERE post_id=? AND channel=? AND state IN ('sending','unknown')");$q->execute([$id,$config['telegram_channel']]);
    if($q->fetchColumn())throw new RuntimeException('Сначала проверь результат предыдущей отправки.');
    $db->beginTransaction();
    try{
        $q=$db->prepare("SELECT 1 FROM telegram_schedule WHERE post_id=? AND channel=? AND state='running'");$q->execute([$id,$config['telegram_channel']]);if($q->fetchColumn())throw new RuntimeException('Отправка уже началась.');
        $db->prepare("UPDATE telegram_schedule SET state='cancelled',updated_at=? WHERE post_id=? AND channel=? AND state='scheduled'")->execute([now(),$id,$config['telegram_channel']]);
        $db->prepare('INSERT INTO telegram_schedule(post_id,channel,bot_fingerprint,snapshot,due_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,$config['telegram_channel'],hash('sha256',$config['telegram_token']),json_encode($p,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$date->getTimestamp(),now(),now()]);
        $db->commit();audit('telegram:scheduled',$id);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function telegram_cancel_schedule(int $id,?string $channel=null): int {
    global $db;
    $filter=$channel===null?'':' AND channel=?';$params=$channel===null?[$id]:[$id,$channel];
    $db->beginTransaction();
    try{
        $q=$db->prepare("SELECT 1 FROM telegram_schedule WHERE post_id=? AND state='running'".$filter);$q->execute($params);if($q->fetchColumn())throw new RuntimeException('Отправка уже началась. Проверь канал.');
        $cancel=$db->prepare("UPDATE telegram_schedule SET state='cancelled',updated_at=? WHERE post_id=? AND state='scheduled'".$filter);$cancel->execute(array_merge([now()],$params));$count=$cancel->rowCount();
        $db->commit();audit('telegram:schedule-cancelled',$id);return $count;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}

}
function telegram_run_due(?callable $transport=null): int {
    global $db,$config,$storage;
    $lock=fopen($storage.'/telegram-worker.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))return 0;
    try{
        // Exclusive worker lock proves any leftover running task belonged to a dead worker.
        $db->prepare("UPDATE telegram_schedule SET state='failed',error='Предыдущий обработчик прерван. Проверь канал перед повтором.',updated_at=? WHERE state='running'")->execute([now()]);
        file_put_contents($storage.'/telegram-heartbeat',(string)time(),LOCK_EX);$count=0;
        // No automatic retries of running or failed tasks, including after process termination.
        $q=$db->prepare("SELECT * FROM telegram_schedule WHERE state='scheduled' AND due_at<=? ORDER BY due_at LIMIT 5");$q->execute([time()]);
        $workerConfig=$config;
        foreach($q->fetchAll() as $job){
            $config=$workerConfig;
            $claim=$db->prepare("UPDATE telegram_schedule SET state='running',updated_at=? WHERE id=? AND state='scheduled'");$claim->execute([now(),$job['id']]);if(!$claim->rowCount())continue;
            try{
                telegram_select_channel($job['channel']);
                if($job['channel']!==($config['telegram_channel']??'') || !hash_equals($job['bot_fingerprint'],hash('sha256',(string)($config['telegram_token']??''))))throw new RuntimeException('Подключение бота или канал изменены. Назначь отправку заново.');
                telegram_send((int)$job['post_id'],$transport,json_decode($job['snapshot'],true,512,JSON_THROW_ON_ERROR));
                $db->prepare("UPDATE telegram_schedule SET state='sent',error='',updated_at=? WHERE id=?")->execute([now(),$job['id']]);$count++;
            }catch(Throwable $e){
                $error=$e instanceof RuntimeException?$e->getMessage():'Отправка остановлена. Проверь канал перед повтором.';
                $db->prepare("UPDATE telegram_schedule SET state='failed',error=?,updated_at=? WHERE id=?")->execute([$error,now(),$job['id']]);
            }
        }
        $config=$workerConfig;return $count;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

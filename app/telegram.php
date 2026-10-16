<?php
declare(strict_types=1);
function telegram_text(array $p): string {
    global $config;
    $text=$p['telegram_text'] ?: $p['title']."\n\n".($p['excerpt'] ?: mb_substr(preg_replace('/!\[[^\]]*\]\([^)]+\)/','',$p['body']),0,700));
    return trim($text)."\n\n".rtrim($config['site_url'],'/').'/?p='.rawurlencode($p['slug']);
}
function telegram_send(int $id, ?callable $transport=null): void {
    global $config,$db,$storage;
    if (empty($config['telegram_token']) || empty($config['telegram_channel'])) throw new RuntimeException('Сначала укажи токен бота и канал в закрытом конфиге.');
    $lock=fopen($storage.'/telegram.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Уже идёт отправка. Подожди.');
    try {
        $p=post($id); if ($p['status']!=='published') throw new RuntimeException('Сначала опубликуй запись на сайте.');
        $text=telegram_text($p); if (mb_strlen($text)>4096) throw new RuntimeException('Текст для Telegram слишком длинный. Сократи его до 4096 символов вместе со ссылкой.');
        $channel=$config['telegram_channel'];
        $q=$db->prepare('SELECT * FROM telegram_deliveries WHERE post_id=? AND channel=?'); $q->execute([$id,$channel]); $delivery=$q->fetch();
        if ($delivery && in_array($delivery['state'],['sending','unknown'],true)) throw new RuntimeException('Результат прошлой отправки неизвестен. Проверь канал и укажи результат ниже.');
        $messageId=$delivery['message_id']??null;
        $db->prepare("INSERT INTO telegram_deliveries(post_id,channel,state,updated_at) VALUES(?,?,'sending',?) ON CONFLICT(post_id,channel) DO UPDATE SET state='sending',error='',updated_at=excluded.updated_at")->execute([$id,$channel,now()]);
        $payload=['chat_id'=>$channel,'text'=>$text]; if ($messageId) $payload['message_id']=(int)$messageId;
        $method=$messageId?'editMessageText':'sendMessage';
        try {
            if ($transport) $response=$transport($method,$payload);
            else {
                $curl=curl_init('https://api.telegram.org/bot'.$config['telegram_token'].'/'.$method);
                curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
                $raw=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
                if ($raw===false || $status>=500 || $status===0) throw new RuntimeException('Ответ не получен. Проверь канал перед повторной отправкой.');
                $response=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            }
            if (!isset($response['ok'])) throw new RuntimeException('Ответ не распознан. Проверь канал.');
        } catch (Throwable $e) {
            $db->prepare("UPDATE telegram_deliveries SET state='unknown',error=?,updated_at=? WHERE post_id=? AND channel=?")->execute(['Ответ не получен или не распознан. Проверь канал.',now(),$id,$channel]);
            throw new RuntimeException('Результат отправки неизвестен. Проверь канал; автоматического повтора не будет.');
        }
        if (!$response['ok']) {
            if ($messageId && str_contains((string)($response['description']??''),'message is not modified')) {
                $response=['ok'=>true,'result'=>['message_id'=>$messageId]];
            } else {
                // Токен и необработанный сетевой ответ не попадают в журнал.
                $code=(int)($response['error_code']??0);
                $db->prepare("UPDATE telegram_deliveries SET state='failed',error=?,updated_at=? WHERE post_id=? AND channel=?")->execute(['Telegram отклонил запрос, код '.$code,now(),$id,$channel]);
                throw new RuntimeException('Telegram отклонил запрос ('.$code.'). Проверь права бота, канал и текст.');
            }
        }
        $resultId=$response['result']['message_id']??$messageId;
        if (!$resultId) {
            $db->prepare("UPDATE telegram_deliveries SET state='unknown' WHERE post_id=? AND channel=?")->execute([$id,$channel]);
            throw new RuntimeException('Не получен номер сообщения. Проверь канал.');
        }
        $db->prepare("UPDATE telegram_deliveries SET state='sent',message_id=?,error='',updated_at=? WHERE post_id=? AND channel=?")->execute([$resultId,now(),$id,$channel]);
        audit($messageId?'telegram:update':'telegram:send',$id);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
}

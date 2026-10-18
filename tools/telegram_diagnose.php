<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
// No queue runner, scheduling, reconciliation or messages. Only a getMe connectivity probe.
echo 'Время сервера (UTC+7): '.date('d.m.Y H:i:s')."\n";
$heartbeat=is_file($storage.'/telegram-heartbeat')?(int)file_get_contents($storage.'/telegram-heartbeat'):0;
echo 'Последний запуск обработчика: '.($heartbeat?date('d.m.Y H:i:s',$heartbeat).' ('.max(0,time()-$heartbeat).' с назад)':'не зафиксирован')."\n";
echo 'Канал: '.(!empty($config['telegram_channel'])?'подключён':'не указан')."\n";
echo "Очередь — последние 5 задач:\n";
$q=$db->query('SELECT id,post_id,channel,due_at,state,error,updated_at FROM telegram_schedule ORDER BY id DESC LIMIT 5');
foreach($q as $row){
    echo '#'.(int)$row['id'].'; пост '.(int)$row['post_id'].'; канал '.telegram_safe_error(telegram_channel_name($row['channel'])).'; назначено '.date('d.m.Y H:i:s',(int)$row['due_at']).'; состояние '.telegram_safe_error($row['state']).'; обновлено '.telegram_safe_error($row['updated_at'])."\n";
    if($row['error']!=='')echo '  '.telegram_safe_error($row['error'])."\n";
}
echo "Отправки — последние 5 результатов:\n";
$q=$db->query('SELECT post_id,channel,state,error,updated_at FROM telegram_deliveries ORDER BY updated_at DESC LIMIT 5');
foreach($q as $row){
    echo 'Пост '.(int)$row['post_id'].'; канал '.telegram_safe_error(telegram_channel_name($row['channel'])).'; состояние '.telegram_safe_error($row['state']).'; обновлено '.telegram_safe_error($row['updated_at'])."\n";
    if($row['error']!=='')echo '  '.telegram_safe_error($row['error'])."\n";
}
if(in_array('--offline',$argv,true)){echo "Проверка связи пропущена (--offline). Ничего не отправлено.\n";exit;}
if(in_array('--network',$argv,true)){
    if(!function_exists('curl_init')){echo "Нужно расширение PHP cURL.\n";exit(1);}
    echo "Проверка HTTPS до Telegram без токена бота и без публикаций:\n";
    foreach(['Обычное соединение'=>CURL_IPRESOLVE_WHATEVER,'Только IPv4'=>CURL_IPRESOLVE_V4] as $label=>$resolve){
        $curl=curl_init('https://api.telegram.org/');
        curl_setopt_array($curl,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_IPRESOLVE=>$resolve]);
        curl_exec($curl);$errno=curl_errno($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$seconds=(float)curl_getinfo($curl,CURLINFO_TOTAL_TIME);$timing=telegram_transport_timing($curl);curl_close($curl);
        echo $label.': '.($errno===0&&$status>0?'HTTPS-соединение установлено; HTTP '.$status.'; '.number_format($seconds,1,'.','').' с.':telegram_transport_detail('HEAD',$errno,$status,$seconds,$timing))."\n";
    }
    echo "Токен не использовался. Посты и очередь не изменялись.\n";exit;
}
if(empty($config['telegram_token'])){echo "Ключ бота не задан. Проверка связи пропущена.\n";exit;}
if(!function_exists('curl_init')){echo "В этой версии PHP нет расширения cURL.\n";exit(1);}
echo "Проверка связи getMe (без публикации):\n";
try{
    $result=telegram_request('getMe',[]);
    if(!empty($result['ok']))echo "Telegram ответил, токен бота принят.\n";
    else echo 'Telegram отклонил проверку: '.(int)($result['error_code']??0).' '.telegram_safe_error((string)($result['description']??''))."\n";
}catch(TelegramTransportFailure $e){echo telegram_safe_error($e->getMessage())."\n";}
catch(Throwable $e){echo "Не удалось выполнить проверку; проверь расширения PHP и конфигурацию.\n";}
echo "Посты не отправлялись, очередь не изменялась.\n";

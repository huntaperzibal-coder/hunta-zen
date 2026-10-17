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
$q=$db->query('SELECT id,post_id,due_at,state,error,updated_at FROM telegram_schedule ORDER BY id DESC LIMIT 5');
foreach($q as $row){
    echo '#'.(int)$row['id'].'; пост '.(int)$row['post_id'].'; назначено '.date('d.m.Y H:i:s',(int)$row['due_at']).'; состояние '.telegram_safe_error($row['state']).'; обновлено '.telegram_safe_error($row['updated_at'])."\n";
    if($row['error']!=='')echo '  '.telegram_safe_error($row['error'])."\n";
}
echo "Отправки — последние 5 результатов:\n";
$q=$db->query('SELECT post_id,state,error,updated_at FROM telegram_deliveries ORDER BY updated_at DESC LIMIT 5');
foreach($q as $row){
    echo 'Пост '.(int)$row['post_id'].'; состояние '.telegram_safe_error($row['state']).'; обновлено '.telegram_safe_error($row['updated_at'])."\n";
    if($row['error']!=='')echo '  '.telegram_safe_error($row['error'])."\n";
}
if(in_array('--offline',$argv,true)){echo "Проверка связи пропущена (--offline). Ничего не отправлено.\n";exit;}
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

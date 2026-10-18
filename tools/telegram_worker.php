<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    require dirname(__DIR__).'/app/bootstrap.php';
    reader_feedback_cleanup();
    echo 'Telegram: завершено отправок — '.telegram_run_due()."\n";
}catch(Throwable $e){fwrite(STDERR,"Telegram: обработчик остановлен. Проверь подключение и структуру базы в админке.\n");exit(1);}

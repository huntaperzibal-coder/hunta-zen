<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{
    $mode=$argv[1]??'';
    if(!in_array($mode,['','--download','--list'],true)||count($argv)>2)throw new RuntimeException('Использование: php tools/backup_database.php [--download|--list]');
    if($mode==='--list'){
        $directory=database_backup_directory();echo "Каталог: $directory\n";
        $files=glob($directory.'/zen_*.sqlite')?:[];rsort($files);
        foreach(array_slice($files,0,20) as $file)echo basename($file).' · '.filesize($file)." байт\n";
        if(!$files)echo "Копий базы пока нет.\n";
        exit;
    }
    $file=database_backup_create();
    if($mode==='--download'){
        // stdout is exclusively the verified SQLite file; errors go to stderr.
        if(readfile($file)===false)throw new RuntimeException('Не удалось прочитать созданную копию.');
    }else echo "Проверенная копия базы: $file\nРазмер: ".filesize($file)." байт\nSHA-256: ".hash_file('sha256',$file)."\nФотографии, видео и файлы секретов в эту копию не входят.\n";
}catch(Throwable $e){fwrite(STDERR,'Ошибка резервного копирования: '.$e->getMessage()."\n");exit(1);}

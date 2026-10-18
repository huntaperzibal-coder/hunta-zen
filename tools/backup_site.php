<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{
    $mode=$argv[1]??'';
    if(!in_array($mode,['','--download'],true)||count($argv)>2)throw new RuntimeException('Использование: php tools/backup_site.php [--download]');
    $process=proc_open(['/usr/bin/python3',ROOT.'/tools/site_archive.py'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);
    if(!is_resource($process))throw new RuntimeException('Не удалось запустить Python 3 для полного архива.');
    fwrite($pipes[0],json_encode(['root'=>realpath(ROOT),'storage'=>$storage,'config'=>$configPath,'directory'=>database_backup_directory(),'download'=>$mode==='--download'],JSON_THROW_ON_ERROR));fclose($pipes[0]);
    exit(proc_close($process));
}catch(Throwable $e){fwrite(STDERR,'Ошибка полного архива: '.$e->getMessage()."\n");exit(1);}

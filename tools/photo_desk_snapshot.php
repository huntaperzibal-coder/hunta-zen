<?php
declare(strict_types=1);
namespace PhotoDesk;
function snapshot(\PDO $db,string $storage): string {
    $dir=$storage.'/backups/'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
    if(!mkdir($dir,0700,true))throw new \RuntimeException('Не удалось создать резервную копию.');
    $db->exec('VACUUM INTO '.$db->quote($dir.'/desk.sqlite'));chmod($dir.'/desk.sqlite',0600);
    $copy=new \PDO('sqlite:'.$dir.'/desk.sqlite');
    if($copy->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new \RuntimeException('Не прошла проверка базы в копии.');
    mkdir($dir.'/files',0700);
    foreach(new \DirectoryIterator($storage.'/files') as $file){if(!$file->isFile())continue;$destination=$dir.'/files/'.$file->getFilename();if(!copy($file->getPathname(),$destination)||hash_file('sha256',$file->getPathname())!==hash_file('sha256',$destination))throw new \RuntimeException('Не удалось проверить файл резервной копии.');chmod($destination,0600);}
    if(!copy($storage.'/config.php',$dir.'/config.php'))throw new \RuntimeException('Не удалось скопировать конфиг.');chmod($dir.'/config.php',0600);
    return $dir;
}
if(PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){require dirname(__DIR__).'/photo-desk/app.php';$config=require (getenv('PHOTO_DESK_CONFIG')?:dirname(__DIR__).'/storage/photo-desk/config.php');echo snapshot(connect($config['storage']),$config['storage'])."\n";}

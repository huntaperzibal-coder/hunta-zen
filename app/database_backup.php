<?php
declare(strict_types=1);
function database_backup_directory(): string {
    global $config;
    return rtrim((string)($config['database_backup_dir']??(dirname((string)realpath(ROOT)).'/zen_backups')),'/');
}
/** A consistent SQLite snapshot, checked before it receives its final name. */
function database_backup_create(?string $directory=null): string {
    global $db;
    $directory=$directory??database_backup_directory();
    if($directory===''||$directory[0]!=='/')throw new RuntimeException('Нужен абсолютный путь каталога копий.');
    $public=realpath(ROOT.'/public');
    $probe=$directory;while(!file_exists($probe)&&dirname($probe)!==$probe)$probe=dirname($probe);
    $resolved=realpath($probe);
    if($resolved===false||($public&&($resolved===$public||str_starts_with($resolved,$public.'/'))))throw new RuntimeException('Копии базы должны находиться вне публичного каталога сайта.');
    if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Не удалось создать каталог копий: '.$directory);
    $real=realpath($directory);
    if(!$real||($public&&($real===$public||str_starts_with($real,$public.'/'))))throw new RuntimeException('Каталог копий находится внутри сайта.');
    if(!is_writable($real))throw new RuntimeException('Нет права записи в каталог копий: '.$real);
    $temporary=tempnam($real,'.zen-db-');if($temporary===false)throw new RuntimeException('Не удалось создать файл копии.');
    $check=null;
    try{
        chmod($temporary,0600);
        $db->exec('VACUUM INTO '.$db->quote($temporary));
        $check=new PDO('sqlite:'.$temporary,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        if($check->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('Копия базы не прошла проверку целостности.');
        foreach(['posts','settings'] as $table)$check->query('SELECT count(*) FROM '.$table)->fetchColumn();
        $check=null;
        $file=$real.'/zen_'.date('Y-m-d_H-i-s').'_'.bin2hex(random_bytes(4)).'.sqlite';
        if(!rename($temporary,$file))throw new RuntimeException('Не удалось завершить сохранение копии.');
        return $file;
    }finally{$check=null;if(is_file($temporary))unlink($temporary);}
}

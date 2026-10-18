<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/hunta-db-backup-'.bin2hex(random_bytes(5));mkdir($dir,0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test','database_backup_dir'=>$dir.'/private-copies','telegram_token'=>'','telegram_channel'=>''];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));
function check_backup(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'OK: '.$message."\n";}
function backup_cli(string $mode):array{
    $process=proc_open([getenv('PHP_BIN')?:'php',ROOT.'/tools/backup_database.php',$mode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($process),$out,$err];
}
try{
    $db->prepare('INSERT INTO posts(slug,title,body,created_at,updated_at) VALUES(?,?,?,?,?)')->execute(['one','Заголовок','Личный текст',now(),now()]);
    $file=database_backup_create();$before=hash_file('sha256',$file);
    $snapshot=new PDO('sqlite:'.$file);
    check_backup($snapshot->query('PRAGMA integrity_check')->fetchColumn()==='ok'&&$snapshot->query('SELECT body FROM posts')->fetchColumn()==='Личный текст','checked snapshot contains posts');
    check_backup((fileperms($file)&0777)===0600&&(fileperms(dirname($file))&0777)===0700,'private snapshot permissions');
    $db->exec("UPDATE posts SET body='Поздняя правка'");$other=database_backup_create();
    check_backup($file!==$other&&hash_file('sha256',$file)===$before,'new snapshots keep previous versions intact');$snapshot=null;
    [$exit,$out,$err]=backup_cli('--download');
    check_backup($exit===0&&$err===''&&str_starts_with($out,"SQLite format 3\0"),'download stdout is exclusively SQLite bytes');
    file_put_contents($dir.'/received.sqlite',$out);$received=new PDO('sqlite:'.$dir.'/received.sqlite');
    check_backup($received->query('PRAGMA integrity_check')->fetchColumn()==='ok'&&$received->query('SELECT body FROM posts')->fetchColumn()==='Поздняя правка','downloaded snapshot restores latest consistent data');$received=null;
    [$exit,$out]=backup_cli('--list');check_backup($exit===0&&str_contains($out,basename($file)),'CLI lists existing snapshots');
    [$exit,$out,$err]=backup_cli('--invalid');check_backup($exit===1&&$out===''&&$err!=='','CLI rejects invalid mode without leaking data');
    try{database_backup_create(ROOT.'/public/private-copy');throw new LogicException('public backup accepted');}catch(RuntimeException $e){check_backup(!is_dir(ROOT.'/public/private-copy'),'public backup directory rejected before writing');}
}finally{
    $db=null;
    foreach(glob($dir.'/private-copies/*')?:[] as $file)unlink($file);
    if(is_dir($dir.'/private-copies'))rmdir($dir.'/private-copies');
    foreach(glob($dir.'/*')?:[] as $file)if(is_file($file))unlink($file);rmdir($dir);
}

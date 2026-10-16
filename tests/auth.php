<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/hunta-auth-'.bin2hex(random_bytes(6));mkdir($dir,0700);mkdir($dir.'/photos',0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test','telegram_token'=>'','telegram_channel'=>''];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/tools/author_source.php';
$db->exec(file_get_contents(ROOT.'/database.sql'));
function expect(bool $ok,string $reason):void { if (!$ok)throw new RuntimeException($reason); }
try {
    $hash=password_hash('old',PASSWORD_DEFAULT);
    $db->prepare('INSERT INTO orda_users(login,password_hash) VALUES(?,?)')->execute(['Hunta',$hash]);
    expect(password_verify('old',ordaFindUser('hUNta')['password_hash']),'same short existing password');
    ordaLoginSession('Hunta',$hash);expect(authenticated(),'Orda session accepted');
    $db->prepare('UPDATE orda_users SET password_hash=?')->execute([password_hash('changed',PASSWORD_DEFAULT)]);
    expect(!authenticated(),'password change revokes session');
    ordaThrottleFail('login_user','hunta');
    for($i=0;$i<4;$i++)ordaThrottleFail('login_user','hunta');
    expect(ordaThrottleBlockedSeconds('login_user','hunta')>0,'Orda login throttle');
    ordaThrottleClear('login_user','hunta');expect(ordaThrottleBlockedSeconds('login_user','hunta')===0,'throttle cleared');
    mkdir($dir.'/source/site/db',0700,true);
    $source=new PDO('sqlite:'.$dir.'/source/site/db/topo.db');$source->exec('CREATE TABLE orda_users(login TEXT,password_hash TEXT)');
    $source->prepare('INSERT INTO orda_users VALUES(?,?)')->execute(['Hunta',$hash]);$source=null;
    $before=hash_file('sha256',$dir.'/source/site/db/topo.db');
    $author=source_author($dir.'/source','hunta');
    expect($author['password_hash']===$hash,'exact existing hash imported');
    expect(hash_file('sha256',$dir.'/source/site/db/topo.db')===$before,'source database unchanged');
    expect(source_author($dir.'/source','other')===null,'only specified account');
    session_write_close();
    echo "OK: Orda account lookup, same password, session revocation, throttle, read-only hash import\n";
} finally {
    $db=null;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){if($item->isDir())rmdir($item->getPathname());else unlink($item->getPathname());}rmdir($dir);
}

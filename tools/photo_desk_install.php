<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/photo-desk/app.php';
$root=dirname(__DIR__);$options=getopt('',['public:','url:','login:','shared']);
foreach(['pdo_sqlite','mbstring','curl','fileinfo','dom'] as $extension)if(!extension_loaded($extension))throw new RuntimeException('Нужно расширение '.$extension);
$storage=$root.'/storage/photo-desk';$configPath=$storage.'/config.php';
$url=rtrim((string)($options['url']??'https://files.hunta-zen.ru'),'/');
if(!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_PATH))throw new RuntimeException('Нужен HTTPS-адрес без пути.');
$shared=array_key_exists('shared',$options);
$target=realpath((string)($options['public']??'/home/a/altaikemp/files.altaikemp.beget.tech/public_html'));
if(!$shared && (!$target || !is_dir($target) || !is_writable($target)))throw new RuntimeException('Папка сайта недоступна. Укажи --public=/полный/путь/public_html');
if(!$shared && ($target===realpath($root.'/public') || str_contains((string)$target,'/storage')))throw new RuntimeException('Нельзя устанавливать кабинет в папку блога или данных.');
foreach([$storage,$storage.'/files',$storage.'/thumbs',$storage.'/sessions',$storage.'/backups'] as $dir)if(!is_dir($dir) && !mkdir($dir,0700,true))throw new RuntimeException('Не удалось создать '.$dir);
if(!is_file($configPath)){
    $blogPath=getenv('HUNTA_CONFIG')?:$root.'/storage/config.php';$author=null;
    if(is_readable($blogPath)){
        $blog=require $blogPath;
        $source=new PDO('sqlite:file:'.$blog['storage'].'/blog.sqlite?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $users=$source->query('SELECT login,password_hash FROM orda_users')->fetchAll(PDO::FETCH_ASSOC);
        $login=(string)($options['login']??'');
        if(!$login && count($users)===1)$login=$users[0]['login'];
        if(!$login){echo 'Логин фотографа из блога: ';$login=trim((string)fgets(STDIN));}
        foreach($users as $u)if(mb_strtolower($u['login'])===mb_strtolower($login))$author=$u;
    }
    if(!$author){require __DIR__.'/author_source.php';$author=choose_author();}
    $db=PhotoDesk\connect($storage);PhotoDesk\schema($db);
    $db->prepare('INSERT OR IGNORE INTO users VALUES(?,?,?)')->execute([$author['login'],$author['password_hash'],'admin']);chmod($storage.'/desk.sqlite',0600);
    $content="<?php\nreturn ".var_export(['storage'=>$storage,'url'=>$url],true).";\n";
    $handle=fopen($configPath,'x');if(!$handle)throw new RuntimeException('Конфиг уже создан другим процессом.');chmod($configPath,0600);fwrite($handle,$content);fclose($handle);
    echo 'Вход фотографа: '.$author['login'].". Прежний пароль сохранён. Обе галочки доступны в режиме разработки.\n";
}else{
    $config=require $configPath;if($config['url']!==$url)throw new RuntimeException('Существующий адрес отличается. Конфиг не изменён.');
    $storage=$config['storage'];$db=PhotoDesk\connect($storage);
    require __DIR__.'/photo_desk_snapshot.php';PhotoDesk\snapshot($db,$storage);
    PhotoDesk\schema($db);
}
if($shared){echo "Кабинет установлен: $url/\nВ Beget привяжи files.hunta-zen.ru к тому же сайту, что и hunta-zen.ru.\nОсновной домен открывает блог, поддомен files — отдельный кабинет.\n";exit;}
$entry="<?php\ndeclare(strict_types=1);\nputenv('PHOTO_DESK_CONFIG='.".var_export($configPath,true).");\nrequire ".var_export($root.'/photo-desk/public/index.php',true).";\n";
foreach(['index.php','index.html','index.htm'] as $file){$path=$target.'/'.$file;if(is_file($path) && file_get_contents($path)!==$entry){$backup=$storage.'/backups/'.$file.'.'.date('Ymd-His').'.'.bin2hex(random_bytes(3));if(!copy($path,$backup) || hash_file('sha256',$path)!==hash_file('sha256',$backup))throw new RuntimeException('Не удалось проверить резервную копию стартовой страницы.');chmod($backup,0600);}}
if(is_file($target.'/index.html') && !rename($target.'/index.html',$target.'/index.html.before-photo-desk'))throw new RuntimeException('Не удалось убрать прежнюю HTML-заглушку.');
if(is_file($target.'/index.htm') && !rename($target.'/index.htm',$target.'/index.htm.before-photo-desk'))throw new RuntimeException('Не удалось убрать прежнюю HTML-заглушку.');
$tmp=$target.'/.photo-desk-'.bin2hex(random_bytes(4)).'.tmp';file_put_contents($tmp,$entry);chmod($tmp,0644);if(!rename($tmp,$target.'/index.php'))throw new RuntimeException('Не удалось установить стартовую страницу.');
echo "Готово: $url/\nКод загружается из этого репозитория. После git pull отдельное копирование файлов не требуется.\n";

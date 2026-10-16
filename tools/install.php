<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
foreach (['pdo_sqlite','mbstring','curl','fileinfo'] as $extension) {
    if (!extension_loaded($extension)) exit('Нужно расширение PHP: '.$extension."\n");
}
$root=dirname(__DIR__); $storage=$root.'/storage';
$path=getenv('HUNTA_CONFIG') ?: $storage.'/config.php';
if (is_file($path)) exit("Настройки уже существуют. Для обновления: php tools/migrate.php\n");
if (!is_dir($storage)) mkdir($storage,0700,true);
if (!is_dir($storage.'/photos')) mkdir($storage.'/photos',0700,true);
echo "Адрес сайта (например https://hunta-zen.ru): "; $url=trim((string)fgets(STDIN));
if (!filter_var($url,FILTER_VALIDATE_URL) || !in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)) exit("Нужен корректный адрес сайта.\n");
require __DIR__.'/author_source.php';
$author=choose_author();
$config=['storage'=>$storage,'site_url'=>rtrim($url,'/'),'telegram_token'=>'','telegram_channel'=>''];
$content="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
$handle=fopen($path,'x'); if (!$handle) exit("Не удалось создать конфиг.\n"); chmod($path,0600); fwrite($handle,$content); fclose($handle);
require $root.'/app/bootstrap.php';
$db->exec(file_get_contents($root.'/database.sql'));
$db->prepare('INSERT INTO orda_users(login,password_hash) VALUES(?,?)')->execute([$author['login'],$author['password_hash']]);
chmod($storage.'/blog.sqlite',0600);
echo "Готово. Мастерская: ".$config['site_url']."/admin.php\n";

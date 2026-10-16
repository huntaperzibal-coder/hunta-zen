<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
$dest=$storage.'/backups/'.date('Ymd-His').'-'.bin2hex(random_bytes(3)); mkdir($dest,0700,true);
$db->exec('VACUUM INTO '.$db->quote($dest.'/blog.sqlite'));
$check=new PDO('sqlite:'.$dest.'/blog.sqlite');
if ($check->query('PRAGMA integrity_check')->fetchColumn()!=='ok') throw new RuntimeException('Копия базы не прошла проверку.');
mkdir($dest.'/photos',0700);
foreach (glob($storage.'/photos/*') as $photo) { if (!copy($photo,$dest.'/photos/'.basename($photo))) throw new RuntimeException('Ошибка копирования фотографии.'); chmod($dest.'/photos/'.basename($photo),0600); }
chmod($dest.'/blog.sqlite',0600);
echo "Проверенная копия базы и фотографий: $dest\nКонфиг с паролем и токеном сохраняй отдельно в защищённом месте.\n";

<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/app/bootstrap.php';
$db->beginTransaction();
try { $db->exec(file_get_contents(ROOT.'/database.sql'));
    // Сохраняем доступ установок первой версии без смены пароля.
    if (isset($config['login'],$config['password_hash']) && !(int)$db->query('SELECT COUNT(*) FROM orda_users')->fetchColumn()) {
        $db->prepare('INSERT INTO orda_users(login,password_hash) VALUES(?,?)')->execute([$config['login'],$config['password_hash']]);
    }
    $db->commit(); echo "Структура базы проверена. Записи сохранены.\n"; }
catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }

$deskConfig=ROOT.'/storage/photo-desk/config.php';
if(is_file($deskConfig)){
    require_once ROOT.'/photo-desk/app.php';
    $deskSettings=require $deskConfig;
    $deskDb=PhotoDesk\connect($deskSettings['storage']);
    PhotoDesk\schema($deskDb);
    echo "Структура кабинета фотографий проверена. Пользователи и файлы сохранены.\n";
}

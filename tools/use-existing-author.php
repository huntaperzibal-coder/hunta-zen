<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/app/bootstrap.php';
require __DIR__.'/author_source.php';
$author=choose_author();
$db->exec('VACUUM INTO '.$db->quote($storage.'/before-author-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite'));
$db->exec(file_get_contents(ROOT.'/database.sql'));
$db->beginTransaction();
try {
    $old=ordaFindUser($author['login']);
    if ($old) $db->prepare('UPDATE orda_users SET password_hash=? WHERE login=?')->execute([$author['password_hash'],$old['login']]);
    else $db->prepare('INSERT INTO orda_users(login,password_hash) VALUES(?,?)')->execute([$author['login'],$author['password_hash']]);
    audit('author:import');$db->commit();
} catch (Throwable $e) { $db->rollBack();throw $e; }
echo "Готово. Вход с твоим прежним логином и паролем. Другие сайты не изменены.\n";

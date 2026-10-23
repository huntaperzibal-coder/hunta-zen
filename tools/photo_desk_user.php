<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/photo-desk/app.php';
$config=require (getenv('PHOTO_DESK_CONFIG')?:dirname(__DIR__).'/storage/photo-desk/config.php');$db=PhotoDesk\connect($config['storage']);
$options=getopt('',['login:','role:']);$login=trim((string)($options['login']??''));$role=(string)($options['role']??'');
if(!$login || !in_array($role,['admin','photographer','lawyer'],true))exit("Использование: php tools/photo_desk_user.php --login=ЛОГИН --role=lawyer|photographer|admin\nСуществующему пользователю меняется только роль. Новому пароль вводится скрыто в терминале.\n");
$q=$db->prepare('SELECT * FROM users WHERE login=? COLLATE NOCASE');$q->execute([$login]);$old=$q->fetch();
if(!$old){
    if(!stream_isatty(STDIN))throw new RuntimeException('Для пароля нужен интерактивный терминал.');
    echo 'Пароль нового пользователя: ';system('stty -echo');try{$password=rtrim((string)fgets(STDIN),"\r\n");}finally{system('stty echo');echo "\n";}
    if($password==='')throw new RuntimeException('Пустой пароль не сохранён.');$hash=password_hash($password,PASSWORD_DEFAULT);unset($password);
}
require __DIR__.'/photo_desk_snapshot.php';$backup=PhotoDesk\snapshot($db,$config['storage']);
if($old){
    if($old['role']==='admin' && $role!=='admin' && (int)$db->query("SELECT count(*) FROM users WHERE role='admin'")->fetchColumn()===1 && !(int)$db->query("SELECT count(*) FROM users WHERE role='lawyer'")->fetchColumn())throw new RuntimeException('Сначала создай учётную запись юриста.');
    $db->prepare('UPDATE users SET role=? WHERE login=?')->execute([$role,$old['login']]);
}else $db->prepare('INSERT INTO users VALUES(?,?,?)')->execute([$login,$hash,$role]);
echo "Права сохранены: $login → $role. Пароль существующего пользователя не изменён.\n";

<?php
declare(strict_types=1);
/** Только чтение: переносит одну указанную учётную запись Орды, не меняя исходный сайт. */
function source_author(string $root,string $login): ?array {
    $rows=[]; $path=rtrim($root,'/').'/site/db/topo.db';
    if (is_readable($path)) {
        $source=new PDO('sqlite:file:'.$path.'?mode=ro',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        if ($source->query("SELECT 1 FROM sqlite_master WHERE name='orda_users'")->fetchColumn()) {
            $rows=$source->query('SELECT login,password_hash FROM orda_users')->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    if (!$rows) {
        $path=rtrim($root,'/').'/adminka/texts/users.json';
        if (is_readable($path)) $rows=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    }
    foreach ($rows as $row) {
        if (mb_strtolower((string)($row['login']??''),'UTF-8')===mb_strtolower(trim($login),'UTF-8')) {
            $hash=(string)($row['password_hash']??'');
            if (!password_get_info($hash)['algo']) throw new RuntimeException('Неизвестный формат хеша исходного пароля.');
            return ['login'=>$row['login'],'password_hash'=>$hash];
        }
    }
    return null;
}
function existing_password(): string {
    $hidden=function_exists('shell_exec') && is_file('/dev/tty');
    echo 'Твой действующий пароль от других сайтов (не новый): ';
    if ($hidden) shell_exec('stty -echo 2>/dev/null');
    try { $password=rtrim((string)fgets(STDIN),"\r\n"); }
    finally { if ($hidden) shell_exec('stty echo 2>/dev/null');echo "\n"; }
    if ($password==='') throw new RuntimeException('Пароль не введён. Изменения не сохранены.');
    return $password;
}
function choose_author(): array {
    echo 'Твой логин, как в админке других сайтов: ';$login=trim((string)fgets(STDIN));
    if ($login==='') throw new RuntimeException('Логин не введён.');
    $source=getenv('HUNTA_TOPO_ROOT') ?: '/home/a/altaikemp/topo-altai.ru/public_html';
    $author=source_author($source,$login);
    if ($author) { echo "Учётная запись скопирована из Орды. Пароль сохранён прежний.\n";return $author; }
    echo "Исходная учётная запись недоступна этому SSH-пользователю. Можно указать тот же действующий пароль локально.\n";
    return ['login'=>$login,'password_hash'=>password_hash(existing_password(),PASSWORD_DEFAULT)];
}

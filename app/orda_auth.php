<?php
declare(strict_types=1);
/** Адаптация функций входа из altai-topo/adminka/tools/orda_guard.php и orda_auth_store.php.
 * Те же orda_users, user_login/user_id и отпечаток password_hash в сессии.
 * Хранилище и пути принадлежат только новому сайту.
 */
function ordaFindUser(string $login): ?array {
    global $db;
    $needle=mb_strtolower(trim($login),'UTF-8');
    foreach ($db->query('SELECT login,password_hash FROM orda_users') as $user) {
        if ($needle!=='' && mb_strtolower($user['login'],'UTF-8')===$needle) return $user;
    }
    return null;
}
function ordaGuardPasswordFingerprint(string $passwordHash): string {
    return substr(hash('sha256',$passwordHash),0,24);
}
function ordaLoginSession(string $login,string $passwordHash): void {
    session_open(); session_regenerate_id(true);
    $_SESSION['user_id']=$login;
    $_SESSION['user_login']=$login;
    $_SESSION['orda_pw_fp']=ordaGuardPasswordFingerprint($passwordHash);
    $_SESSION['orda_session_version']=(int)(blog_access($login)['session_version']??1);
}
function ordaThrottleBlockedSeconds(string $scope,string $key,int $max=5,int $window=900): int {
    global $db;
    $db->prepare('DELETE FROM orda_auth_attempts WHERE created_at < ?')->execute([time()-86400]);
    $q=$db->prepare('SELECT COUNT(*),MIN(created_at) FROM orda_auth_attempts WHERE scope=? AND key_hash=? AND created_at>?');
    $q->execute([$scope,hash('sha256',$key),time()-$window]);
    [$count,$oldest]=$q->fetch(PDO::FETCH_NUM);
    return (int)$count<$max?0:max(1,(int)$oldest+$window-time());
}
function ordaThrottleFail(string $scope,string $key): void {
    global $db; $db->prepare('INSERT INTO orda_auth_attempts(scope,key_hash,created_at) VALUES(?,?,?)')->execute([$scope,hash('sha256',$key),time()]);
}
function ordaThrottleClear(string $scope,string $key): void {
    global $db; $db->prepare('DELETE FROM orda_auth_attempts WHERE scope=? AND key_hash=?')->execute([$scope,hash('sha256',$key)]);
}

function blog_accounts(): \HuntaAccess\Accounts {
    global $db,$storage;
    return new \HuntaAccess\Accounts($db,'blog',static fn()=>database_backup_create($storage.'/access-backups'));
}
function blog_access(string $login): ?array { return blog_accounts()->find($login); }
function blog_owner(): bool {
    $account=blog_access((string)($_SESSION['user_login']??''));
    return $account!==null && blog_accounts()->manager($account);
}

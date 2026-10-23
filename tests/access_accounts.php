<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/access_accounts.php';
require dirname(__DIR__).'/photo-desk/app.php';
function ok(bool $condition,string $why):void{if(!$condition)throw new RuntimeException($why);}
function deny(callable $fn):void{try{$fn();}catch(RuntimeException $e){return;}throw new RuntimeException('Expected denial');}
foreach(['blog','desk'] as $scope){
    $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
    $hash=password_hash('old',PASSWORD_DEFAULT);
    $managerRole=$scope==='blog'?'owner':'admin';$memberRole=$scope==='blog'?'admin':'lawyer';$table=$scope==='blog'?'orda_users':'users';$meta=$scope==='blog'?'orda_user_access':'user_access';
    if($scope==='blog'){
        $db->exec(file_get_contents(dirname(__DIR__).'/database.sql'));
        $db->prepare('INSERT INTO orda_users(login,password_hash) VALUES(?,?)')->execute(['Hunta',$hash]);
        $db->exec(file_get_contents(dirname(__DIR__).'/database.sql'));
    }else{
        PhotoDesk\schema($db);$db->prepare('INSERT INTO users VALUES(?,?,?)')->execute(['Hunta',$hash,'admin']);PhotoDesk\schema($db);
    }
    $snapshots=0;$accounts=new HuntaAccess\Accounts($db,$scope,static function()use(&$snapshots){$snapshots++;});
    ok($accounts->find('hUNta')['password_hash']===$hash,'existing hash preserved');
    ok($accounts->manager($accounts->find('hunta')),'legacy owner');
    $accounts->mutate('Hunta','access_create',['login'=>'assistant','role'=>$memberRole,'active'=>1,'new_password'=>'member','password_confirm'=>'member']);
    ok(!$accounts->manager($accounts->find('assistant')),'member cannot manage');
    deny(fn()=>$accounts->mutate('assistant','access_create',['login'=>'intruder','role'=>$managerRole,'active'=>1,'new_password'=>'x','password_confirm'=>'x']));
    deny(fn()=>$accounts->mutate('Hunta','access_create',['login'=>'ASSISTANT','role'=>$memberRole,'active'=>1,'new_password'=>'x','password_confirm'=>'x']));
    $member=$accounts->find('assistant');
    $accounts->mutate('Hunta','access_update',['login'=>'assistant','role'=>$memberRole,'revision'=>$member['revision']]);
    ok(!$accounts->find('assistant')['active'],'blocked');
    ok($accounts->find('assistant')['session_version']>$member['session_version'],'sessions revoked');
    deny(fn()=>$accounts->mutate('Hunta','access_update',['login'=>'assistant','role'=>$managerRole,'revision'=>$member['revision'],'active'=>1]));
    $member=$accounts->find('assistant');
    $accounts->mutate('Hunta','access_update',['login'=>'assistant','role'=>$memberRole,'revision'=>$member['revision'],'active'=>1,'new_password'=>'reset','password_confirm'=>'reset']);
    ok(password_verify('reset',$accounts->find('assistant')['password_hash']),'owner reset');
    $owner=$accounts->find('Hunta');
    deny(fn()=>$accounts->mutate('Hunta','access_update',['login'=>'Hunta','role'=>$memberRole,'revision'=>$owner['revision']]));
    deny(fn()=>$accounts->mutate('Hunta','access_password',['revision'=>$owner['revision'],'current_password'=>'bad','new_password'=>'new','password_confirm'=>'new']));
    ok($accounts->mutate('Hunta','access_password',['revision'=>$owner['revision'],'current_password'=>'old','new_password'=>'new','password_confirm'=>'new']),'own change logs out');
    ok(password_verify('new',$accounts->find('Hunta')['password_hash']),'own new password');
    ok($accounts->mutate('assistant','access_revoke_self',['revision'=>$accounts->find('assistant')['revision']]),'self revoke logs out');
    ob_start();HuntaAccess\render($accounts,'Hunta','CSRF');$html=ob_get_clean();ok(!str_contains($html,$hash)&&!str_contains($html,'value="reset"'),'no passwords in HTML');
    ob_start();HuntaAccess\render($accounts,'assistant','CSRF');$html=ob_get_clean();ok(!str_contains($html,'access_create'),'member only sees own controls');
    ok($snapshots>=5,'backup before mutation');
    echo "Access accounts $scope checks passed\n";
}

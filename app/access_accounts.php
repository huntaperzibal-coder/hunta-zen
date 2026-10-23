<?php
declare(strict_types=1);
namespace HuntaAccess;

final class Accounts {
    private string $users;
    private string $access;
    public function __construct(private \PDO $db,public string $scope,private $snapshot) {
        if(!in_array($scope,['blog','desk'],true))throw new \InvalidArgumentException('Unknown account scope');
        $this->users=$scope==='blog'?'orda_users':'users';$this->access=$scope==='blog'?'orda_user_access':'user_access';
    }
    public function roles(): array {return $this->scope==='blog'?['owner'=>'Владелец','admin'=>'Администратор блога']:['admin'=>'Владелец кабинета','photographer'=>'Фотограф','lawyer'=>'Юрист'];}
    public function manager(array $user): bool {return (bool)$user['active'] && $user['role']===($this->scope==='blog'?'owner':'admin');}
    public function installed(): bool {$q=$this->db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$this->access]);return (bool)$q->fetchColumn();}
    public function all(): array {
        $rows=$this->db->query('SELECT login,password_hash'.($this->scope==='desk'?',role':'').' FROM '.$this->users.' ORDER BY login')->fetchAll(\PDO::FETCH_ASSOC);
        $meta=[];if($this->installed())foreach($this->db->query('SELECT * FROM '.$this->access) as $row)$meta[$row['login']]=$row;
        foreach($rows as &$row){$baseRole=$row['role']??'owner';$row=array_replace(['role'=>$this->scope==='blog'?'owner':$row['role'],'active'=>1,'revision'=>0,'session_version'=>1],$row,$meta[$row['login']]??[]);if($this->scope==='desk')$row['role']=$baseRole;}
        unset($row);return $rows;
    }
    public function find(string $login): ?array {foreach($this->all() as $row)if(mb_strtolower($row['login'],'UTF-8')===mb_strtolower(trim($login),'UTF-8'))return $row;return null;}
    private function password(array $input,bool $required): string {
        $password=(string)($input['new_password']??'');
        if($required && $password==='')throw new \RuntimeException('Введи пароль.');
        if($password!=='' && $password!==(string)($input['password_confirm']??''))throw new \RuntimeException('Пароли не совпадают.');
        if(strlen($password)>72)throw new \RuntimeException('Новый пароль должен занимать не более 72 байт.');
        if(str_contains($password,chr(0)))throw new \RuntimeException('В пароле есть недопустимый символ.');
        if($password!==trim($password))throw new \RuntimeException('Убери пробелы в начале и конце нового пароля.');
        return $password;
    }
    public function mutate(string $actorLogin,string $action,array $input): bool {
        if(!$this->installed())throw new \RuntimeException('Обнови структуру базы командой ~/zen-sync.sh.');
        $actor=$this->find($actorLogin);if(!$actor || !$actor['active'])throw new \RuntimeException('Вход недоступен.');
        if(!in_array($action,['access_create','access_update','access_password','access_revoke_self'],true))throw new \RuntimeException('Неизвестное действие.');
        $own=in_array($action,['access_password','access_revoke_self'],true);
        if(!$own && !$this->manager($actor))throw new \RuntimeException('Управлять пользователями может только владелец.');
        $password=$action==='access_revoke_self'?'':$this->password($input,$action==='access_password'||$action==='access_create');
        if($action==='access_password' && !password_verify((string)($input['current_password']??''),$actor['password_hash']))throw new \RuntimeException('Текущий пароль неверен.');
        $login=$own?$actor['login']:trim((string)($input['login']??''));
        if(!preg_match('/^[\p{L}\p{N}_.@-]{1,80}$/u',$login))throw new \RuntimeException('Логин: до 80 букв, цифр или знаков . _ @ - без пробелов.');
        $role=$own?$actor['role']:(string)($input['role']??'');
        if(!array_key_exists($role,$this->roles()))throw new \RuntimeException('Выбери роль пользователя.');
        $active=$own?1:(isset($input['active'])?1:0);
        // Consistent checked backup happens before any credentials or permissions are changed.
        ($this->snapshot)();
        $this->db->beginTransaction();
        try{
            $actor=$this->find($actorLogin);
            if(!$actor || !$actor['active'] || (!$own && !$this->manager($actor)))throw new \RuntimeException('Права доступа изменились. Войди заново.');
            $target=$this->find($login);
            if($action==='access_create'){
                if($target)throw new \RuntimeException('Этот логин уже используется.');
                $hash=password_hash($password,PASSWORD_DEFAULT);
                $sql='INSERT INTO '.$this->users.'(login,password_hash'.($this->scope==='desk'?',role':'').') VALUES(?,?'.($this->scope==='desk'?',?':'').')';
                $args=[$login,$hash];if($this->scope==='desk')$args[]=$role;$this->db->prepare($sql)->execute($args);
                $this->db->prepare('INSERT INTO '.$this->access.'(login,role,active,revision,session_version) VALUES(?,?,?,1,1)')->execute([$login,$role,$active]);
            }else{
                if(!$target)throw new \RuntimeException('Пользователь не найден.');$login=$target['login'];
                if((int)$target['revision']!==(int)($input['revision']??-1))throw new \RuntimeException('Пользователь уже изменён в другом окне. Обнови страницу.');
                if($action==='access_password' && !password_verify((string)($input['current_password']??''),$target['password_hash']))throw new \RuntimeException('Пароль уже изменён. Войди заново.');
                if(!$own && $login===$actor['login'])throw new \RuntimeException('Свой пароль меняй в разделе «Мой пароль». Свой доступ здесь отключить нельзя.');
                if($this->manager($target) && (!$active || $role!==$target['role'])){
                    $managers=count(array_filter($this->all(),fn($u)=>$this->manager($u)));
                    if($managers<=1)throw new \RuntimeException('Нельзя отключить последнего владельца.');
                }
                if($password!=='')$this->db->prepare('UPDATE '.$this->users.' SET password_hash=? WHERE login=?')->execute([password_hash($password,PASSWORD_DEFAULT),$login]);
                if($this->scope==='desk')$this->db->prepare('UPDATE users SET role=? WHERE login=?')->execute([$role,$login]);
                $this->db->prepare('INSERT INTO '.$this->access.'(login,role,active,revision,session_version) VALUES(?,?,?,?,?) ON CONFLICT(login) DO UPDATE SET role=excluded.role,active=excluded.active,revision=excluded.revision,session_version=excluded.session_version')->execute([$login,$role,$active,(int)$target['revision']+1,(int)$target['session_version']+1]);
            }
            $this->db->exec('CREATE TABLE IF NOT EXISTS account_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor TEXT NOT NULL,target TEXT NOT NULL,action TEXT NOT NULL,created_at TEXT NOT NULL)');
            $this->db->prepare('INSERT INTO account_events(actor,target,action,created_at) VALUES(?,?,?,?)')->execute([$actor['login'],$login,$action,date('c')]);
            $this->db->commit();return $own;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }
}
function database_snapshot(\PDO $db,string $directory): string {
    if(!is_dir($directory) && !mkdir($directory,0700,true))throw new \RuntimeException('Не удалось создать каталог копий доступа.');
    $temp=tempnam($directory,'.access-');if($temp===false)throw new \RuntimeException('Не удалось создать копию доступа.');
    try{
        chmod($temp,0600);$db->exec('VACUUM INTO '.$db->quote($temp));
        $check=new \PDO('sqlite:'.$temp,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        if($check->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new \RuntimeException('Копия доступа не прошла проверку.');
        $check=null;$path=$directory.'/accounts-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
        if(!rename($temp,$path))throw new \RuntimeException('Не удалось завершить копию доступа.');return $path;
    }finally{if(is_file($temp))unlink($temp);}
}
function e(mixed $s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function password_fields(bool $current=false): void {
    if($current)echo '<label>Текущий пароль<input type="password" name="current_password" autocomplete="current-password" required></label>';
    echo '<div class="access-fields"><label>Новый пароль<input type="password" name="new_password" autocomplete="new-password" '.($current?'required':'').'></label><label>Повтори пароль<input type="password" name="password_confirm" autocomplete="new-password" '.($current?'required':'').'></label></div>';
}
function role_options(Accounts $accounts,string $selected): string {$html='';foreach($accounts->roles() as $key=>$label)$html.='<option value="'.e($key).'" '.($key===$selected?'selected':'').'>'.e($label).'</option>';return $html;}
function render(Accounts $accounts,string $actorLogin,string $csrf): void {
    $actor=$accounts->find($actorLogin);if(!$actor)return;
    $manager=$accounts->manager($actor);
    echo '<section class="access-ui"><h1>'.($manager?'Пользователи и пароли':'Мой пароль').'</h1><p class="access-hint">'.($accounts->scope==='blog'?'Доступ только к админке блога. Пользователи кабинета фотографий настраиваются отдельно.':'Доступ только к кабинету фотографий. Эти учётные записи не открывают админку блога.').'</p><p>Ваш логин: <strong>'.e($actor['login']).'</strong>. С другого компьютера откройте этот же адрес и введите свой логин и пароль.</p>';
    if(!$accounts->installed()){echo '<p role="alert">Сначала обнови базу: ~/zen-sync.sh.</p></section>';return;}
    if($manager){
        echo '<details class="access-add"><summary>＋ Добавить пользователя</summary><form method="post">'.$csrf.'<input type="hidden" name="action" value="access_create"><div class="access-fields"><label>Логин<input name="login" maxlength="80" autocomplete="off" required></label><label>Роль<select name="role">'.role_options($accounts,$accounts->scope==='blog'?'admin':'lawyer').'</select></label></div>';
        password_fields();echo '<label class="access-check"><input type="checkbox" name="active" checked> Вход разрешён</label><button class="primary">Добавить пользователя</button></form></details>';
        echo '<div class="access-table-wrap"><table class="access-table"><thead><tr><th>Логин</th><th>Роль</th><th>Вход</th><th>Управление</th></tr></thead><tbody>';
        foreach($accounts->all() as $row){
            echo '<tr><td><strong>'.e($row['login']).'</strong></td><td>'.e($accounts->roles()[$row['role']]??$row['role']).'</td><td>'.($row['active']?'Разрешён':'Заблокирован').'</td><td>';
            if($row['login']===$actor['login'])echo 'Это вы · <a href="#my-password">Мой пароль ↓</a>';
            else{
                echo '<details><summary>Изменить доступ / пароль</summary><form method="post">'.$csrf.'<input type="hidden" name="action" value="access_update"><input type="hidden" name="login" value="'.e($row['login']).'"><input type="hidden" name="revision" value="'.(int)$row['revision'].'"><label>Роль<select name="role">'.role_options($accounts,$row['role']).'</select></label><label class="access-check"><input type="checkbox" name="active" '.($row['active']?'checked':'').'> Вход разрешён</label>';
                password_fields();echo '<p class="access-hint">Оставь пароль пустым, чтобы сохранить прежний. Сохранение завершит все сеансы этого пользователя.</p><button>Сохранить доступ</button></form><form method="post">'.$csrf.'<input type="hidden" name="action" value="access_update"><input type="hidden" name="login" value="'.e($row['login']).'"><input type="hidden" name="revision" value="'.(int)$row['revision'].'"><input type="hidden" name="role" value="'.e($row['role']).'">'.($row['active']?'<input type="hidden" name="active" value="1">':'').'<button>Завершить сеансы</button></form></details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><p class="access-hint">Владелец управляет пользователями. '.($accounts->scope==='blog'?'Администратор блога работает с записями и настройками, но не меняет чужой доступ.':'Фотограф отвечает на запросы; юрист создаёт запросы и закрывает позиции.').'</p>';
    }
    echo '<details class="access-own" id="my-password"><summary>Мой пароль</summary><form method="post">'.$csrf.'<input type="hidden" name="action" value="access_password"><input type="hidden" name="revision" value="'.(int)$actor['revision'].'">';password_fields(true);echo '<p class="access-hint">После смены пароля нужно войти заново на всех устройствах.</p><button class="primary">Сменить мой пароль</button></form><form method="post">'.$csrf.'<input type="hidden" name="action" value="access_revoke_self"><input type="hidden" name="revision" value="'.(int)$actor['revision'].'"><button>Выйти на всех устройствах</button></form></details></section>';
}

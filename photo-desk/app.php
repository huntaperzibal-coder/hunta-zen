<?php
declare(strict_types=1);
namespace PhotoDesk;

function esc(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function connect(string $storage): \PDO {
    $db=new \PDO('sqlite:'.$storage.'/desk.sqlite',null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000'); return $db;
}
function schema(\PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS users(login TEXT PRIMARY KEY,password_hash TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('admin','photographer','lawyer')));
    CREATE TABLE IF NOT EXISTS cases(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT NOT NULL,company TEXT NOT NULL DEFAULT '',request_note TEXT NOT NULL DEFAULT '',response_note TEXT NOT NULL DEFAULT '',source_url TEXT NOT NULL DEFAULT '',source_title TEXT NOT NULL DEFAULT '',preview_key TEXT,ready INTEGER NOT NULL DEFAULT 0,closed INTEGER NOT NULL DEFAULT 0,revision INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL,updated_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS attachments(id INTEGER PRIMARY KEY AUTOINCREMENT,case_id INTEGER NOT NULL REFERENCES cases(id),side TEXT NOT NULL CHECK(side IN ('request','response')),name TEXT NOT NULL,mime TEXT NOT NULL,size INTEGER NOT NULL,storage_key TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS events(id INTEGER PRIMARY KEY AUTOINCREMENT,case_id INTEGER NOT NULL REFERENCES cases(id),actor TEXT NOT NULL,action TEXT NOT NULL,created_at TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS attempts(key_hash TEXT NOT NULL,created_at INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS attachments_case ON attachments(case_id);
    CREATE INDEX IF NOT EXISTS attempts_time ON attempts(created_at);");
}
function record(\PDO $db,int $id): array {
    $q=$db->prepare('SELECT * FROM cases WHERE id=?');$q->execute([$id]);$row=$q->fetch();
    if (!$row) throw new \RuntimeException('Позиция не найдена.'); return $row;
}
function permitted(string $role,string $side): bool { return $role==='admin' || ($side==='request' && $role==='lawyer') || ($side==='response' && $role==='photographer'); }
function event(\PDO $db,int $id,string $actor,string $action): void { $db->prepare('INSERT INTO events(case_id,actor,action,created_at) VALUES(?,?,?,?)')->execute([$id,$actor,$action,date('c')]); }
function source_url(string $url): string {
    $url=trim($url);if ($url==='')return '';
    $p=parse_url($url);
    if (strlen($url)>3000 || !$p || !in_array($p['scheme']??'', ['http','https'],true) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || preg_match('/[\x00-\x20]/',$url)) throw new \RuntimeException('Укажи полную ссылку http:// или https:// без пробелов.');
    return $url;
}
function save(\PDO $db,?int $id,int $revision,array $input,string $role,string $actor,array $files=[],?int $delete=null): int {
    $new=$id===null;
    if ($new && !permitted($role,'request')) throw new \RuntimeException('Новый запрос создаёт юрист.');
    foreach (['title'=>200,'company'=>300,'request_note'=>20000,'response_note'=>20000] as $field=>$limit) if (isset($input[$field]) && mb_strlen((string)$input[$field])>$limit) throw new \RuntimeException('Слишком длинное поле: '.$field);
    $db->beginTransaction();
    try {
        $old=$new?['ready'=>0,'closed'=>0]:record($db,(int)$id);
        if (!$new && (int)$old['revision']!==$revision) throw new \RuntimeException('Позицию уже изменили в другом окне. Обнови страницу: твои изменения не записаны.');
        $values=$old;
        if (permitted($role,'request')) {
            foreach(['title','company','request_note'] as $key)$values[$key]=trim((string)($input[$key]??($old[$key]??'')));
            if ($values['title']==='')throw new \RuntimeException('Добавь название запроса.');
            $values['source_url']=source_url((string)($input['source_url']??($old['source_url']??'')));
        }
        if (permitted($role,'response')) $values['response_note']=trim((string)($input['response_note']??($old['response_note']??'')));
        if (array_key_exists('ready',$input)) {
            if(!permitted($role,'response'))throw new \RuntimeException('Галочка фотографа недоступна.');
            $values['ready']=(int)(bool)$input['ready'];
        }
        if (array_key_exists('closed',$input)) {
            if(!permitted($role,'request'))throw new \RuntimeException('Закрывать позицию может юрист.');
            $values['closed']=(int)(bool)$input['closed'];
        }
        $stamp=date('c');
        if($new) {
            $db->prepare('INSERT INTO cases(title,company,request_note,response_note,source_url,ready,closed,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$values['title'],$values['company'],$values['request_note'],$values['response_note']??'',$values['source_url'],$values['ready'],$values['closed'],$stamp,$stamp]);$id=(int)$db->lastInsertId();
        } else {
            $db->prepare('UPDATE cases SET title=?,company=?,request_note=?,response_note=?,source_url=?,ready=?,closed=?,revision=revision+1,updated_at=? WHERE id=?')->execute([$values['title'],$values['company'],$values['request_note'],$values['response_note'],$values['source_url'],$values['ready'],$values['closed'],$stamp,$id]);
            if($old['source_url']!==$values['source_url'])$db->prepare("UPDATE cases SET source_title='',preview_key=NULL WHERE id=?")->execute([$id]);
        }
        foreach($files as $file){
            if(!permitted($role,$file['side']))throw new \RuntimeException('Нет прав добавлять вложения с этой стороны.');
            $db->prepare('INSERT INTO attachments(case_id,side,name,mime,size,storage_key,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,$file['side'],$file['name'],$file['mime'],$file['size'],$file['storage_key'],$stamp]);
        }
        if($delete!==null){
            $q=$db->prepare('SELECT side FROM attachments WHERE id=? AND case_id=?');$q->execute([$delete,$id]);$side=$q->fetchColumn();
            if(!$side || !permitted($role,(string)$side))throw new \RuntimeException('Вложение недоступно.');
            // Keep bytes for recovery; remove only the visible association.
            $db->prepare('DELETE FROM attachments WHERE id=? AND case_id=?')->execute([$delete,$id]);
        }
        event($db,$id,$actor,$new?'Запрос создан':'Позиция сохранена'.($old['ready']!==$values['ready']?' · Ответ '.($values['ready']?'готов':'не готов'):'').($old['closed']!==$values['closed']?' · '.($values['closed']?'Закрыта':'Открыта'):''));
        $db->commit();return $id;
    }catch(\Throwable $e){$db->rollBack();throw $e;}
}
function upload(array $group,string $side,string $storage): array {
    if(!isset($group['error']) || !is_array($group['error']))return [];
    if(count($group['error'])>12)throw new \RuntimeException('За один раз можно добавить до 12 файлов.');
    $result=[];
    $allowed=['image/jpeg','image/png','image/webp','image/gif','image/tiff','application/pdf','application/zip','application/x-zip-compressed','application/octet-stream','text/plain','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    try{foreach($group['error'] as $i=>$error){
        if($error===UPLOAD_ERR_NO_FILE)continue;
        if($error!==UPLOAD_ERR_OK)throw new \RuntimeException('Файл не загружен. Проверь размер файла и лимиты PHP.');
        $tmp=$group['tmp_name'][$i];$size=filesize($tmp);
        if(!$size || $size>50*1024*1024 || !is_uploaded_file($tmp))throw new \RuntimeException('Максимум 50 МБ на файл; пустые файлы не принимаются.');
        $name=mb_substr(basename(str_replace('\\','/',$group['name'][$i])),0,200);
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if(!in_array($ext,['jpg','jpeg','png','webp','gif','tif','tiff','pdf','zip','txt','doc','docx','raw','cr2','cr3','nef','arw','dng'],true) || !in_array($mime,$allowed,true))throw new \RuntimeException('Разрешены фотографии, RAW, PDF, DOC/DOCX, TXT и ZIP.');
        if(str_starts_with($mime,'image/') && !@getimagesize($tmp))throw new \RuntimeException('Не удалось прочитать изображение.');
        $key=bin2hex(random_bytes(24));
        if(!move_uploaded_file($tmp,$storage.'/files/'.$key))throw new \RuntimeException('Не удалось сохранить файл.');chmod($storage.'/files/'.$key,0600);
        $result[]=['side'=>$side,'name'=>$name,'mime'=>$mime,'size'=>$size,'storage_key'=>$key];
    }return $result;}catch(\Throwable $e){foreach($result as $f)@unlink($storage.'/files/'.$f['storage_key']);throw $e;}
}
// Network previews use public HTTPS only, pinned DNS, no redirects, strict byte/time limits.
function public_fetch(string $url,int $limit): array {
    source_url($url);$p=parse_url($url);
    if(($p['scheme']??'')!=='https' || isset($p['port']) && $p['port']!==443)throw new \RuntimeException('Для автоматического превью нужна HTTPS-ссылка.');
    $host=$p['host'];if(!preg_match('/^[a-z0-9.-]+$/i',$host))throw new \RuntimeException('Не удалось получить превью.');
    $ips=gethostbynamel($host);if(!$ips)throw new \RuntimeException('Сайт источника недоступен.');
    foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new \RuntimeException('Внутренние сетевые адреса запрещены.');
    $body='';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_USERAGENT=>'PhotoDesk/1.0',CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body,$limit){if(strlen($body)+strlen($part)>$limit)return 0;$body.=$part;return strlen($part);}]);
    $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
    if(!$ok || $status!==200)throw new \RuntimeException('Источник не отдаёт превью. Прикрепи изображение вручную.');return [$body,$type];
}
function preview(string $url,string $storage): array {
    [$html,$type]=public_fetch($url,1500000);
    if(!str_contains($type,'text/html'))throw new \RuntimeException('Ссылка не ведёт на HTML-страницу.');
    $dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$title='';$image='';
    foreach($dom->getElementsByTagName('meta') as $meta){$property=strtolower($meta->getAttribute('property')?:$meta->getAttribute('name'));if($property==='og:title')$title=$meta->getAttribute('content');if($property==='og:image')$image=$meta->getAttribute('content');}
    if(!$title)$title=$dom->getElementsByTagName('title')->item(0)?->textContent??parse_url($url,PHP_URL_HOST);
    $key=null;
    if($image){
        if(str_starts_with($image,'//'))$image='https:'.$image;
        if(str_starts_with($image,'/'))$image='https://'.parse_url($url,PHP_URL_HOST).$image;
        try{[$bytes,$mime]=public_fetch($image,8000000);$info=@getimagesizefromstring($bytes);
            if($info && in_array($info['mime'],['image/jpeg','image/png','image/webp','image/gif'],true)){$key=bin2hex(random_bytes(24));file_put_contents($storage.'/files/'.$key,$bytes);chmod($storage.'/files/'.$key,0600);}
        }catch(\Throwable $e){/* The title and link still work; manual upload is available. */}
    }
    return ['title'=>mb_substr(trim($title),0,300),'key'=>$key];
}

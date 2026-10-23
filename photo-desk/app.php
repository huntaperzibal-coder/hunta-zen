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
    CREATE TABLE IF NOT EXISTS user_access(login TEXT PRIMARY KEY REFERENCES users(login),role TEXT NOT NULL,active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),session_version INTEGER NOT NULL DEFAULT 1,revision INTEGER NOT NULL DEFAULT 1);
    INSERT OR IGNORE INTO user_access(login,role) SELECT login,role FROM users;
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
        $raw=in_array($ext,['raw','raf','cr2','cr3','nef','arw','dng'],true);
        if(!in_array($ext,['jpg','jpeg','png','webp','gif','tif','tiff','pdf','zip','txt','doc','docx','raw','raf','cr2','cr3','nef','arw','dng'],true))throw new \RuntimeException('Разрешены фотографии, RAW (включая Fujifilm RAF), PDF, DOC/DOCX, TXT и ZIP.');
        if($raw){
            $rawMimes=['application/octet-stream','image/tiff','image/x-tiff','image/x-fuji-raf','image/x-fujifilm-raf','image/x-canon-cr2','image/x-canon-cr3','image/x-nikon-nef','image/x-sony-arw','image/x-adobe-dng','image/x-raw','image/x-dcraw'];
            if($ext==='raf'){
                $handle=fopen($tmp,'rb');$signature=$handle?fread($handle,16):'';if($handle)fclose($handle);
                if($signature!=='FUJIFILMCCD-RAW ')throw new \RuntimeException('Файл .RAF не содержит заголовок Fujifilm RAW.');
            }elseif(!in_array($mime,$rawMimes,true))throw new \RuntimeException('Не удалось определить формат RAW-файла.');
            // Camera RAW is kept as evidence, never decoded as a web image or recompressed.
            $mime='application/octet-stream';
        }else{
            if(!in_array($mime,$allowed,true))throw new \RuntimeException('Не удалось определить разрешённый формат файла.');
            if(str_starts_with($mime,'image/') && !@getimagesize($tmp))throw new \RuntimeException('Не удалось прочитать изображение.');
        }
        $key=bin2hex(random_bytes(24));
        if(!move_uploaded_file($tmp,$storage.'/files/'.$key))throw new \RuntimeException('Не удалось сохранить файл.');chmod($storage.'/files/'.$key,0600);
        $result[]=['side'=>$side,'name'=>$name,'mime'=>$mime,'size'=>$size,'storage_key'=>$key];
    }return $result;}catch(\Throwable $e){foreach($result as $f)@unlink($storage.'/files/'.$f['storage_key']);throw $e;}
}
// Every redirect is independently validated and pinned to public DNS addresses.
function resolve_url(string $base,string $link): string {
    $link=trim(html_entity_decode($link,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if(preg_match('~^https?://~i',$link))return $link;
    $baseParts=parse_url($base);
    if(str_starts_with($link,'//'))return 'https:'.$link;
    $origin='https://'.$baseParts['host'];
    if(str_starts_with($link,'/'))return $origin.$link;
    if(str_starts_with($link,'?'))return $origin.($baseParts['path']??'/').$link;
    $path=dirname($baseParts['path']??'/').'/'.$link;$parts=[];
    foreach(explode('/',$path) as $part){if($part==='..')array_pop($parts);elseif($part!=='' && $part!=='.')$parts[]=$part;}
    return $origin.'/'.implode('/',$parts);
}
function public_fetch(string $url,int $limit): array {
    $deadline=microtime(true)+12;
    for($hop=0;$hop<4;$hop++){
        source_url($url);$p=parse_url($url);
        if(($p['scheme']??'')!=='https' || isset($p['port']) && $p['port']!==443)throw new \RuntimeException('Для автоматического превью нужна HTTPS-ссылка.');
        $host=$p['host'];if(!preg_match('/^[a-z0-9.-]+$/i',$host))throw new \RuntimeException('Не удалось получить превью.');
        $ips=gethostbynamel($host);if(!$ips)throw new \RuntimeException('Не удалось определить адрес источника.');
        foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new \RuntimeException('Внутренние сетевые адреса запрещены.');
        $remaining=(int)ceil($deadline-microtime(true));if($remaining<1)throw new \RuntimeException('Источник слишком долго отвечает.');
        $body='';$location='';$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_CONNECTTIMEOUT=>min(4,$remaining),CURLOPT_TIMEOUT=>$remaining,CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; PhotoDesk/1.1)',CURLOPT_HTTPHEADER=>['Accept: text/html,image/avif,image/webp,image/*;q=0.9,*/*;q=0.8','Accept-Language: ru,en;q=0.8'],CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$location){if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},CURLOPT_WRITEFUNCTION=>static function($ch,$part)use(&$body,$limit){if(strlen($body)+strlen($part)>$limit)return 0;$body.=$part;return strlen($part);}]);
        $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
        if(in_array($status,[301,302,303,307,308],true) && $location!==''){$url=resolve_url($url,$location);continue;}
        if(!$ok || $status!==200)throw new \RuntimeException($status?'Источник вернул HTTP '.$status.'. Можно вставить картинку из буфера или загрузить файл.':'Не удалось связаться с источником. Можно вставить картинку из буфера или загрузить файл.');
        return [$body,$type,$url];
    }
    throw new \RuntimeException('Слишком много перенаправлений у источника.');
}
function cache_preview_image(string $bytes,string $storage): string {
    $info=@getimagesizefromstring($bytes);
    if(!$info || !in_array($info['mime'],['image/jpeg','image/png','image/webp','image/gif'],true))throw new \RuntimeException('По этой ссылке нет изображения JPG, PNG, WebP или GIF.');
    $key=bin2hex(random_bytes(24));
    if(file_put_contents($storage.'/files/'.$key,$bytes)!==strlen($bytes))throw new \RuntimeException('Не удалось сохранить превью.');
    chmod($storage.'/files/'.$key,0600);return $key;
}
function preview_metadata(string $html,string $url): array {
    $dom=new \DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$title='';$image='';
    foreach($dom->getElementsByTagName('meta') as $meta){
        $property=strtolower($meta->getAttribute('property')?:($meta->getAttribute('name')?:$meta->getAttribute('itemprop')));
        if($property==='og:title')$title=$meta->getAttribute('content');
        if(in_array($property,['og:image','og:image:url','og:image:secure_url','twitter:image','twitter:image:src','image'],true) && !$image)$image=$meta->getAttribute('content');
    }
    if(!$title)$title=$dom->getElementsByTagName('title')->item(0)?->textContent??parse_url($url,PHP_URL_HOST);
    if(!$image)foreach($dom->getElementsByTagName('link') as $link)if(strtolower($link->getAttribute('rel'))==='image_src'){$image=$link->getAttribute('href');break;}
    return ['title'=>mb_substr(trim($title),0,300),'image'=>$image?resolve_url($url,$image):''];
}
function preview(string $url,string $storage,bool $directImage=false): array {
    [$body,$type,$finalUrl]=public_fetch($url,8000000);
    if($directImage || str_starts_with($type,'image/'))return ['title'=>'','key'=>cache_preview_image($body,$storage)];
    if(!str_contains($type,'text/html'))throw new \RuntimeException('Ссылка не ведёт на страницу или картинку.');
    $meta=preview_metadata($body,$finalUrl);$key=null;
    if($meta['image']){
        try{[$bytes]=public_fetch($meta['image'],8000000);$key=cache_preview_image($bytes,$storage);}catch(\Throwable $e){/* A manual image or clipboard paste remains available. */}
    }
    return ['title'=>$meta['title'],'key'=>$key];
}

/** Extract Fujifilm's embedded JPEG, never decode or modify the sensor data.
 * RAF directory: big-endian JPEG offset/length at byte 84 (LibRaw identify.cpp).
 */
function raf_preview(array $file,string $storage): ?string {
    if(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='raf')return null;
    $source=$storage.'/files/'.$file['storage_key'];
    $cache=$storage.'/thumbs/'.basename($file['storage_key']).'.raf-v1.jpg';
    if(is_file($cache))return $cache;
    $handle=@fopen($source,'rb');if(!$handle)return null;
    try{
        $header=fread($handle,92);$size=fstat($handle)['size'];
        if(strlen($header)!==92 || substr($header,0,16)!=='FUJIFILMCCD-RAW ')return null;
        $dir=unpack('Noffset/Nlength',substr($header,84,8));
        if($dir['offset']<92 || $dir['length']<4 || $dir['length']>16000000 || $dir['offset']>$size || $dir['length']>$size-$dir['offset'])return null;
        if(fseek($handle,$dir['offset'])!==0)return null;
        $jpeg=stream_get_contents($handle,$dir['length']);
        if(strlen($jpeg)!==$dir['length'] || !str_starts_with($jpeg,"\xff\xd8"))return null;
        $info=@getimagesizefromstring($jpeg);
        if(!$info || $info[2]!==IMAGETYPE_JPEG || $info[0]*$info[1]>20000000)return null;
        if(!is_dir($storage.'/thumbs') && !@mkdir($storage.'/thumbs',0700,true) && !is_dir($storage.'/thumbs'))return null;
        $temp=$cache.'.'.bin2hex(random_bytes(6));
        if(@file_put_contents($temp,$jpeg)!==strlen($jpeg)){@unlink($temp);return null;}
        chmod($temp,0600);
        if(!@rename($temp,$cache)){@unlink($temp);return null;}
        return $cache;
    }finally{fclose($handle);}
}
function attachment_preview_url(array $file,string $storage): string {
    $url='?file='.(int)$file['id'];
    if(in_array($file['mime'],['image/jpeg','image/png','image/webp','image/gif'],true))return $url;
    return raf_preview($file,$storage)!==null?$url.'&raw_preview=1':'';
}

require_once __DIR__.'/metadata.php';

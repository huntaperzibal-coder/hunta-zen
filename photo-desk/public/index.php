<?php
declare(strict_types=1);
namespace PhotoDesk;
require dirname(__DIR__).'/app.php';
header('X-Robots-Tag: noindex, nofollow, noarchive');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; img-src 'self' blob:; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
if(isset($_GET['asset'])){
    $assets=['favicon.svg'=>'image/svg+xml; charset=utf-8','style.css'=>'text/css; charset=utf-8','desk.js'=>'text/javascript; charset=utf-8'];$a=(string)$_GET['asset'];if(!isset($assets[$a])){http_response_code(404);exit;}header('Content-Type: '.$assets[$a]);readfile(__DIR__.'/'.$a);exit;
}
$configPath=getenv('PHOTO_DESK_CONFIG')?:dirname(__DIR__,2).'/storage/photo-desk/config.php';
if(!is_file($configPath)){http_response_code(503);exit('Кабинет ещё не установлен. Запусти tools/photo_desk_install.php через SSH.');}
$config=require $configPath;$storage=$config['storage'];
if(PHP_SAPI!=='cli-server' && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off')){header('Location: '.$config['url'].'/',true,308);exit;}
ini_set('display_errors','0');date_default_timezone_set('Asia/Novosibirsk');
set_exception_handler(static function(\Throwable $e){error_log('PhotoDesk: '.$e);http_response_code(500);echo 'Не удалось открыть кабинет. Проверь журнал сервера; сохранённые данные не удалены.';});
$db=connect($storage);
ini_set('session.use_strict_mode','1');session_name('photo_desk');session_save_path($storage.'/sessions');session_set_cookie_params(['httponly'=>true,'secure'=>PHP_SAPI!=='cli-server','samesite'=>'Lax','path'=>'/']);session_start();$_SESSION['csrf']??=bin2hex(random_bytes(32));
$user=null;if(isset($_SESSION['login'])){$q=$db->prepare('SELECT * FROM users WHERE login=?');$q->execute([$_SESSION['login']]);$user=$q->fetch()?:null;if(!$user || !hash_equals(hash('sha256',$user['password_hash']),(string)($_SESSION['fp']??''))){$user=null;unset($_SESSION['login']);}}
function token(): string{return '<input type="hidden" name="csrf" value="'.esc($_SESSION['csrf']).'">';}
function go(string $to='./'): never {header('Location: '.$to,true,303);exit;}
$err='';$draft=[];$draftId=0;
if($_SERVER['REQUEST_METHOD']==='POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0){http_response_code(413);exit('Загрузка превышает post_max_size в настройках PHP. Увеличь лимит поддомена и повтори.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Сессия формы устарела. Обнови страницу.');}
    $action=(string)($_POST['action']??'');
    if($action==='login'){
        $login=trim((string)($_POST['login']??''));$key=hash('sha256',($_SERVER['REMOTE_ADDR']??'').'|'.mb_strtolower($login));$ipkey=hash('sha256','ip:'.($_SERVER['REMOTE_ADDR']??''));
        $db->prepare('DELETE FROM attempts WHERE created_at<?')->execute([time()-900]);$q=$db->prepare('SELECT count(*) FROM attempts WHERE key_hash IN (?,?)');$q->execute([$key,$ipkey]);$blocked=(int)$q->fetchColumn()>=10;
        $q=$db->prepare('SELECT * FROM users WHERE login=? COLLATE NOCASE');$q->execute([$login]);$candidate=$q->fetch();
        if(!$blocked && $candidate && password_verify((string)($_POST['password']??''),$candidate['password_hash'])){session_regenerate_id(true);$_SESSION['login']=$candidate['login'];$_SESSION['fp']=hash('sha256',$candidate['password_hash']);$_SESSION['csrf']=bin2hex(random_bytes(32));go();}
        $db->prepare('INSERT INTO attempts VALUES(?,?)')->execute([$key,time()]);$db->prepare('INSERT INTO attempts VALUES(?,?)')->execute([$ipkey,time()]);$err=$blocked?'Слишком много попыток. Подожди 15 минут.':'Не удалось войти. Проверь логин и пароль.';
    }else{
        if(!$user){http_response_code(403);exit('Сначала войди в кабинет.');}
        if($action==='logout'){$_SESSION=[];session_destroy();go();}
        $stored=[];
        try{
            if((int)($_SERVER['CONTENT_LENGTH']??0)>0 && empty($_POST))throw new \RuntimeException('Превышен лимит загрузки PHP.');
            $id=(int)($_POST['id']??0);$revision=(int)($_POST['revision']??0);
            if($action==='preview'){
                $old=record($db,$id);if(!permitted($user['role'],'request'))throw new \RuntimeException('Превью обновляет юрист.');
                if((int)$old['revision']!==$revision)throw new \RuntimeException('Карточка изменилась. Обнови страницу.');
                $manual=trim((string)($_POST['preview_image_url']??''));
                $p=preview($manual?:$old['source_url'],$storage,$manual!=='');
                if($manual!=='')$p['title']=$old['source_title'];
                $q=$db->prepare('UPDATE cases SET source_title=?,preview_key=?,revision=revision+1,updated_at=? WHERE id=? AND revision=?');$q->execute([$p['title'],$p['key'],date('c'),$id,$revision]);
                if(!$q->rowCount()){if($p['key'])@unlink($storage.'/files/'.$p['key']);throw new \RuntimeException('Карточка изменилась. Повтори после обновления.');}
                event($db,$id,$user['login'],'Превью обновлено');$_SESSION['flash']=$p['key']?'Превью обновлено.':'Ссылка сохранена. Сайт не отдал картинку — прикрепи её вручную.';
            }elseif(in_array($action,['save','create','flag','delete'],true)){
                $input=[];
                if(in_array($action,['save','create'],true)){
                    foreach(['title','company','request_note','response_note','source_url'] as $key)if(isset($_POST[$key]))$input[$key]=(string)$_POST[$key];
                    foreach(['request','response'] as $side)if(isset($_FILES[$side])){if(!permitted($user['role'],$side))throw new \RuntimeException('Нет прав на вложения.');$stored=array_merge($stored,upload($_FILES[$side],$side,$storage));}
                }
                if($action==='flag'){$flag=(string)($_POST['flag']??'');if(!in_array($flag,['ready','closed'],true))throw new \RuntimeException('Неизвестная галочка.');$input[$flag]=isset($_POST['value'])?1:0;}
                $id=save($db,$action==='create'?null:$id,$revision,$input,$user['role'],$user['login'],$stored,$action==='delete'?(int)($_POST['attachment']??0):null);
                // Persistence succeeded: preview failures must never undo or delete attachments.
                $stored=[];
                $_SESSION['flash']='Сохранено · № '.$id;
                $saved=record($db,$id);
                if(isset($input['source_url']) && $saved['source_url']!=='' && $saved['source_title']===''){
                    try{
                        $p=preview($saved['source_url'],$storage);
                        $q=$db->prepare('UPDATE cases SET source_title=?,preview_key=?,revision=revision+1 WHERE id=? AND revision=?');
                        $q->execute([$p['title'],$p['key'],$id,$saved['revision']]);
                        if(!$q->rowCount() && $p['key'])@unlink($storage.'/files/'.$p['key']);
                    }catch(\Throwable $e){$_SESSION['flash'].='. Источник не отдал превью; ссылку сохранили. Можно прикрепить картинку вручную.';}
                }
            }else throw new \RuntimeException('Неизвестное действие.');
            $filter=in_array($_POST['filter']??'',['all','open','pending','ready','closed'],true)?$_POST['filter']:'all';$search=mb_substr((string)($_POST['q']??''),0,300);
            go('?'.http_build_query(['filter'=>$filter,'q'=>$search,'id'=>$id]).'#case-'.$id);
        }catch(\Throwable $e){foreach($stored as $f)@unlink($storage.'/files/'.$f['storage_key']);$err=$e instanceof \RuntimeException && !$e instanceof \PDOException?$e->getMessage():'Не удалось сохранить. Данные не перезаписаны.';$draft=$_POST;$draftId=(int)($_POST['id']??0);http_response_code(422);}
    }
}
if(isset($_GET['file']) || isset($_GET['preview'])){
    if(!$user){http_response_code(403);exit('Требуется вход.');}
    $name='preview.jpg';$mime='';$path='';
    if(isset($_GET['file'])){$q=$db->prepare('SELECT * FROM attachments WHERE id=?');$q->execute([(int)$_GET['file']]);$file=$q->fetch();if($file){$path=$storage.'/files/'.$file['storage_key'];$mime=$file['mime'];$name=$file['name'];}}
    else{$row=record($db,(int)$_GET['preview']);if($row['preview_key']){$path=$storage.'/files/'.$row['preview_key'];$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);}}
    if(!$path || !is_file($path)){http_response_code(404);exit('Файл не найден.');}
    $inline=!isset($_GET['download']) && in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true);
    if($inline && isset($_GET['thumb']) && extension_loaded('gd')){
        $cache=$storage.'/thumbs/'.basename($path).'.jpg';
        if(!is_file($cache)){$info=@getimagesize($path);if($info && $info[0]*$info[1]<=20000000 && filesize($path)<15000000){$img=@imagecreatefromstring(file_get_contents($path));if($img){$w=480;$h=max(1,(int)round(imagesy($img)*min(1,$w/imagesx($img))));$w=min($w,imagesx($img));$out=imagecreatetruecolor($w,$h);imagefill($out,0,0,imagecolorallocate($out,255,255,255));imagecopyresampled($out,$img,0,0,0,0,$w,$h,imagesx($img),imagesy($img));$temp=$cache.'.'.bin2hex(random_bytes(4));imagejpeg($out,$temp,82);chmod($temp,0600);rename($temp,$cache);imagedestroy($out);imagedestroy($img);}}}
        if(is_file($cache)){$path=$cache;$mime='image/jpeg';}
    }
    header('Content-Type: '.($inline?$mime:'application/octet-stream'));header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="file"; filename*=UTF-8\'\''.rawurlencode($name));header('Content-Length: '.filesize($path));session_write_close();readfile($path);exit;
}
function form_context(?array $row=null): string {
    $out=token();if($row)$out.='<input type="hidden" name="id" value="'.(int)$row['id'].'"><input type="hidden" name="revision" value="'.(int)$row['revision'].'">';
    foreach(['filter','q'] as $key)$out.='<input type="hidden" name="'.$key.'" value="'.esc($_GET[$key]??'').'">';return $out;
}
function attachments(\PDO $db,int $id,string $side,bool $editable,array $row): void {
    $q=$db->prepare('SELECT * FROM attachments WHERE case_id=? AND side=? ORDER BY id');$q->execute([$id,$side]);echo '<div class="attachments">';
    foreach($q as $file){$fid=(int)$file['id'];$image=in_array($file['mime'],['image/jpeg','image/png','image/webp','image/gif'],true);echo '<article class="attachment">';
        if($image)echo '<a href="?file='.$fid.'" class="image-link" data-image><img loading="lazy" src="?file='.$fid.'&amp;thumb=1" alt="'.esc($file['name']).'"></a>';
        else echo '<div class="file-icon">'.esc(strtoupper(pathinfo($file['name'],PATHINFO_EXTENSION))).'</div>';
        echo '<strong>'.esc($file['name']).'</strong><small>'.esc(round($file['size']/1048576,2)).' МБ</small><a class="button compact" href="?file='.$fid.'&amp;download=1">Скачать ↓</a>';
        if($editable)echo '<form method="post">'.form_context($row).'<input type="hidden" name="action" value="delete"><input type="hidden" name="attachment" value="'.$fid.'"><button class="text-button remove" data-confirm="Убрать вложение из карточки?">Убрать</button></form>';
        echo '</article>';
    }echo '</div>';
}
function summary_preview(\PDO $db,array $row): void {
    $url='';
    if($row['preview_key'])$url='?preview='.(int)$row['id'];
    else{
        $q=$db->prepare("SELECT id FROM attachments WHERE case_id=? AND mime IN ('image/jpeg','image/png','image/webp','image/gif') ORDER BY CASE side WHEN 'request' THEN 0 ELSE 1 END,id LIMIT 1");
        $q->execute([(int)$row['id']]);$fileId=$q->fetchColumn();
        if($fileId!==false)$url='?file='.(int)$fileId;
    }
    if($url!=='')echo '<a class="case-thumb" href="'.esc($url).'" data-image aria-label="Увеличить фото: '.esc($row['title']).'"><img loading="lazy" src="'.esc($url.'&thumb=1').'" alt="'.esc($row['title']).'"></a>';
}
function flag(array $row,string $key,string $label,bool $editable): void {
    echo '<form method="post" class="flag">'.form_context($row).'<input type="hidden" name="action" value="flag"><input type="hidden" name="flag" value="'.$key.'"><label><input type="checkbox" name="value" value="1" '.($row[$key]?'checked ':'').(!$editable?'disabled ':'').' data-flag> '.esc($label).'</label>'.($editable?'<button class="flag-save compact">Применить</button>':'').'</form>';
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Запросы по фотографиям</title><link rel="icon" type="image/svg+xml" sizes="any" href="/?asset=favicon.svg&amp;v=1"><link rel="stylesheet" href="?asset=style.css"><script defer src="?asset=desk.js"></script></head><body>
<header class="top"><span>▣ &nbsp; Кабинет фотографа и юриста</span><?php if($user):?><div><?=esc(['admin'=>'Режим разработки','lawyer'=>'Юрист','photographer'=>'Фотограф'][$user['role']])?> · <?=esc($user['login'])?> <form method="post"><?=token()?><button name="action" value="logout" class="text-button">Выйти ↗</button></form></div><?php endif?></header>
<main><h1>Запросы по фотографиям</h1>
<?php if($err):?><div class="notice error" role="alert"><?=esc($err)?><?php if($draft):?><p>Текст оставлен в форме. Файлы нужно выбрать повторно. При конфликте сначала скопируй текст, затем обнови страницу.</p><?php endif?></div><?php endif?>
<?php if(!$user):?><form method="post" class="login panel"><?=token()?><input type="hidden" name="action" value="login"><h2>Вход в кабинет</h2><label>Логин<input name="login" autocomplete="username" required></label><label>Пароль<input name="password" type="password" autocomplete="current-password" required></label><button class="primary">Войти</button></form>
<?php else:
$flash=$_SESSION['flash']??'';unset($_SESSION['flash']);if($flash)echo '<div class="notice" role="status">'.esc($flash).'</div>';
$filter=in_array($_GET['filter']??'',['all','open','pending','ready','closed'],true)?$_GET['filter']:'all';$search=mb_substr(trim((string)($_GET['q']??'')),0,300);$page=max(1,(int)($_GET['page']??1));$selected=(int)($_GET['id']??0);
$counts=$db->query('SELECT COUNT(*) total,COALESCE(SUM(closed=0),0) opened,COALESCE(SUM(closed=0 AND ready=0),0) pending,COALESCE(SUM(closed=0 AND ready=1),0) ready,COALESCE(SUM(closed=1),0) closed FROM cases')->fetch();
?><div class="toolbar"><form method="get" class="search"><input type="hidden" name="filter" value="<?=esc($filter)?>"><input name="q" value="<?=esc($search)?>" placeholder="ID, компания или текст" aria-label="Поиск"><button>Найти</button></form><nav class="filters"><?php foreach(['all'=>['Все','total'],'open'=>['Открытые','opened'],'pending'=>['Без ответа','pending'],'ready'=>['Ответ готов','ready'],'closed'=>['Закрытые','closed']] as $key=>[$label,$counter]):?><a class="button <?=$filter===$key?'active':''?>" href="?<?=esc(http_build_query(['filter'=>$key,'q'=>$search]))?>"><?=esc($label)?> <span><?=(int)$counts[$counter]?></span></a><?php endforeach?></nav><?php if(permitted($user['role'],'request')):?><button class="primary" data-new>＋ Новый запрос</button><?php endif?></div>
<p class="legend">Галочка фотографа — ответ готов. Галочка юриста — вся позиция закрыта.</p>
<?php if(permitted($user['role'],'request')):?><details class="new panel" id="new-case" <?=($draft && !$draftId)?'open':''?>><summary>Добавить запрос</summary><form method="post" enctype="multipart/form-data" data-upload><?=form_context()?><input type="hidden" name="action" value="create"><div class="fields"><label>Название<input name="title" maxlength="200" value="<?=esc(!$draftId?($draft['title']??''):'')?>" required></label><label>Компания<input name="company" maxlength="300" value="<?=esc(!$draftId?($draft['company']??''):'')?>"></label></div><label>Ссылка на источник<input type="url" name="source_url" value="<?=esc(!$draftId?($draft['source_url']??''):'')?>" placeholder="https://stock.adobe.com/…"></label><label>Запрос<textarea name="request_note" maxlength="20000"><?=esc(!$draftId?($draft['request_note']??''):'')?></textarea></label><label class="upload">＋ Фотографии или документы<input type="file" name="request[]" multiple></label><div class="local-previews"></div><button class="primary">Создать запрос</button></form></details><?php endif?>
<?php
$where=[];$params=[];if($filter==='open')$where[]='closed=0';if($filter==='pending')$where[]='closed=0 AND ready=0';if($filter==='ready')$where[]='closed=0 AND ready=1';if($filter==='closed')$where[]='closed=1';
if($search!==''){$where[]="(CAST(id AS TEXT)=? OR title LIKE ? ESCAPE '\\' OR company LIKE ? ESCAPE '\\' OR request_note LIKE ? ESCAPE '\\' OR response_note LIKE ? ESCAPE '\\' OR source_url LIKE ? ESCAPE '\\')";$like='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%';$params=[$search,$like,$like,$like,$like,$like];}
$sql=$where?' WHERE '.implode(' AND ',$where):'';$q=$db->prepare('SELECT COUNT(*) FROM cases'.$sql);$q->execute($params);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/20));$page=min($page,$pages);
$q=$db->prepare('SELECT * FROM cases'.$sql.' ORDER BY id DESC LIMIT 20 OFFSET '.(($page-1)*20));$q->execute($params);$rows=$q->fetchAll();
if(!$rows)echo '<section class="empty panel"><h2>'.($search!==''?'Ничего не найдено':'Здесь будут запросы').'</h2><p>'.($search!==''?'Измени поиск или фильтр.':'Создай первую позицию: добавь фотографию, ссылку и текст запроса.').'</p></section>';
foreach($rows as $n=>$row):$id=(int)$row['id'];$revision=$row['revision'];$editing=$draftId===$id && $draft;
$display=$row;if($editing)foreach(['title','company','request_note','response_note','source_url'] as $key)if(isset($draft[$key]))$display[$key]=$draft[$key];if($editing)$display['revision']=(int)($draft['revision']??0);
$req=permitted($user['role'],'request');$resp=permitted($user['role'],'response');
?>
<section class="case <?=$row['closed']?'closed':($row['ready']?'ready':'')?>" id="case-<?=$id?>"><div class="case-head"><button class="fold" aria-expanded="<?=($selected===$id||(!$selected&&$n===0)||$editing)?'true':'false'?>" aria-controls="body-<?=$id?>">⌄</button><?php summary_preview($db,$row);?><a class="case-title" href="?<?=esc(http_build_query(['id'=>$id,'filter'=>$filter,'q'=>$search,'page'=>$page]))?>#case-<?=$id?>">№ <?=str_pad((string)$id,4,'0',STR_PAD_LEFT)?> · <?=esc($row['title'])?></a><span class="company"><?=esc($row['company'])?></span><small><?=esc(date('d.m.Y',strtotime($row['created_at'])))?></small><span class="badge"><?=$row['closed']?'Закрыта':($row['ready']?'Ответ готов · Открыта':'Без ответа')?></span><div class="flags"><?php flag($row,'ready','Фотограф: ответ готов',$resp);flag($row,'closed','Юрист: закрыто',$req);?></div></div>
<div class="case-body" id="body-<?=$id?>" <?=($selected===$id||(!$selected&&$n===0)||$editing)?'':'hidden'?>>
<section class="panel"><h2>Запрос юриста</h2>
<?php if($row['source_url']):?><div class="source"><?php if($row['preview_key']):?><a class="image-link" data-image href="?preview=<?=$id?>"><img loading="lazy" src="?preview=<?=$id?>&amp;thumb=1" alt="Превью источника"></a><?php endif?><div><small><?=esc(parse_url($row['source_url'],PHP_URL_HOST))?></small><strong><?=esc($row['source_title']?:'Источник фотографии')?></strong><a href="<?=esc($row['source_url'])?>" target="_blank" rel="noopener noreferrer">Открыть источник ↗</a></div></div><?php if($req):?><form method="post" class="preview-form"><?=form_context($row)?><input type="hidden" name="action" value="preview"><button class="text-button">↻ Получить превью</button><details class="manual-preview"><summary>Указать картинку</summary><input type="url" name="preview_image_url" placeholder="HTTPS-ссылка на саму картинку" aria-label="Ссылка на картинку для превью"><button class="compact">Загрузить превью</button></details></form><?php endif?><?php endif?>
<?php attachments($db,$id,'request',$req,$row);?>
<?php if($req && !$row['preview_key']):?><p class="preview-hint">Нет картинки? Скопируй фото и вставь Ctrl+V в «Изменить запрос» или прикрепи файл.</p><?php endif?>
<?php if($req):?><?php if($display['request_note']!==''):?><p class="note"><?=nl2br(esc($display['request_note']))?></p><?php endif?><details class="edit-request" <?=($editing?'open':'')?>><summary>✎ Изменить запрос · добавить фото</summary><form method="post" enctype="multipart/form-data" data-upload><?=form_context($display)?><input type="hidden" name="action" value="save"><div class="fields"><label>Название<input name="title" maxlength="200" value="<?=esc($display['title'])?>" required></label><label>Компания<input name="company" maxlength="300" value="<?=esc($display['company'])?>"></label></div><label>Ссылка<input type="url" name="source_url" value="<?=esc($display['source_url'])?>"></label><label>Запрос<textarea name="request_note" maxlength="20000"><?=esc($display['request_note'])?></textarea></label><label class="upload">＋ Файлы · можно вставить фото Ctrl+V<input type="file" name="request[]" multiple></label><div class="local-previews"></div><button class="button">Сохранить запрос</button></form></details><?php else:?><p class="note"><?=nl2br(esc($row['request_note']))?></p><?php endif?></section>
<section class="panel"><h2>Ответ фотографа</h2><?php attachments($db,$id,'response',$resp,$row);?>
<?php if($resp):?><form method="post" enctype="multipart/form-data" data-upload><?=form_context($display)?><input type="hidden" name="action" value="save"><label class="upload">＋ Файлы · можно вставить фото Ctrl+V<input type="file" name="response[]" multiple></label><div class="local-previews"></div><label>Заметка<textarea name="response_note" maxlength="20000" placeholder="Пояснение к ответу…"><?=esc($display['response_note'])?></textarea></label><button class="primary">Сохранить ответ</button></form><?php else:?><p class="note"><?=nl2br(esc($row['response_note']?:'Ответ пока не добавлен.'))?></p><?php endif?></section>
<details class="history"><summary>История изменений · Обновлено <?=esc(date('d.m.Y H:i',strtotime($row['updated_at'])))?></summary><?php $events=$db->prepare('SELECT * FROM events WHERE case_id=? ORDER BY id DESC LIMIT 50');$events->execute([$id]);foreach($events as $event):?><p><?=esc(date('d.m H:i',strtotime($event['created_at'])))?> · <?=esc($event['actor'])?> · <?=esc($event['action'])?></p><?php endforeach?></details>
</div></section>
<?php endforeach?>
<nav class="pagination" aria-label="Страницы"><?php if($page>1):?><a class="button" href="?<?=esc(http_build_query(['filter'=>$filter,'q'=>$search,'page'=>$page-1]))?>">← Назад</a><?php endif?><span>Найдено: <?=$total?> · Страница <?=$page?> из <?=$pages?></span><?php if($page<$pages):?><a class="button" href="?<?=esc(http_build_query(['filter'=>$filter,'q'=>$search,'page'=>$page+1]))?>">Далее →</a><?php endif?></nav>
<?php endif?></main><dialog id="image-view"><button type="button" class="close-view" aria-label="Закрыть">✕</button><img alt="Увеличенное изображение"><a class="button" href="" download>Скачать оригинал ↓</a></dialog></body></html>

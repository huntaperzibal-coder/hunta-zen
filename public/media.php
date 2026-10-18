<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$name=(string)($_GET['file']??'');
if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|mp4)$/D',$name,$m)) { http_response_code(404); exit; }
// Не публикуем фото из черновиков по известному URL.
$q=$db->prepare("SELECT 1 FROM posts WHERE status='published' AND (cover=? OR instr(body,?)>0 OR instr(body,?)>0) LIMIT 1"); $q->execute([$name,']('.$name.')','file='.$name]);
$profile=in_array($name,array_filter([reader_public_photo('author_photo'),reader_public_photo('support_qr')]),true);
if (!$q->fetchColumn() && !$profile && !authenticated()) { http_response_code(404);exit; }
$file=$storage.'/photos/'.$name; if (!is_file($file)) { http_response_code(404); exit; }
header('Content-Type: '.['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','mp4'=>'video/mp4'][$m[1]]);
header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$size=filesize($file);$range=media_byte_range((string)($_SERVER['HTTP_RANGE']??''),$size);
header('Accept-Ranges: bytes');
if($range===false){http_response_code(416);header('Content-Range: bytes */'.$size);exit;}
[$start,$end]=$range??[0,$size-1];
if($range!==null){http_response_code(206);header("Content-Range: bytes $start-$end/$size");}
header('Content-Length: '.($end-$start+1));
if($_SERVER['REQUEST_METHOD']==='HEAD')exit;
$stream=fopen($file,'rb');fseek($stream,$start);$remaining=$end-$start+1;
while($remaining>0&&!feof($stream)&&!connection_aborted()){$chunk=fread($stream,min(65536,$remaining));if($chunk===false||$chunk==='')break;echo $chunk;$remaining-=strlen($chunk);}
fclose($stream);

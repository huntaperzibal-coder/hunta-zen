<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$name=(string)($_GET['file']??'');
if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$name,$m)) { http_response_code(404); exit; }
// Не публикуем фото из черновиков по известному URL.
$q=$db->prepare("SELECT 1 FROM posts WHERE status='published' AND (cover=? OR instr(body,?)>0) LIMIT 1"); $q->execute([$name,']('.$name.')']);
if (!$q->fetchColumn() && !authenticated()) { http_response_code(404);exit; }
$file=$storage.'/photos/'.$name; if (!is_file($file)) { http_response_code(404); exit; }
header('Content-Type: '.['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$m[1]]);
header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); header('Content-Length: '.filesize($file)); readfile($file);

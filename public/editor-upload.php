<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405);header('Allow: POST');echo json_encode(['error'=>'Нужна загрузка файла.']);exit; }
if (!authenticated()) { http_response_code(401);echo json_encode(['error'=>'Войди в админку заново.']);exit; }
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0) { http_response_code(413);echo json_encode(['error'=>'Файл превышает лимит хостинга: '.round(video_upload_limit()/1048576,1).' МБ.']);exit; }
session_open();
if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { http_response_code(403);echo json_encode(['error'=>'Сессия формы устарела.']);exit; }
try {
    post((int)($_POST['id']??0));
    $name=($_POST['kind']??'')==='video'?video_upload($_FILES['file']??[]):photo_upload($_FILES['file']??[]);
    echo json_encode(['location'=>photo_url($name)],JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);echo json_encode(['error'=>$e instanceof RuntimeException?$e->getMessage():'Не удалось загрузить файл.']);
}

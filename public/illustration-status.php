<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Cache-Control: no-store');header('Content-Type: application/json');
if(!authenticated()){http_response_code(401);echo '{}';exit;}
$q=$db->prepare('SELECT id,state,image,error FROM illustration_jobs WHERE post_id=? AND snapshot NOT LIKE ? ORDER BY id DESC LIMIT 30');$q->execute([(int)($_GET['id']??0),'{"mode":"proofread",%']);
echo json_encode($q->fetchAll(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

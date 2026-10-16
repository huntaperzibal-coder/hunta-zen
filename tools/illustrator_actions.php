<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Invoked only by the Actions Python runner in a private temporary directory.
$packet=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
require dirname(__DIR__).'/app/bootstrap.php';
echo json_encode(illustration_actions_execute($packet),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

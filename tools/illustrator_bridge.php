<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
try{
    require dirname(__DIR__).'/app/bootstrap.php';
    // SSH_ORIGINAL_COMMAND is deliberately ignored. No shell command is accepted.
    $input=stream_get_contents(STDIN,32*1024*1024+1);
    if(strlen($input)>32*1024*1024)throw new RuntimeException('Слишком большой запрос.');
    $request=json_decode($input,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($request))throw new RuntimeException('Некорректный запрос.');
    echo json_encode(illustration_bridge($request),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    // Transport logs are public in this repository: never echo exception/request details.
    echo '{"ok":false,"error":"SSH bridge operation failed; inspect the site configuration."}';exit(1);
}

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
// Read-only API endpoints: no generation, no paid inference, no automatic retries.
$key=illustration_key();
foreach(['models','key'] as $endpoint){
    if($endpoint==='key' && $key===''){echo "Ключ на сайте ещё не задан.\n";continue;}
    $body='';$curl=curl_init('https://openrouter.ai/api/v1/'.$endpoint);
    curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$endpoint==='key'?['Authorization: Bearer '.$key]:[],
        CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)<16384)$body.=substr($chunk,0,16384-strlen($body));return strlen($chunk);}]);
    $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$type=(string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE);$errno=curl_errno($curl);curl_close($curl);
    echo '/'.$endpoint.': HTTP '.$status.'; Content-Type: '.preg_replace('/[^a-zA-Z0-9;= .\/-]/','',$type)."\n";
    if($ok===false){echo 'Ошибка соединения cURL '.$errno."\n";continue;}
    if($status>=200 && $status<300){echo $endpoint==='key'?"Ключ принят.\n":"Каталог моделей доступен.\n";continue;}
    echo illustration_error_detail($status,$body,$key)."\n";
    echo 'Получено байт ответа (до 16 КБ): '.strlen($body)."\n";
    if(str_contains(strtolower($type),'text/html'))echo "Вместо JSON пришла HTML-страница отказа.\n";
}

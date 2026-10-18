<?php
declare(strict_types=1);
const VIDEO_MAX_BYTES=45*1024*1024;
function video_upload_limit(): int {
    $limits=[VIDEO_MAX_BYTES];
    foreach(['upload_max_filesize','post_max_size'] as $key){$raw=trim((string)ini_get($key));$n=(float)$raw;$unit=strtolower(substr($raw,-1));$n*=match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};if($n>0)$limits[]=(int)$n-65536;}
    return max(0,min($limits));
}
function article_video_name(string $url): ?string {
    global $config;
    $base=rtrim($config['site_url'],'/');if(str_starts_with($url,$base.'/'))$url=substr($url,strlen($base));
    return preg_match('~^/media\.php\?file=([a-f0-9]{32}\.mp4)$~D',$url,$m)?$m[1]:null;
}
function video_upload(array $file): string {
    global $storage;
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Видео не загрузилось. Лимит хостинга: '.round(video_upload_limit()/1048576,1).' МБ.');
    $tmp=(string)($file['tmp_name']??'');
    if(!is_uploaded_file($tmp))throw new RuntimeException('Нужна загрузка видео с компьютера.');
    $size=filesize($tmp);
    if(!$size||$size>video_upload_limit())throw new RuntimeException('Видео слишком большое. Допустимо до '.round(video_upload_limit()/1048576,1).' МБ.');
    if((new finfo(FILEINFO_MIME_TYPE))->file($tmp)!=='video/mp4')throw new RuntimeException('Нужен видеофайл MP4. Для совместимости используй H.264 со звуком AAC.');
    $name=bin2hex(random_bytes(16)).'.mp4';
    // Alongside photos: existing verified backups and storage permissions cover videos too.
    if(!move_uploaded_file($tmp,$storage.'/photos/'.$name))throw new RuntimeException('Не удалось сохранить видео.');
    chmod($storage.'/photos/'.$name,0600);return $name;
}
/** null = no range, false = unsatisfiable, otherwise inclusive byte offsets. */
function media_byte_range(string $range,int $size): array|false|null {
    if($range==='')return null;
    if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m)||($m[1]===''&&$m[2]===''))return false;
    if($m[1]===''){$length=(int)$m[2];return $length>0&&$size>0?[max(0,$size-$length),$size-1]:false;}
    $start=(int)$m[1];$end=$m[2]!==''?min((int)$m[2],$size-1):$size-1;
    return $start<=$end&&$start<$size?[$start,$end]:false;
}

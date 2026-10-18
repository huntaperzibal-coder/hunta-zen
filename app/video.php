<?php
declare(strict_types=1);
const VIDEO_MAX_BYTES=200*1024*1024;
function video_upload_limit(): int {
    $limits=[VIDEO_MAX_BYTES];
    foreach(['upload_max_filesize','post_max_size'] as $key){$raw=trim((string)ini_get($key));$n=(float)$raw;$unit=strtolower(substr($raw,-1));$n*=match($unit){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};if($n>0)$limits[]=(int)$n-($key==='post_max_size'?65536:0);}
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

/** Read MP4 boxes by seeking, without loading the video into PHP memory. */
function video_mp4_metadata(string $file): array {
    $stream=@fopen($file,'rb');if(!$stream)return [];
    $result=[];$moov=null;$mdat=null;$boxes=0;
    $walk=function(int $start,int $end,int $depth=0)use(&$walk,$stream,&$result,&$moov,&$mdat,&$boxes):void{
        if($depth>4)return;
        for($pos=$start;$pos+8<=$end&&++$boxes<4096;){
            fseek($stream,$pos);$header=fread($stream,8);if(strlen($header)!==8)return;
            $size=unpack('N',substr($header,0,4))[1];$type=substr($header,4,4);$head=8;
            if($size===1){$wide=fread($stream,8);if(strlen($wide)!==8)return;$parts=unpack('Nhigh/Nlow',$wide);if($parts['high']>1)return;$size=$parts['high']*4294967296+$parts['low'];$head=16;}
            if($size===0)$size=$end-$pos;
            if($size<$head||$size>$end-$pos)return;
            $data=$pos+$head;
            if($type==='mdat'&&$depth===0)$mdat??=$pos;
            if($type==='moov'){$moov=$pos;$walk($data,$pos+$size,$depth+1);}
            elseif($type==='trak')$walk($data,$pos+$size,$depth+1);
            elseif($type==='mvhd'&&$size-$head>=32){
                fseek($stream,$data);$v=ord(fread($stream,1));fseek($stream,$data+($v===1?20:12));$raw=fread($stream,$v===1?12:8);
                if(strlen($raw)===($v===1?12:8)){$n=unpack($v===1?'Nscale/Nhigh/Nlow':'Nscale/Nlow',$raw);$duration=(($n['high']??0)*4294967296+$n['low'])/max(1,$n['scale']);if($duration>0&&$duration<=604800)$result['duration']=max(1,(int)round($duration));}
            }elseif($type==='tkhd'&&$size-$head>=84){
                fseek($stream,$pos+$size-8);$n=unpack('Nwidth/Nheight',fread($stream,8));$w=(int)($n['width']/65536);$h=(int)($n['height']/65536);
                if($w>0&&$h>0&&$w<=16384&&$h<=16384)$result+=['width'=>$w,'height'=>$h];
            }
            $pos+=$size;
        }
    };
    try{$walk(0,(int)filesize($file));}finally{fclose($stream);}
    if($moov!==null&&$mdat!==null&&isset($result['width']))$result['supports_streaming']=$moov<$mdat;
    return $result;
}
function video_thumbnail_path(string $name): ?string {
    global $storage;
    if(!preg_match('/^[a-f0-9]{32}\.mp4$/D',$name))return null;
    $path=$storage.'/photos/'.$name.'.thumb.jpg';
    return is_file($path)?$path:null;
}
function video_thumbnail_upload(string $name,array $file): void {
    global $storage;
    if(!preg_match('/^[a-f0-9]{32}\.mp4$/D',$name)||!is_file($storage.'/photos/'.$name))throw new RuntimeException('Видео недоступно.');
    $tmp=(string)($file['tmp_name']??'');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($tmp)||filesize($tmp)>=200000)throw new RuntimeException('Не удалось подготовить обложку видео.');
    $image=@getimagesize($tmp);
    if(!$image||$image[2]!==IMAGETYPE_JPEG||$image[0]>320||$image[1]>320)throw new RuntimeException('Неверная обложка видео.');
    $path=$storage.'/photos/'.$name.'.thumb.jpg';
    if(!move_uploaded_file($tmp,$path))throw new RuntimeException('Не удалось сохранить обложку видео.');
    chmod($path,0600);
}

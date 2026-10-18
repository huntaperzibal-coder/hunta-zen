<?php
declare(strict_types=1);
const READER_IMAGE_WIDTHS=[480,960,1440];
function reader_images_supported(): bool {
    return function_exists('imagewebp')&&function_exists('imagecreatetruecolor')&&function_exists('imagecreatefrompng')&&function_exists('imagecreatefromjpeg')&&(gd_info()['WebP Support']??false);
}
function reader_image_info(string $name): array|false {
    global $storage;
    if(!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$name))return false;
    return @getimagesize($storage.'/photos/'.$name);
}
/** Originals are immutable. Only disposable private derivatives are written here. */
function reader_image_variant(string $name,int $width): string {
    global $storage;
    $original=$storage.'/photos/'.$name;
    if(!in_array($width,READER_IMAGE_WIDTHS,true)||!reader_images_supported())return $original;
    $info=reader_image_info($name);
    if(!$info||$info[0]*$info[1]>20000000)return $original;
    // GD does not retain EXIF; preserve JPEG orientation when EXIF is available.
    if($info['mime']==='image/jpeg'&&!function_exists('exif_read_data'))return $original;
    $dir=$storage.'/image-cache/reader-v1';
    if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return $original;
    $stamp=hash('sha256',(string)filemtime($original).':'.filesize($original));
    $file=$dir.'/'.$name.'-'.$width.'-'.substr($stamp,0,12).'.webp';
    if(is_file($file))return $file;
    $lock=@fopen($dir.'/'.$name.'.lock','c');
    if(!$lock)return $original;
    @chmod($dir.'/'.$name.'.lock',0600);
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);return $original;}
    $source=null;$target=null;$temp=null;
    try{
        if(is_file($file))return $file;
        $orientation=1;
        if($info['mime']==='image/jpeg')$orientation=(int)((@exif_read_data($original,'IFD0',true)['IFD0']['Orientation'])??1);
        $sw=$info[0];$sh=$info[1];
        if(in_array($orientation,[5,6,7,8],true))[$sw,$sh]=[$sh,$sw];
        $tw=min($width,$sw);$th=max(1,(int)round($sh*$tw/$sw));
        $raw=trim((string)ini_get('memory_limit'));$limit=(int)$raw;
        $limit*=match(strtolower(substr($raw,-1))){'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
        $needed=$info[0]*$info[1]*8+$tw*$th*8+16*1024*1024;
        if($limit>0&&memory_get_usage(true)+$needed>$limit)return $original;
        $source=match($info['mime']){'image/jpeg'=>@imagecreatefromjpeg($original),'image/png'=>@imagecreatefrompng($original),'image/webp'=>function_exists('imagecreatefromwebp')?@imagecreatefromwebp($original):false,default=>false};
        if(!$source)return $original;
        if(in_array($orientation,[2,4,5,7],true))imageflip($source,in_array($orientation,[2,5,7],true)?IMG_FLIP_HORIZONTAL:IMG_FLIP_VERTICAL);
        $angle=match($orientation){3=>180,6,7=>-90,5,8=>90,default=>0};
        if($angle){$rotated=imagerotate($source,$angle,0);if(!$rotated)return $original;imagedestroy($source);$source=$rotated;}
        $target=imagecreatetruecolor($tw,$th);imagealphablending($target,false);imagesavealpha($target,true);
        if(!imagecopyresampled($target,$source,0,0,0,0,$tw,$th,imagesx($source),imagesy($source)))return $original;
        $temp=tempnam($dir,'.webp-');if(!$temp)return $original;
        chmod($temp,0600);
        if(!imagewebp($target,$temp,82)||filesize($temp)===0)return $original;
        // Small originals are already efficient: never replace them with a larger derivative.
        if(filesize($temp)>=filesize($original))return $original;
        if(!rename($temp,$file))return $original;
        $temp=null;return $file;
    }catch(Throwable $e){return $original;}
    finally{
        if($temp&&is_file($temp))@unlink($temp);
        if($source)imagedestroy($source);if($target)imagedestroy($target);
        flock($lock,LOCK_UN);fclose($lock);
    }
}
function reader_image_attributes(string $name,bool $cover=false,bool $dimensions=true): string {
    $original=photo_url($name);$info=reader_image_info($name);
    $attrs=' src="'.h($original).'" data-original="'.h($original).'"';
    if(!$info||!reader_images_supported()||$info[0]*$info[1]>20000000)return $attrs;
    $w=$info[0];$h=$info[1];
    if($info['mime']==='image/jpeg'){
        if(!function_exists('exif_read_data'))return $attrs;
        global $storage;$exif=@exif_read_data($storage.'/photos/'.$name,'IFD0',true);
        if(in_array((int)($exif['IFD0']['Orientation']??1),[5,6,7,8],true))[$w,$h]=[$h,$w];
    }
    $candidates=[];foreach(READER_IMAGE_WIDTHS as $size)$candidates[min($size,$w)]=photo_url($name).'&width='.$size.' '.min($size,$w).'w';
    $desktop=min(628,(int)round($w*($cover?260:340)/$h));$mobile=min(628,(int)round($w*($cover?230:340)/$h));
    $attrs=' src="'.h($original.'&width=480').'" data-original="'.h($original).'" srcset="'.h(implode(', ',$candidates)).'" sizes="(max-width: 760px) min(calc(100vw - 48px), '.$mobile.'px), '.$desktop.'px"';
    return $attrs.($dimensions?' width="'.$w.'" height="'.$h.'"':'');
}
/** Presentation only: stored editor/Telegram markup continues to reference originals. */
function reader_body_images(string $html): string {
    return preg_replace_callback('~<img\b[^>]*>~i',static function(array $m):string{
        if(!preg_match('~\bsrc="([^"]+)"~',$m[0],$src))return $m[0];
        $name=article_photo_name(html_entity_decode($src[1],ENT_QUOTES|ENT_HTML5,'UTF-8'));
        if(!$name)return $m[0];
        $dimensions=!preg_match('~\b(?:width|height)="~',$m[0]);
        return str_replace($src[0],trim(reader_image_attributes($name,false,$dimensions)),$m[0]);
    },$html);
}

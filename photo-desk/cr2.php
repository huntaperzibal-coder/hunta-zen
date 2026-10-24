<?php
declare(strict_types=1);
namespace PhotoDesk;

/** Bounded TIFF directories from the original CR2, never from its JPEG.
 * References: libopenraw formats/cr2; ExifTool Exif.pm (IFD0 preview tags).
 */
function cr2_directories(string $path): array {
    $h=@fopen($path,'rb');if(!$h)return [];
    try{
        $size=fstat($h)['size'];$head=fread($h,16);
        if(strlen($head)!==16 || !in_array(substr($head,0,2),['II','MM'],true) || substr($head,8,4)!=="CR\x02\x00")return [];
        $le=substr($head,0,2)==='II';
        $u16=fn($b)=>unpack($le?'v':'n',$b)[1];$u32=fn($b)=>unpack($le?'V':'N',$b)[1];
        if($u16(substr($head,2,2))!==42)return [];
        $read=static function(int $offset,int $length)use($h,$size):?string{
            if($offset<0 || $length<1 || $offset>$size || $length>$size-$offset || fseek($h,$offset)!==0)return null;
            $b=stream_get_contents($h,$length);return strlen($b)===$length?$b:null;
        };
        $queue=[[$u32(substr($head,4,4)),'IFD0']];$seen=[];$dirs=[];$budget=1048576;
        while($queue && count($seen)<32){
            [$offset,$group]=array_shift($queue);
            if(!$offset || isset($seen[$offset]))continue;$seen[$offset]=true;
            $b=$read($offset,2);if($b===null)continue;$count=$u16($b);
            if($count>512)continue;$b=$read($offset+2,$count*12+4);if($b===null)continue;
            $tags=[];
            for($i=0;$i<$count;$i++){
                $e=substr($b,$i*12,12);$tag=$u16(substr($e,0,2));$type=$u16(substr($e,2,2));$n=$u32(substr($e,4,4));
                $width=[1=>1,2=>1,3=>2,4=>4,5=>8,7=>1,9=>4,10=>8][$type]??0;
                // MakerNote and binary image blocks are not text metadata.
                if(!$width || !$n || $n>4096 || $tag===0x927c)continue;
                $length=$width*$n;if($length>65536 || $length>$budget)continue;$budget-=$length;
                $data=$length<=4?substr($e,8,$length):$read($u32(substr($e,8,4)),$length);if($data===null)continue;
                if($type===2)$value=rtrim($data,"\0");
                elseif($type===7)continue;
                else{
                    $value=[];
                    for($j=0;$j<$n;$j++){
                        $part=substr($data,$j*$width,$width);
                        if($type===1)$v=ord($part);
                        elseif($type===3)$v=$u16($part);
                        else{
                            $v=$u32(substr($part,0,4));if(in_array($type,[9,10],true) && $v>=2147483648)$v-=4294967296;
                            if(in_array($type,[5,10],true)){$d=$u32(substr($part,4,4));if($type===10 && $d>=2147483648)$d-=4294967296;$v=$v.'/'.$d;}
                        }
                        $value[]=$v;
                    }
                }
                $tags[$tag]=$value;
            }
            $dirs[]=['group'=>$group,'tags'=>$tags];
            foreach([0x8769=>'EXIF',0x8825=>'GPS',0xa005=>'Interop',0x014a=>'SubIFD'] as $tag=>$label)
                foreach(array_slice((array)($tags[$tag]??[]),0,32) as $v)if(is_int($v) && count($queue)<128)$queue[]=[$v,$label];
            $next=$u32(substr($b,$count*12,4));if($next)$queue[]=[$next,$group==='IFD0'?'IFD1':$group.' next'];
        }
        return $dirs;
    }finally{fclose($h);}
}
function cr2_preview(array $file,string $storage): ?string {
    $source=$storage.'/files/'.$file['storage_key'];$cache=$storage.'/thumbs/'.basename($file['storage_key']).'.cr2-v1.jpg';
    if(is_file($cache))return $cache;
    $dirs=cr2_directories($source);$h=@fopen($source,'rb');if(!$h)return null;
    try{
        $size=fstat($h)['size'];$best=null;$area=0;
        foreach($dirs as $dir){
            // IFD0 is Canon's full JPEG; IFD1 can supply a smaller thumbnail.
            if(!in_array($dir['group'],['IFD0','IFD1'],true))continue;
            $t=$dir['tags'];
            foreach([[0x111,0x117],[0x201,0x202]] as [$start,$length]){
                if(count((array)($t[$start]??[]))!==1 || count((array)($t[$length]??[]))!==1)continue;
                $o=$t[$start][0];$n=$t[$length][0];
                if(!is_int($o) || !is_int($n) || $o<16 || $n<4 || $n>16000000 || $o>$size || $n>$size-$o || fseek($h,$o)!==0)continue;
                $jpeg=stream_get_contents($h,$n);if(strlen($jpeg)!==$n || !str_starts_with($jpeg,"\xff\xd8"))continue;
                $info=@getimagesizefromstring($jpeg);if(!$info || $info[2]!==IMAGETYPE_JPEG)continue;
                $a=$info[0]*$info[1];if($a>20000000 || $a<=$area)continue;$area=$a;$best=$jpeg;
            }
        }
        if($best===null)return null;
        if(!is_dir($storage.'/thumbs') && !@mkdir($storage.'/thumbs',0700,true) && !is_dir($storage.'/thumbs'))return null;
        $temp=$cache.'.'.bin2hex(random_bytes(6));
        if(@file_put_contents($temp,$best)!==strlen($best)){@unlink($temp);return null;}chmod($temp,0600);
        if(!@rename($temp,$cache)){@unlink($temp);return null;}return $cache;
    }finally{fclose($h);}
}
function raw_preview(array $file,string $storage): ?string {
    return match(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))){
        'raf'=>raf_preview($file,$storage),'cr2'=>cr2_preview($file,$storage),default=>null,
    };
}
function cr2_native_metadata(string $path): array {
    $dirs=cr2_directories($path);$rows=[];
    $labels=[0x100=>'ImageWidth',0x101=>'ImageHeight',0x102=>'BitsPerSample',0x103=>'Compression',0x10e=>'ImageDescription',0x10f=>'Make',0x110=>'Model',0x112=>'Orientation',0x131=>'Software',0x132=>'DateTime',0x13b=>'Artist',0x8298=>'Copyright',0x829a=>'ExposureTime',0x829d=>'FNumber',0x8822=>'ExposureProgram',0x8827=>'ISO',0x9003=>'DateTimeOriginal',0x9004=>'DateTimeDigitized',0x9011=>'OffsetTimeOriginal',0x9201=>'ShutterSpeedValue',0x9202=>'ApertureValue',0x9204=>'ExposureBiasValue',0x9207=>'MeteringMode',0x9209=>'Flash',0x920a=>'FocalLength',0xa002=>'PixelXDimension',0xa003=>'PixelYDimension',0xa405=>'FocalLengthIn35mmFilm',0xa431=>'BodySerialNumber',0xa433=>'LensMake',0xa434=>'LensModel',0xa435=>'LensSerialNumber'];
    foreach($dirs as $dir)foreach($dir['tags'] as $tag=>$value){
        if(in_array($tag,[0x111,0x117,0x201,0x202,0x8769,0x8825,0xa005,0x14a],true))continue;
        $rows[]=['group'=>'CR2 · исходный RAW · '.$dir['group'],'label'=>($dir['group']==='GPS'?'GPS ':($labels[$tag]??'TIFF ')).sprintf(' [0x%04X]',$tag),'value'=>metadata_text($value)];
        if(count($rows)>=250)break 2;
    }
    return ['rows'=>$rows,'notice'=>$dirs?'Метаданные прочитаны из каталогов исходного CR2. Закрытый блок Canon MakerNote не расшифровывается.':'Не удалось прочитать каталоги исходного CR2.'];
}

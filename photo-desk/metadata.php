<?php
declare(strict_types=1);
namespace PhotoDesk;

/** Values are text only; do not expose server paths, MakerNote blobs or thumbnails. */
function metadata_text(mixed $value): string {
    if(is_array($value))$value=implode(', ',array_map(fn($v)=>is_scalar($v)?(string)$v:'',array_slice($value,0,64)));
    if(!is_scalar($value))return '';
    $value=(string)$value;
    if(!mb_check_encoding($value,'UTF-8'))$value=mb_convert_encoding($value,'UTF-8','Windows-1251');
    return mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$value)),0,4000);
}
/** Read the original RAF header and its bounded big-endian metadata directory.
 * Format references: libopenraw formats/raf; ExifTool FujiFilm RAF tags.
 */
function raf_native_metadata(string $path): array {
    $rows=[];$add=function(string $label,mixed $value)use(&$rows):void{$rows[]=['group'=>'RAF · исходный RAW','label'=>$label,'value'=>metadata_text($value)];};
    $h=@fopen($path,'rb');if(!$h)return ['rows'=>[],'notice'=>'Не удалось прочитать оригинал RAF.'];
    try{
        $header=fread($h,104);$size=fstat($h)['size'];
        if(strlen($header)<104 || substr($header,0,16)!=='FUJIFILMCCD-RAW ')return ['rows'=>[],'notice'=>'Заголовок RAF повреждён или неполон.'];
        $add('Формат','Fujifilm RAF');$add('Версия RAF',substr($header,16,4));
        $add('Идентификатор камеры (не серийный номер)',substr($header,20,8));
        $add('Модель камеры из заголовка RAF',substr($header,28,32));
        $add('Версия каталога RAF',substr($header,60,4));
        $dir=unpack('Noffset/Nlength',substr($header,92,8));
        if($dir['offset']<104 || $dir['length']<4 || $dir['length']>2097152 || $dir['offset']>$size || $dir['length']>$size-$dir['offset'])return ['rows'=>$rows,'notice'=>'Блок метаданных RAF отсутствует или имеет неверные границы.'];
        if(fseek($h,$dir['offset'])!==0)return ['rows'=>$rows,'notice'=>'Не удалось прочитать блок RAF.'];
        $bytes=stream_get_contents($h,$dir['length']);$len=strlen($bytes);
        if($len<4)return ['rows'=>$rows,'notice'=>'Блок метаданных RAF неполон.'];
        $count=unpack('N',substr($bytes,0,4))[1];$pos=4;
        if($count>1024)return ['rows'=>$rows,'notice'=>'Превышен лимит записей каталога RAF.'];
        $sizes=[0x100=>'Полный размер RAW с полями [RawImageFullSize]',0x111=>'Размер области RAW с полями [RawImageCroppedSize]',0x121=>'Размер RAW [RawImageSize]',0x119=>'Размер цифрового кадрирования [RawZoomSize]'];
        $pairs=[0x110=>'Отступ RAW: сверху, слева [RawImageCropTopLeft]',0x118=>'Начало цифрового кадрирования: сверху, слева [RawZoomTopLeft]'];
        for($i=0;$i<$count;$i++){
            if($pos+4>$len)return ['rows'=>$rows,'notice'=>'Каталог RAF обрезан; показаны прочитанные поля.'];
            $entry=unpack('ntag/nlength',substr($bytes,$pos,4));$pos+=4;
            if($entry['length']>$len-$pos)return ['rows'=>$rows,'notice'=>'Повреждена длина поля RAF; показаны прочитанные поля.'];
            $value=substr($bytes,$pos,$entry['length']);$pos+=$entry['length'];$tag=$entry['tag'];
            if(isset($sizes[$tag]) && strlen($value)===4){$v=array_values(unpack('n2',$value));$add($sizes[$tag],$v[1].' × '.$v[0]);}
            elseif(isset($pairs[$tag]) && strlen($value)===4)$add($pairs[$tag],array_values(unpack('n2',$value)));
            elseif($tag===0x2ff0 && strlen($value)===8)$add('Баланс белого GRGB [WB_GRGBLevels]',array_values(unpack('n4',$value)));
            elseif($tag===0x131 && strlen($value)===36)$add('Матрица X-Trans 6 × 6 [XTransLayout]',implode(' / ',array_map(fn($row)=>implode(' ',unpack('C*',$row)),str_split($value,6))));
            elseif($tag===0x130)$add('Расположение сенсора [FujiLayout], hex',bin2hex(substr($value,0,16)));
            elseif($tag===0xc000)$add('Дополнительный блок RAFData, байт',strlen($value));
            elseif(strlen($value)<=64)$add(sprintf('Поле RAF 0x%04X, hex',$tag),bin2hex($value));
        }
        return ['rows'=>$rows,'notice'=>''];
    }finally{fclose($h);}
}
function photo_metadata(array $file,string $storage): array {
    $original=$storage.'/files/'.$file['storage_key'];
    $cr2=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))==='cr2';
    $raf=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))==='raf';
    $path=$raf?raf_preview($file,$storage):$original;
    $rows=[];$add=function(string $group,string $label,mixed $value)use(&$rows):void{
        $text=metadata_text($value);if($text!=='')$rows[]=['group'=>$group,'label'=>$label,'value'=>$text];
    };
    $add('Файл','Имя',$file['name']);$add('Файл','Размер, байт',$file['size']);
    $add('Файл','Загружен в кабинет',$file['created_at']);
    $add('Файл','Источник метаданных',$cr2?'Исходный файл CR2: каталоги TIFF, EXIF и GPS':($raf?'Исходный файл RAF: заголовок и каталог RAW; EXIF — из блока внутри RAF':'Загруженный оригинал'));
    if($cr2){$native=cr2_native_metadata($original);return ['name'=>$file['name'],'rows'=>array_merge($rows,$native['rows']),'notice'=>$native['notice']];}
    $rafNotice='';if($raf){$native=raf_native_metadata($original);$rows=array_merge($rows,$native['rows']);$rafNotice=$native['notice'];}
    if(!$path)return ['name'=>$file['name'],'rows'=>$rows,'notice'=>trim($rafNotice.' EXIF-блок внутри RAF недоступен; показаны метаданные RAW.')];
    $imageInfo=[];$info=@getimagesize($path,$imageInfo);
    if($info && !$raf){$add('Изображение','Размер изображения',$info[0].' × '.$info[1]);$add('Изображение','Тип',$info['mime']);}
    $labels=['Make'=>'Производитель камеры','Model'=>'Камера','LensModel'=>'Объектив','LensMake'=>'Производитель объектива','DateTimeOriginal'=>'Дата съёмки','DateTimeDigitized'=>'Дата оцифровки','DateTime'=>'Дата изменения в EXIF','OffsetTimeOriginal'=>'Часовой пояс съёмки','ExposureTime'=>'Выдержка, с','FNumber'=>'Диафрагма','ISOSpeedRatings'=>'ISO','FocalLength'=>'Фокусное расстояние, мм','FocalLengthIn35mmFilm'=>'Фокусное в эквиваленте 35 мм','Artist'=>'Автор','Copyright'=>'Авторские права','Software'=>'Программа','ImageDescription'=>'Описание','Orientation'=>'Ориентация'];
    $found=false;
    if(function_exists('exif_read_data') && $info && in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_TIFF_II,IMAGETYPE_TIFF_MM],true)){
        $exif=@exif_read_data($path,null,true,false)?:[];
        foreach(['IFD0','EXIF','GPS','COMMENT'] as $section){
            foreach(($exif[$section]??[]) as $tag=>$value){
                if(in_array($tag,['MakerNote','UserComment','Exif_IFD_Pointer','GPS_IFD_Pointer','JPEGInterchangeFormat','JPEGInterchangeFormatLength'],true))continue;
                if(count($rows)>=250)break 2;
                $add(($raf?'EXIF внутри RAF · ':'EXIF · ').$section,($labels[$tag]??(string)$tag).' ['.$tag.']',$value);$found=true;
            }
        }
        if(isset($exif['COMPUTED']['UserComment'])){$add($raf?'EXIF внутри RAF':'EXIF','Комментарий',$exif['COMPUTED']['UserComment']);$found=true;}
    }
    if(isset($imageInfo['APP13']) && function_exists('iptcparse')){
        $iptc=@iptcparse($imageInfo['APP13'])?:[];
        $names=['2#005'=>'Название','2#025'=>'Ключевые слова','2#055'=>'Дата создания','2#080'=>'Автор','2#085'=>'Должность автора','2#090'=>'Город','2#095'=>'Регион','2#101'=>'Страна','2#110'=>'Источник','2#116'=>'Авторские права','2#120'=>'Описание','2#122'=>'Автор описания'];
        foreach($iptc as $tag=>$values){if(count($rows)>=300)break;$add($raf?'IPTC внутри RAF':'IPTC',$names[$tag]??$tag,$values);$found=true;}
    }
    $notice=$found?'':(function_exists('exif_read_data')?'EXIF/IPTC не найдены: файл может не содержать этих данных.':'На сервере не включено расширение PHP EXIF. Показаны доступные сведения о файле.');
    return ['name'=>$file['name'],'rows'=>$rows,'notice'=>trim($rafNotice.' '.$notice)];
}
function metadata_download(array $metadata): string {
    $text="Метаданные фотографии\r\n";$group='';
    foreach($metadata['rows'] as $row){if($row['group']!==$group){$group=$row['group'];$text.="\r\n".$group."\r\n";}$text.=$row['label'].': '.$row['value']."\r\n";}
    if($metadata['notice'])$text.="\r\n".$metadata['notice']."\r\n";
    return "\xEF\xBB\xBF".$text;
}


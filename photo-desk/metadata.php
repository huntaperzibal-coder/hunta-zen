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
function photo_metadata(array $file,string $storage): array {
    $original=$storage.'/files/'.$file['storage_key'];
    $raf=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))==='raf';
    $path=$raf?raf_preview($file,$storage):$original;
    $rows=[];$add=function(string $group,string $label,mixed $value)use(&$rows):void{
        $text=metadata_text($value);if($text!=='')$rows[]=['group'=>$group,'label'=>$label,'value'=>$text];
    };
    $add('Файл','Имя',$file['name']);$add('Файл','Размер, байт',$file['size']);
    $add('Файл','Загружен в кабинет',$file['created_at'].' (UTC)');
    $add('Файл','Источник метаданных',$raf?'Встроенный JPEG из RAF':'Загруженный оригинал');
    if(!$path)return ['name'=>$file['name'],'rows'=>$rows,'notice'=>'В RAF не найдено доступного JPEG-превью; EXIF не прочитан.'];
    $imageInfo=[];$info=@getimagesize($path,$imageInfo);
    if($info){$add('Изображение',$raf?'Размер встроенного JPEG':'Размер изображения',$info[0].' × '.$info[1]);$add('Изображение','Тип',$info['mime']);}
    $labels=['Make'=>'Производитель камеры','Model'=>'Камера','LensModel'=>'Объектив','LensMake'=>'Производитель объектива','DateTimeOriginal'=>'Дата съёмки','DateTimeDigitized'=>'Дата оцифровки','DateTime'=>'Дата изменения в EXIF','OffsetTimeOriginal'=>'Часовой пояс съёмки','ExposureTime'=>'Выдержка, с','FNumber'=>'Диафрагма','ISOSpeedRatings'=>'ISO','FocalLength'=>'Фокусное расстояние, мм','FocalLengthIn35mmFilm'=>'Фокусное в эквиваленте 35 мм','Artist'=>'Автор','Copyright'=>'Авторские права','Software'=>'Программа','ImageDescription'=>'Описание','Orientation'=>'Ориентация'];
    $found=false;
    if(function_exists('exif_read_data') && $info && in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_TIFF_II,IMAGETYPE_TIFF_MM],true)){
        $exif=@exif_read_data($path,null,true,false)?:[];
        foreach(['IFD0','EXIF','GPS','COMMENT'] as $section){
            foreach(($exif[$section]??[]) as $tag=>$value){
                if(in_array($tag,['MakerNote','UserComment','Exif_IFD_Pointer','GPS_IFD_Pointer','JPEGInterchangeFormat','JPEGInterchangeFormatLength'],true))continue;
                if(count($rows)>=250)break 2;
                $add('EXIF · '.$section,($labels[$tag]??(string)$tag).' ['.$tag.']',$value);$found=true;
            }
        }
        if(isset($exif['COMPUTED']['UserComment'])){$add('EXIF','Комментарий',$exif['COMPUTED']['UserComment']);$found=true;}
    }
    if(isset($imageInfo['APP13']) && function_exists('iptcparse')){
        $iptc=@iptcparse($imageInfo['APP13'])?:[];
        $names=['2#005'=>'Название','2#025'=>'Ключевые слова','2#055'=>'Дата создания','2#080'=>'Автор','2#085'=>'Должность автора','2#090'=>'Город','2#095'=>'Регион','2#101'=>'Страна','2#110'=>'Источник','2#116'=>'Авторские права','2#120'=>'Описание','2#122'=>'Автор описания'];
        foreach($iptc as $tag=>$values){if(count($rows)>=300)break;$add('IPTC',$names[$tag]??$tag,$values);$found=true;}
    }
    $notice=$found?'':(function_exists('exif_read_data')?'EXIF/IPTC не найдены: файл может не содержать этих данных.':'На сервере не включено расширение PHP EXIF. Показаны доступные сведения о файле.');
    return ['name'=>$file['name'],'rows'=>$rows,'notice'=>$notice];
}
function metadata_download(array $metadata): string {
    $text="Метаданные фотографии\r\n";$group='';
    foreach($metadata['rows'] as $row){if($row['group']!==$group){$group=$row['group'];$text.="\r\n".$group."\r\n";}$text.=$row['label'].': '.$row['value']."\r\n";}
    if($metadata['notice'])$text.="\r\n".$metadata['notice']."\r\n";
    return "\xEF\xBB\xBF".$text;
}

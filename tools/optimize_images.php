<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
if(!reader_images_supported()){fwrite(STDERR,"Для облегчённых копий нужно расширение PHP GD с поддержкой WebP. Оригиналы сохранены; сайт продолжит показывать их.\n");exit(1);}
$names=[];
foreach($db->query('SELECT cover,body FROM posts') as $post){
    if($post['cover'])$names[$post['cover']]=true;
    preg_match_all('/[a-f0-9]{32}\.(?:jpg|png|webp)/',$post['body'],$matches);
    foreach($matches[0] as $name)$names[$name]=true;
}
$count=0;$originalBytes=0;$readerBytes=0;$fallback=0;
foreach(array_keys($names) as $name){
    if(!reader_image_info($name))continue;
    $original=$storage.'/photos/'.$name;
    foreach(READER_IMAGE_WIDTHS as $width)$variant=reader_image_variant($name,$width);
    $reader=reader_image_variant($name,960);
    $originalBytes+=filesize($original);$readerBytes+=filesize($reader);$count++;
    if($reader===$original)$fallback++;
}
echo 'Проверено изображений: '.$count."\n";
echo 'Оригиналы: '.round($originalBytes/1048576,2).' МБ; версии до 960 px: '.round($readerBytes/1048576,2)." МБ.\n";
if($fallback)echo 'Без уменьшения: '.$fallback.' (маленькие файлы или ограничение обработки).'."\n";
echo "Оригиналы, тексты и публикации не изменены. Новые копии создаются автоматически при просмотре.\n";

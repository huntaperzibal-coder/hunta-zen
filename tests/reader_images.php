<?php
declare(strict_types=1);
if(!function_exists('imagewebp'))throw new RuntimeException('Tests require GD/WebP');
$dir=sys_get_temp_dir().'/zen-images-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/photos',0700);
$config=['storage'=>$dir,'site_url'=>'https://example.test'];
file_put_contents($dir.'/config.php','<?php return '.var_export($config,true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';
function image_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'OK: '.$message."\n";}
$name=str_repeat('a',32).'.png';$file=$dir.'/photos/'.$name;
try{
 $image=imagecreatetruecolor(1600,900);imagealphablending($image,false);imagesavealpha($image,true);
 for($y=0;$y<900;$y++){imagefilledrectangle($image,0,$y,1599,$y,imagecolorallocatealpha($image,($y*7)%256,($y*3)%256,$y%256,30));}
 // Fine detail makes the original appreciably larger than the reader derivative.
 for($x=0;$x<1600;$x+=3)imageline($image,$x,0,1599-$x,899,imagecolorallocatealpha($image,$x%256,130,90,70));
 imagepng($image,$file,0);imagedestroy($image);$hash=hash_file('sha256',$file);
 foreach(READER_IMAGE_WIDTHS as $width){$variant=reader_image_variant($name,$width);$info=getimagesize($variant);image_check($variant!==$file&&$info['mime']==='image/webp'&&$info[0]===$width&&$info[1]===(int)round(900*$width/1600),'correct WebP dimensions '.$width);image_check(filesize($variant)<filesize($file),'reader version is smaller '.$width);image_check((fileperms($variant)&0777)===0600,'derivative is private');}
 $variant=reader_image_variant($name,480);$mtime=filemtime($variant);image_check(reader_image_variant($name,480)===$variant&&filemtime($variant)===$mtime,'cached derivative reused');
 $decoded=imagecreatefromwebp($variant);image_check((imagecolorat($decoded,20,20)>>24)>0,'transparency retained');imagedestroy($decoded);
 image_check(hash_file('sha256',$file)===$hash,'original bytes unchanged');
 image_check(reader_image_variant($name,100)===$file,'arbitrary sizes rejected');
 $fallbackCode='$storage='.var_export($dir,true).';require '.var_export(ROOT.'/app/reader_images.php',true).';echo reader_image_variant('.var_export($name,true).',480);';
 $process=proc_open([PHP_BINARY,'-n','-r',$fallbackCode],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$fallback=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
 image_check($exit===0&&$error===''&&$fallback===$file,'missing GD safely returns original');

 $attrs=reader_image_attributes($name,true);image_check(str_contains($attrs,'srcset=')&&str_contains($attrs,'data-original="'.photo_url($name).'"')&&str_contains($attrs,'1440w'),'responsive candidates and original lightbox URL');
 $html='<figure><img src="'.photo_url($name).'" alt="Тест" width="300"></figure>';
 $result=reader_body_images($html);image_check(str_contains($result,'srcset=')&&str_contains($result,'width="300"')&&str_contains($result,'alt="Тест"'),'inline formatting survives presentation enhancement');
 image_check(str_contains(sanitize_article_html($html),'src="'.photo_url($name).'"')&&str_contains(sanitize_article_html($result),'src="'.photo_url($name).'"')&&!str_contains(sanitize_article_html($result),'srcset='),'editor does not store derivative attributes');
 // EXIF orientation 6: encoded landscape must display as portrait after transformation.
 if(function_exists('exif_read_data')){
  $im=imagecreatetruecolor(1600,900);ob_start();imagejpeg($im,null,95);$jpeg=ob_get_clean();imagedestroy($im);
  $tiff="II\x2a\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00\x06\x00\x00\x00\x00\x00\x00\x00";$exif="Exif\0\0".$tiff;$jname=str_repeat('b',32).'.jpg';file_put_contents($dir.'/photos/'.$jname,substr($jpeg,0,2)."\xff\xe1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));
  $j=reader_image_variant($jname,480);$info=getimagesize($j);image_check($info[0]===480&&$info[1]===853,'JPEG EXIF rotation retained');
 }
 echo 'Compression: '.filesize($file).' -> '.filesize($variant)." bytes (480 px)\n";
}finally{
 $db=null;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $p){if($p->isDir())rmdir($p->getPathname());else unlink($p->getPathname());}rmdir($dir);
}

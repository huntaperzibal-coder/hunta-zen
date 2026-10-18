<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/video.php';
function video_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo "OK: $message\n";}
function video_box(string $type,string $payload):string{return pack('N',strlen($payload)+8).$type.$payload;}
$path=tempnam(sys_get_temp_dir(),'zen-video-');
try{
    $mvhd=video_box('mvhd',str_repeat("\0",12).pack('NN',1000,60080).str_repeat("\0",12));
    $tkhd=video_box('tkhd',str_repeat("\0",76).pack('NN',852*65536,480*65536));
    $moov=video_box('moov',$mvhd.video_box('trak',$tkhd));$mdat=video_box('mdat',str_repeat('x',128));
    file_put_contents($path,$moov.$mdat);$info=video_mp4_metadata($path);
    video_check($info===['duration'=>60,'width'=>852,'height'=>480,'supports_streaming'=>true],'MP4 duration, dimensions and faststart parsed');
    file_put_contents($path,$mdat.$moov);video_check(video_mp4_metadata($path)['supports_streaming']===false,'moov after media is not advertised as streamable');
    $mvhd1=video_box('mvhd',"\1".str_repeat("\0",19).pack('NNN',1000,0,60000));
    file_put_contents($path,video_box('moov',$mvhd1.video_box('trak',$tkhd)).$mdat);video_check(video_mp4_metadata($path)['duration']===60,'64-bit MP4 movie header supported');
    foreach(['not a video',pack('N',0xffffffff).'moov',pack('N',1).'moov'.pack('NN',0xffffffff,0xffffffff)] as $bad){file_put_contents($path,$bad);video_check(video_mp4_metadata($path)===[],'invalid or truncated MP4 safely ignored');}
}finally{unlink($path);}

<?php
declare(strict_types=1);
function telegram_message_meta(int $id,string $channel,?array $delivery=null): array {
    global $db;
    $q=$db->prepare('SELECT * FROM telegram_message_meta WHERE post_id=? AND channel=?');$q->execute([$id,$channel]);
    $meta=$q->fetch()?:['format'=>'rich','linked_at'=>null,'synced_at'=>null,'synced_hash'=>'','update_mode'=>'auto'];
    if(telegram_nontext_message((string)($delivery['error']??''))&&$meta['format']!=='media'){$meta['format']='media';$meta['synced_at']=null;$meta['synced_hash']='';}
    return $meta;
}
function telegram_record_sync(int $id,string $channel,string $format,string $hash,string $mode='auto'): void {
    global $db;
    $db->prepare('INSERT INTO telegram_message_meta(post_id,channel,format,synced_at,synced_hash,update_mode) VALUES(?,?,?,?,?,?) ON CONFLICT(post_id,channel) DO UPDATE SET format=excluded.format,synced_at=excluded.synced_at,synced_hash=excluded.synced_hash,update_mode=excluded.update_mode')->execute([$id,$channel,$format,now(),$hash,$mode]);
}
function telegram_media_document(array $p): array {
    $plain=$p;$plain['telegram_footer']='off';$doc=telegram_document($plain);
    if(telegram_has_footer($p)){
        $doc['text'].="\n\n";$start=telegram_units($doc['text']);
        $doc['text'].='Гена и Винни';$doc['entities'][]=['type'=>'bold','offset'=>$start,'length'=>telegram_units('Гена и Винни')];
        if($p['status']==='published'){
            $doc['text'].="\n";$offset=telegram_units($doc['text']);$doc['text'].='🌐 Читать на сайте';
            $doc['entities'][]=['type'=>'text_link','offset'=>$offset,'length'=>telegram_units('🌐 Читать на сайте'),'url'=>seo_base().'/?p='.rawurlencode($p['slug'])];
        }
        $doc['entities'][]=['type'=>'blockquote','offset'=>$start,'length'=>telegram_units($doc['text'])-$start];
    }
    return $doc;
}
/** A legacy media message has one caption and at most one replacement attachment. */
function telegram_media_plan(array $p,string $mode='auto'): array {
    global $storage;
    if(!in_array($mode,['auto','caption','photo','video'],true))throw new RuntimeException('Выбери, что обновить в старом медиапосте.');
    $doc=telegram_media_document($p);$length=telegram_units($doc['text']);
    if($length>1024)throw new RuntimeException('Старый пост с фото/видео: подпись получилась '.$length.' символов при лимите 1024, включая заголовок и подпись блога. Сократи «Особый текст для канала» или отключи подпись блога. В Telegram ничего не изменено; текст не обрезан.');
    $type='caption';$name='';
    if($mode!=='caption'){
        $body=body_for_editor($p['body']);$photo=(string)$p['cover'];
        if($photo===''&&preg_match('~(?:file=|\()([a-f0-9]{32}\.(?:jpg|png|webp))~',$body,$m))$photo=$m[1];
        $videos=[];telegram_rich_body($p['body'],$videos);
        if($mode==='auto'){
            if(count($videos)>1||($photo!==''&&$videos))throw new RuntimeException('В редакторе несколько вложений, а старый пост содержит одно. Выбери «Текст и фото», «Текст и видео» или обнови только подпись.');
            $mode=$videos?'video':($photo!==''?'photo':'caption');
        }
        if($mode==='photo'){
            if(!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$photo)||!is_file($storage.'/photos/'.$photo))throw new RuntimeException('Добавь обложку или фотографию в редактор для замены изображения.');
            if(filesize($storage.'/photos/'.$photo)>10*1024*1024)throw new RuntimeException('Фотография для Telegram должна быть не больше 10 МБ.');
            $type='photo';$name=$photo;
        }elseif($mode==='video'){
            if(count($videos)!==1)throw new RuntimeException('Для замены видео оставь в редакторе один ролик.');
            $name=(string)array_key_first($videos);
            if(!is_file($storage.'/photos/'.$name)||filesize($storage.'/photos/'.$name)>50*1024*1024)throw new RuntimeException('Видео недоступно или больше лимита Telegram 50 МБ.');
            $type='video';
        }
    }
    $payload=['caption'=>$doc['text'],'caption_entities'=>$doc['entities']];
    $method='editMessageCaption';
    if($type!=='caption'){
        $method='editMessageMedia';$media=['type'=>$type,'media'=>'attach://replacement']+$payload;
        $file=$storage.'/photos/'.$name;
        $payload=['replacement'=>new CURLFile($file,$type==='video'?'video/mp4':mime_content_type($file),basename($name))];
        if($type==='video'){
            $media+=video_mp4_metadata($file);$thumb=video_thumbnail_path($name);
            if($thumb){$media['thumbnail']='attach://replacement_thumb';$media['cover']='attach://replacement_thumb';$payload['replacement_thumb']=new CURLFile($thumb,'image/jpeg','preview.jpg');}
        }
        $payload['media']=$media;
    }
    return ['method'=>$method,'payload'=>$payload,'mode'=>$type,'hash'=>hash('sha256',json_encode([$doc,$type,$name],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'length'=>$length];
}
/** Caller holds telegram.lock; edits one known ID, never sends or deletes a message. */
function telegram_update_media(array $p,array $delivery,?callable $transport,string $mode): void {
    global $db,$config;
    $channel=(string)$config['telegram_channel'];$id=(int)$p['id'];$message=(int)$delivery['message_id'];
    if(!$message)throw new RuntimeException('Не сохранён номер старого сообщения. Сначала проверь привязку.');
    $plan=telegram_media_plan($p,$mode); // All local checks precede any state mutation or API request.
    $payload=['chat_id'=>$channel,'message_id'=>$message]+$plan['payload'];
    $db->prepare("INSERT INTO telegram_parts(post_id,channel,kind,ordinal,message_id,state,operation) VALUES(?,?,'text',0,?,'sending','upsert') ON CONFLICT(post_id,channel,kind,ordinal) DO UPDATE SET message_id=excluded.message_id,state='sending',operation='upsert'")->execute([$id,$channel,$message]);
    telegram_state($id,$channel,'sending');
    try{$response=$transport?$transport($plan['method'],$payload):telegram_request($plan['method'],$payload);if(!is_array($response)||!isset($response['ok']))throw new RuntimeException('Invalid response');}
    catch(Throwable $e){$error='Результат обновления неизвестен. Проверь канал; автоматического повтора не будет.'.($e instanceof TelegramTransportFailure?' '.telegram_safe_error($e->getMessage()):'');telegram_state($id,$channel,'unknown',$error);throw new RuntimeException($error);}
    $description=(string)($response['description']??'');
    if(empty($response['ok'])&&!str_contains(strtolower($description),'message is not modified')){
        $error='Telegram отклонил обновление ('.(int)($response['error_code']??0).'): '.telegram_safe_error($description).'. Новая публикация не создавалась.';
        $db->prepare("UPDATE telegram_parts SET state='failed' WHERE post_id=? AND channel=? AND kind='text' AND ordinal=0")->execute([$id,$channel]);
        telegram_state($id,$channel,telegram_invalid_message($description)?'unknown':'failed',$error);throw new RuntimeException($error);
    }
    if(isset($response['result']['message_id'])&&(int)$response['result']['message_id']!==$message){telegram_state($id,$channel,'unknown','Telegram вернул другой номер сообщения. Проверь результат вручную.');throw new RuntimeException('Номер обновлённого сообщения не совпал. Проверь канал.');}
    $db->prepare("UPDATE telegram_parts SET state='sent',content_hash=? WHERE post_id=? AND channel=? AND kind='text' AND ordinal=0")->execute([$plan['hash'],$id,$channel]);
    telegram_state($id,$channel,'sent');telegram_record_sync($id,$channel,'media',$plan['hash'],$plan['mode']);audit('telegram:media-updated',$id);
}

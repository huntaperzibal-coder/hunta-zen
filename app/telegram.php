<?php
declare(strict_types=1);
function telegram_has_footer(array $p): bool {
    $mode=$p['telegram_footer']??'auto';
    return $mode==='on'||($mode==='auto'&&in_array($p['category_slug']??'',['vinnie','gena'],true));
}
function telegram_units(string $text): int { return intdiv(strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8')),2); }
/** Read the article as text plus Telegram entities; offsets are UTF-16, not bytes. */
function telegram_document(array $p): array {
    global $config;
    $slug=(string)($p['category_slug']??'');
    $caption=category_public_caption($slug,'');
    $name=category_public_title($slug,'');
    $icon=match($slug){'vinnie'=>'☯️','gena'=>'⚙️',default=>''};
    $line=$icon!==''?$icon.' '.$name.' · '.$caption:'';
    $text=($line!==''?$line."\n\n":'').$p['title']."\n\n";
    $entities=[['type'=>'bold','offset'=>telegram_units($line!==''?$line."\n\n":''),'length'=>telegram_units($p['title'])]];
    if($line!==''){
        $entities[]=['type'=>'bold','offset'=>telegram_units($icon.' '),'length'=>telegram_units($name)];
        $entities[]=['type'=>'italic','offset'=>telegram_units($icon.' '.$name.' · '),'length'=>telegram_units($caption)];
    }
    if(trim((string)$p['telegram_text'])!=='')$text.=trim($p['telegram_text']);
    else {
        $doc=new DOMDocument('1.0','UTF-8');$prev=libxml_use_internal_errors(true);
        try{$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.body_for_editor($p['body']).'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
        finally{libxml_clear_errors();libxml_use_internal_errors($prev);}
        $walk=function(DOMNode $node,bool $inCode=false,bool $inQuote=false)use(&$walk,&$text,&$entities,$config):void{
            if($node instanceof DOMText){$text.=$node->nodeValue;return;}
            if(!$node instanceof DOMElement)return;
            $tag=strtolower($node->tagName);
            if(in_array($tag,['img','video','script','style'],true))return;
            if($tag==='br'){$text.="\n";return;}
            if($tag==='hr'){$text.="\n\n";return;}
            if($tag==='li')$text.='• ';
            $start=telegram_units($text);
            foreach($node->childNodes as $child)$walk($child,$inCode||in_array($tag,['pre','code'],true),$inQuote||$tag==='blockquote');
            $length=telegram_units($text)-$start;
            $type=$inCode?null:match($tag){'strong','b','h2','h3','h4'=>'bold','em','i'=>'italic','u'=>'underline','s'=>'strikethrough','pre'=>'pre','code'=>'code','blockquote'=>$inQuote?null:'blockquote','a'=>'text_link',default=>null};
            if($type && $length){
                $e=['type'=>$type,'offset'=>$start,'length'=>$length];
                if($type==='text_link'){
                    $url=safe_article_link($node->getAttribute('href'));
                    if($url && str_starts_with($url,'/'))$url=rtrim($config['site_url'],'/').$url;
                    if(!$url || str_starts_with($url,'#'))$type=null;else $e['url']=$url;
                }
                if($type)$entities[]=$e;
            }
            if(in_array($tag,['p','h2','h3','h4','blockquote','pre','figure','ul','ol','table'],true))$text.="\n\n";
            elseif(in_array($tag,['li','tr','figcaption'],true))$text.="\n";
            elseif(in_array($tag,['td','th'],true))$text.="\t";
        };
        foreach($doc->getElementsByTagName('body')->item(0)->childNodes as $node)$walk($node);
    }
    $text=rtrim($text).(telegram_has_footer($p)&&$p['status']==='published'?"\n\n".rtrim($config['site_url'],'/').'/?p='.rawurlencode($p['slug']):'');
    $entities=array_values(array_filter($entities,fn($e)=>$e['length']>0 && $e['offset']+$e['length']<=telegram_units($text)));
    // Telegram forbids bold/italic/link ranges overlapping code/pre ranges.
    $codeRanges=array_filter($entities,fn($e)=>in_array($e['type'],['code','pre'],true));
    $normalized=[];
    foreach($entities as $e){
        $segments=[$e];
        if(!in_array($e['type'],['code','pre'],true))foreach($codeRanges as $code){
            $next=[];
            foreach($segments as $segment){
                $a=$segment['offset'];$b=$a+$segment['length'];$c=$code['offset'];$d=$c+$code['length'];
                if($b<=$c||$a>=$d){$next[]=$segment;continue;}
                if($a<$c){$left=$segment;$left['length']=$c-$a;$next[]=$left;}
                if($b>$d){$right=$segment;$right['offset']=$d;$right['length']=$b-$d;$next[]=$right;}
            }
            $segments=$next;
        }
        array_push($normalized,...$segments);
    }
    $entities=$normalized;
    usort($entities,fn($a,$b)=>($a['offset']<=>$b['offset'])?:($b['length']<=>$a['length']));
    return ['text'=>$text,'entities'=>$entities];
}
function telegram_text(array $p): string { return telegram_document($p)['text']; }
function telegram_chunks(array $document,int $limit=3900): array {
    $remaining=$document['text'];$offset=0;$chunks=[];
    while($remaining!==''){
        $piece=mb_substr($remaining,0,$limit);
        while(telegram_units($piece)>$limit)$piece=mb_substr($piece,0,mb_strlen($piece)-1);
        if(mb_strlen($piece)<mb_strlen($remaining)){
            foreach(["\n\n","\n",' '] as $separator){
                $pos=mb_strrpos($piece,$separator);
                if($pos!==false && $pos>mb_strlen($piece)/2){$piece=mb_substr($piece,0,$pos+mb_strlen($separator));break;}
            }
        }
        $length=telegram_units($piece);$entities=[];
        foreach($document['entities'] as $entity){
            $start=max($offset,$entity['offset']);$end=min($offset+$length,$entity['offset']+$entity['length']);
            if($end>$start){$entity['offset']=$start-$offset;$entity['length']=$end-$start;$entities[]=$entity;}
        }
        $chunks[]=['text'=>$piece,'entities'=>$entities];$offset+=$length;$remaining=mb_substr($remaining,mb_strlen($piece));
    }
    return $chunks;
}
/** Telegram Bot API Rich Messages: header, image and story in one message. */
function telegram_rich_body(string $body, array &$videos=[]): string {
    global $config;
    $doc=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    try{$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.body_for_editor($body).'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
    finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $walk=function(DOMNode $node)use(&$walk,$config,&$videos):string{
        if($node instanceof DOMText)return h($node->nodeValue);
        if(!$node instanceof DOMElement)return '';
        $tag=strtolower($node->tagName);
        if($tag==='video'){
            $name=article_video_name($node->getAttribute('src'));if(!$name)return '';
            if(!isset($videos[$name]))$videos[$name]='video'.count($videos);
            return '<video src="tg://video?id='.$videos[$name].'"></video>';
        }
        if(in_array($tag,['img','script','style'],true))return '';
        $content='';foreach($node->childNodes as $child)$content.=$walk($child);
        if($tag==='a'){
            $url=safe_article_link($node->getAttribute('href'));
            if(!$url||str_starts_with($url,'#'))return $content;
            if(str_starts_with($url,'/'))$url=rtrim($config['site_url'],'/').$url;
            return '<a href="'.h($url).'">'.$content.'</a>';
        }
        if($tag==='figcaption')$tag='p';
        if(!in_array($tag,['p','h2','h3','h4','strong','b','em','i','u','s','blockquote','pre','code','ul','ol','li','table','tr','td','th','br','hr','sub','sup'],true))return $content;
        if(in_array($tag,['br','hr'],true))return '<'.$tag.'>';
        $attrs='';if(in_array($tag,['td','th'],true))foreach(['colspan','rowspan'] as $key){$value=(int)$node->getAttribute($key);if($value>1 && $value<=100)$attrs.=' '.$key.'="'.$value.'"';}
        return '<'.$tag.$attrs.'>'.$content.'</'.$tag.'>';
    };
    $html='';foreach($doc->getElementsByTagName('body')->item(0)->childNodes as $node)$html.=$walk($node);
    return $html;
}
function telegram_plan(array $p): array {
    global $storage,$config;
    $photo=(string)$p['cover'];
    if($photo==='' && preg_match('~(?:file=|\()([a-f0-9]{32}\.(?:jpg|png|webp))~',body_for_editor($p['body']),$m))$photo=$m[1];
    if($photo!==''){
        if(!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$photo)||!is_file($storage.'/photos/'.$photo))throw new RuntimeException('Иллюстрация недоступна. Проверь фотографию перед отправкой.');
        if(filesize($storage.'/photos/'.$photo)>10*1024*1024)throw new RuntimeException('Иллюстрация больше 10 МБ. Уменьши её перед отправкой в Telegram.');
    }
    $slug=(string)($p['category_slug']??'');$icon=match($slug){'vinnie'=>'☯️','gena'=>'⚙️',default=>''};
    $html=$icon!==''?'<p>'.$icon.' <b>'.h(category_public_title($slug,'')).'</b> · <i>'.h(category_public_caption($slug,'')).'</i></p>':'';
    if($photo!=='')$html.='<img src="tg://photo?id=illustration">';
    $html.='<p><b>'.h($p['title']).'</b></p>';
    $videos=[];$story=telegram_rich_body($p['body'],$videos);
    $html.=trim((string)$p['telegram_text'])!==''?'<p>'.str_replace("\n",'<br>',h(trim($p['telegram_text']))).'</p>':$story;
    if(trim((string)$p['telegram_text'])!=='')foreach($videos as $name=>$key)$html.='<video src="tg://video?id='.$key.'"></video>';
    $url=rtrim($config['site_url'],'/').'/?p='.rawurlencode($p['slug']);
    if(telegram_has_footer($p))$html.='<blockquote><p><b>Гена и Винни</b></p>'.($p['status']==='published'?'<p><a href="'.h($url).'">🌐 Читать на сайте</a></p>':'').'</blockquote>';
    // Reject oversized messages before any network call; never truncate or split implicitly.
    if(mb_strlen(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))>32768)throw new RuntimeException('Рассказ больше лимита одного составного сообщения Telegram (32768 символов). Сократи текст для канала.');
    $doc=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    try{$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}
    finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $blocks=0;$check=function(DOMNode $node,int $depth=0)use(&$check,&$blocks):void{
        if(!$node instanceof DOMElement)return;
        if($depth>16)throw new RuntimeException('Слишком много вложенных уровней форматирования для Telegram.');
        if(in_array(strtolower($node->tagName),['p','h2','h3','h4','pre','blockquote','ul','ol','li','table','tr','img','video','hr'],true))$blocks++;
        foreach($node->childNodes as $child)$check($child,$depth+1);
    };
    foreach($doc->getElementsByTagName('body')->item(0)->childNodes as $node)$check($node);
    if($blocks>500)throw new RuntimeException('Слишком много абзацев и блоков для одного сообщения Telegram (лимит 500).');
    $rich=['html'=>$html];
    if($photo!=='')$rich['media']=[['id'=>'illustration','media'=>['type'=>'photo','media'=>'attach://picture']]];
    if(count($videos)+($photo!==''?1:0)>50)throw new RuntimeException('В одном сообщении Telegram может быть не больше 50 вложений.');
    foreach($videos as $name=>$key){
        $file=$storage.'/photos/'.$name;
        if(!is_file($file))throw new RuntimeException('Видео недоступно. Проверь его перед отправкой.');
        if(filesize($file)>50*1024*1024)throw new RuntimeException('Для сайта можно до 200 МБ, но Telegram принимает через бота видео до 50 МБ. Для отправки в канал уменьши ролик.');
        $media=['type'=>'video','media'=>'attach://'.$key]+video_mp4_metadata($file);
        if(video_thumbnail_path($name)){$media['thumbnail']='attach://'.$key.'_thumb';$media['cover']='attach://'.$key.'_thumb';}
        $rich['media'][]=['id'=>$key,'media'=>$media];
    }
    return [['kind'=>'text','ordinal'=>0,'rich_message'=>$rich,'photo'=>$photo,'videos'=>$videos]];
}
/** Only fixed transport metadata is exposed; never a request URL or raw response. */
class TelegramTransportFailure extends RuntimeException {}
function telegram_safe_error(string $message): string {
    global $config;
    $token=(string)($config['telegram_token']??'');
    if($token!=='')$message=str_replace($token,'[токен скрыт]',$message);
    $message=preg_replace('~https?://[^\s<>]+~iu','[адрес скрыт]',$message)??'';
    $message=preg_replace('~[0-9]{5,}:[A-Za-z0-9_-]{20,}~','[токен скрыт]',$message)??'';
    return mb_substr(trim(preg_replace('/[\x00-\x20\x7f]+/u',' ',strip_tags($message))??''),0,800);
}
function telegram_transport_detail(string $method,int $errno,int $status,float $seconds): string {
    $reason=match($errno){5,6=>'не удалось определить адрес сервера',7=>'не удалось подключиться',28=>'истекло время ожидания',35=>'ошибка TLS',51,60=>'ошибка сертификата',52=>'пустой ответ сервера',55,56=>'соединение прервано',0=>$status>=500?'ошибка сервера Telegram':'ответ Telegram не распознан',default=>'сбой соединения'};
    $method=preg_replace('/[^A-Za-z]/','',$method)??'';
    return $method.': '.$reason.'; cURL '.$errno.'; HTTP '.$status.'; '.number_format($seconds,1,'.','').' с.';
}
/** Raw response is never logged: URL contains the bot token. */
function telegram_request(string $method,array $payload): array {
    global $config;
    static $lastRequest=0.0;
    $pause=1.05-(microtime(true)-$lastRequest);if($pause>0)usleep((int)($pause*1000000));$lastRequest=microtime(true);
    $curl=curl_init('https://api.telegram.org/bot'.$config['telegram_token'].'/'.$method);
    foreach($payload as $k=>$value)if(is_array($value))$payload[$k]=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>(count(array_filter($payload,fn($v)=>$v instanceof CURLFile))?180:30),CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
    $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);$errno=curl_errno($curl);$seconds=(float)curl_getinfo($curl,CURLINFO_TOTAL_TIME);curl_close($curl);
    if($raw===false || $status>=500 || !$status)throw new TelegramTransportFailure(telegram_transport_detail($method,$errno,$status,$seconds));
    $response=json_decode($raw,true);
    if(!is_array($response)||!isset($response['ok']))throw new TelegramTransportFailure(telegram_transport_detail($method,$errno,$status,$seconds));
    return $response;
}
function telegram_state(int $id,string $channel,string $state,string $error=''): void {
    global $db;$db->prepare('UPDATE telegram_deliveries SET state=?,error=?,updated_at=? WHERE post_id=? AND channel=?')->execute([$state,$error,now(),$id,$channel]);
}
/** These edit failures require an explicit channel check, never a blind resend. */
function telegram_invalid_message(string $error): bool {
    return preg_match('/MESSAGE_ID_INVALID|message to edit not found/i',$error)===1;
}
function telegram_send(int $id,?callable $transport=null,?array $snapshot=null): void {
    global $config,$db,$storage;
    if(empty($config['telegram_token'])||empty($config['telegram_channel']))throw new RuntimeException('Подключи бота и канал в разделе Telegram.');
    $lock=fopen($storage.'/telegram.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Уже идёт отправка. Подожди.');
    try{
        $current=post($id);
        if(trim($current['title'])===''||trim(body_plain($current['body']))==='')throw new RuntimeException('Для отправки нужны заголовок и текст.');
        $p=$snapshot??$current;$p['status']=$current['status'];$p['slug']=$current['slug'];$plan=telegram_plan($p);$channel=$config['telegram_channel'];
        $q=$db->prepare('SELECT * FROM telegram_deliveries WHERE post_id=? AND channel=?');$q->execute([$id,$channel]);$delivery=$q->fetch();
        if($delivery && (in_array($delivery['state'],['sending','unknown'],true)||telegram_invalid_message($delivery['error'])))throw new RuntimeException('Результат прошлой отправки неизвестен. Проверь канал и укажи результат ниже.');
        // Adopt the original single text delivery without duplicating it after an upgrade.
        if(!empty($delivery['message_id']))$db->prepare("INSERT OR IGNORE INTO telegram_parts(post_id,channel,kind,ordinal,message_id,state) VALUES(?,?,'text',0,?,'sent')")->execute([$id,$channel,$delivery['message_id']]);
        $q=$db->prepare('SELECT * FROM telegram_parts WHERE post_id=? AND channel=?');$q->execute([$id,$channel]);$old=[];
        foreach($q->fetchAll() as $part)$old[$part['kind'].':'.$part['ordinal']]=$part;
        $db->prepare("INSERT INTO telegram_deliveries(post_id,channel,state,updated_at) VALUES(?,?,'sending',?) ON CONFLICT(post_id,channel) DO UPDATE SET state='sending',error='',updated_at=excluded.updated_at")->execute([$id,$channel,now()]);
        $operations=[];$keys=[];
        foreach($plan as $part){$key=$part['kind'].':'.$part['ordinal'];$keys[]=$key;$operations[]=['part'=>$part,'old'=>$old[$key]??null,'delete'=>false];}
        foreach($old as $key=>$part)if(!in_array($key,$keys,true)&&$part['message_id'])$operations[]=['part'=>$part,'old'=>$part,'delete'=>true];
        foreach($operations as $op){
            $part=$op['part'];$previous=$op['old'];$message=$previous['message_id']??null;$delete=$op['delete'];
            $hash=hash('sha256',json_encode($part,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            // Do not skip edits: Telegram may already have the same content; it confirms that safely.
            $payload=['chat_id'=>$channel];$method='sendMessage';
            if($delete){$method='deleteMessage';$payload['message_id']=(int)$message;}
            else{
                $method=$message?'editMessageText':'sendRichMessage';
                $payload['rich_message']=$part['rich_message'];
                if($message)$payload['message_id']=(int)$message;
                if($part['photo']!=='')$payload['picture']=new CURLFile($storage.'/photos/'.$part['photo'],mime_content_type($storage.'/photos/'.$part['photo']),'illustration.'.pathinfo($part['photo'],PATHINFO_EXTENSION));
                foreach($part['videos']??[] as $name=>$key){
                    $payload[$key]=new CURLFile($storage.'/photos/'.$name,'video/mp4',$key.'.mp4');
                    $thumb=video_thumbnail_path($name);if($thumb)$payload[$key.'_thumb']=new CURLFile($thumb,'image/jpeg',$key.'.jpg');
                }
            }
            $db->prepare("INSERT INTO telegram_parts(post_id,channel,kind,ordinal,message_id,state,operation) VALUES(?,?,?,?,?,'sending',?) ON CONFLICT(post_id,channel,kind,ordinal) DO UPDATE SET state='sending',operation=excluded.operation")->execute([$id,$channel,$part['kind'],$part['ordinal'],$message,$delete?'delete':'upsert']);
            try{$response=$transport?$transport($method,$payload):telegram_request($method,$payload);if(!is_array($response)||!isset($response['ok']))throw new RuntimeException('Invalid response');}
            catch(Throwable $e){
                $detail=$e instanceof TelegramTransportFailure?' Причина: '.telegram_safe_error($e->getMessage()):'';
                $error='Результат отправки неизвестен. Проверь канал; автоматического повтора не будет.'.$detail;
                telegram_state($id,$channel,'unknown',$error);throw new RuntimeException($error);
            }
            if(!$response['ok']){
                $description=(string)($response['description']??'');
                $safeRepeat=($message && str_contains($description,'message is not modified'))||($delete && str_contains($description,'message to delete not found'));
                if(!$safeRepeat){
                    $db->prepare("UPDATE telegram_parts SET state='failed' WHERE post_id=? AND channel=? AND kind=? AND ordinal=?")->execute([$id,$channel,$part['kind'],$part['ordinal']]);
                    $reason=mb_substr(str_replace((string)$config['telegram_token'],'[токен скрыт]',strip_tags($description)),0,300);
                    $error='Telegram отклонил запрос ('.(int)($response['error_code']??0).')'.($reason!==''?': '.$reason:'. Проверь права бота и канал.');
                    if(!$delete&&$message&&telegram_invalid_message($description)){
                        $error.=' Не удалось обновить сообщение №'.(int)$message.'. Проверь канал и исправь привязку ниже.';
                        telegram_state($id,$channel,'unknown',$error);
                    }else telegram_state($id,$channel,'failed',$error);
                    throw new RuntimeException($error.' Уже отправленные сообщения сохранены.');
                }
            }
            $resultId=$response['result']['message_id']??$message;
            if(!$delete && !$resultId){telegram_state($id,$channel,'unknown','Не получен номер сообщения.');throw new RuntimeException('Не получен номер сообщения. Проверь канал.');}
            if($delete)$db->prepare('DELETE FROM telegram_parts WHERE post_id=? AND channel=? AND kind=? AND ordinal=?')->execute([$id,$channel,$part['kind'],$part['ordinal']]);
            else $db->prepare("UPDATE telegram_parts SET state='sent',message_id=?,content_hash=? WHERE post_id=? AND channel=? AND kind=? AND ordinal=?")->execute([$resultId,$hash,$id,$channel,$part['kind'],$part['ordinal']]);
        }
        // One rich message uses the text slot; legacy photo slots are removed after confirmation.
        $q=$db->prepare("SELECT message_id FROM telegram_parts WHERE post_id=? AND channel=? AND kind='text' AND ordinal=0");$q->execute([$id,$channel]);
        $db->prepare("UPDATE telegram_deliveries SET state='sent',message_id=?,error='',updated_at=? WHERE post_id=? AND channel=?")->execute([$q->fetchColumn()?:null,now(),$id,$channel]);
        audit($delivery?'telegram:update':'telegram:send',$id);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function telegram_resolve(int $id,int $message): void {
    global $db,$config,$storage;
    $lock=fopen($storage.'/telegram.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Отправка ещё идёт. Подожди.');
    try{
        $channel=$config['telegram_channel'];$q=$db->prepare("SELECT * FROM telegram_parts WHERE post_id=? AND channel=? AND state IN ('sending','failed') ORDER BY CASE state WHEN 'sending' THEN 0 ELSE 1 END,kind DESC,ordinal LIMIT 1");$q->execute([$id,$channel]);$part=$q->fetch();
        if($part){
            if($part['operation']==='delete'){
                if($message!==0)throw new RuntimeException('Для удаления проверь канал: укажи 0, если сообщение удалено.');
                $db->prepare('DELETE FROM telegram_parts WHERE post_id=? AND channel=? AND kind=? AND ordinal=?')->execute([$id,$channel,$part['kind'],$part['ordinal']]);
            }else $db->prepare("UPDATE telegram_parts SET message_id=?,state='failed' WHERE post_id=? AND channel=? AND kind=? AND ordinal=?")->execute([$message?:null,$id,$channel,$part['kind'],$part['ordinal']]);
            if($part['kind']==='text'&&(int)$part['ordinal']===0)$db->prepare('UPDATE telegram_deliveries SET message_id=? WHERE post_id=? AND channel=?')->execute([$message?:null,$id,$channel]);
            telegram_state($id,$channel,'failed','Проверено вручную. Нажми отправку, чтобы завершить все части.');
        }else{
            $q=$db->prepare('SELECT 1 FROM telegram_parts WHERE post_id=? AND channel=? LIMIT 1');$q->execute([$id,$channel]);
            if($q->fetchColumn())telegram_state($id,$channel,'failed','Проверено. Нажми отправку, чтобы завершить публикацию.');
            else $db->prepare("UPDATE telegram_deliveries SET state=?,message_id=?,updated_at=? WHERE post_id=? AND channel=? AND state IN ('sending','unknown')")->execute([$message?'sent':'failed',$message?:null,now(),$id,$channel]);
        }
        audit('telegram:manual-check',$id);
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}

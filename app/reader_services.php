<?php
declare(strict_types=1);
function reader_support_url(string $url): string {
    $parts=parse_url($url);
    return filter_var($url,FILTER_VALIDATE_URL)&&is_array($parts)&&($parts['scheme']??'')==='https'&&!isset($parts['user'])&&!isset($parts['pass'])?$url:'';
}
function reader_service_links(): string {
    $telegram=setting('telegram_url');$html='';
    if(preg_match('~^https://t\.me/[A-Za-z0-9_]+$~D',$telegram))$html.='<a href="'.h($telegram).'" target="_blank" rel="noopener">'.dao_icon('telegram').'<span>Читать в Telegram</span><span aria-hidden="true">↗</span></a>';
    return $html.'<a href="/support.php">'.dao_icon('heart').'<span>Поддержать</span></a><a href="/feedback.php">'.dao_icon('mail').'<span>Написать автору</span></a>';
}
function reader_service_dialog(): string {
    return '<dialog id="reader-services" class="reader-services" aria-labelledby="reader-services-title"><div class="reader-services-heading"><h2 id="reader-services-title">Гена и Винни</h2><button type="button" data-service-close aria-label="Закрыть меню">'.dao_icon('close').'</button></div><nav class="reader-service-links" aria-label="Сервисы сайта">'.reader_service_links().'</nav></dialog><script src="/assets/js/reader-services.js?v=1" defer></script>';
}
/** Public reader feedback is intentionally available without an author account. */
function reader_feedback_save(array $input,string $address): void {
    global $db,$config;
    $message=trim((string)($input['message']??''));$contact=trim((string)($input['contact']??''));$nonce=(string)($input['submission']??'');
    if($message===''||mb_strlen($message)>5000||mb_strlen($contact)>250||!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new RuntimeException('Напиши сообщение до 5000 символов. Контакт для ответа — до 250 символов.');
    if(trim((string)($input['website']??''))!=='')return;
    $client=hash_hmac('sha256',$address,(string)($config['password_hash']??ROOT));
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT 1 FROM reader_feedback WHERE submission=?');$q->execute([$nonce]);
        if($q->fetchColumn()){$db->commit();return;}
        $q=$db->prepare('SELECT 1 FROM reader_feedback WHERE client_key=? AND created_at>=? LIMIT 1');$q->execute([$client,time()-60]);
        if($q->fetchColumn())throw new RuntimeException('Сообщение уже принято недавно. Подожди минуту перед следующим.');
        $db->prepare('INSERT INTO reader_feedback(submission,message,contact,client_key,created_at) VALUES(?,?,?,?,?)')->execute([$nonce,$message,$contact,$client,time()]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

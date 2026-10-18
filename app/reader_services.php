<?php
declare(strict_types=1);
function reader_support_url(string $url): string {
    $parts=parse_url($url);
    return filter_var($url,FILTER_VALIDATE_URL)&&is_array($parts)&&($parts['scheme']??'')==='https'&&!isset($parts['user'])&&!isset($parts['pass'])?$url:'';
}
function reader_service_links(): string {
    $telegram=setting('telegram_url');$html='';
    if(preg_match('~^https://t\.me/[A-Za-z0-9_]+$~D',$telegram))$html.='<a href="'.h($telegram).'" target="_blank" rel="noopener">'.dao_icon('telegram').'<span>Читать в Telegram</span></a>';
    return $html.'<a href="/support.php">'.dao_icon('heart').'<span>Поддержать</span></a><a href="/feedback.php">'.dao_icon('mail').'<span>Познакомиться</span></a>';
}
function reader_service_dialog(): string {
    return '<dialog id="reader-services" class="reader-services" aria-labelledby="reader-services-title"><div class="reader-services-heading"><h2 id="reader-services-title">Гена и Винни</h2><button type="button" data-service-close aria-label="Закрыть меню">'.dao_icon('close').'</button></div><nav class="reader-service-links" aria-label="Сервисы сайта">'.reader_service_links().'</nav></dialog><script src="/assets/js/reader-services.js?v=1" defer></script>';
}
/** Guest form is an intentional exception: no author login, but session-bound CSRF and consent. */
function reader_feedback_secret(): string {
    global $config,$db;
    $secret=(string)($config['password_hash']??'');
    if($secret==='')$secret=(string)$db->query('SELECT password_hash FROM orda_users ORDER BY id LIMIT 1')->fetchColumn();
    if($secret==='')throw new RuntimeException('Форма ещё не настроена.');
    return hash('sha256',$secret.'reader-feedback-v2');
}
function reader_feedback_issue(): array {
    session_open();$nonce=bin2hex(random_bytes(16));$a=random_int(2,8);$b=random_int(1,8);
    $forms=$_SESSION['reader_forms']??[];foreach($forms as $key=>$value)if($value['issued']<time()-3600)unset($forms[$key]);
    while(count($forms)>=5)array_shift($forms);
    $form=['issued'=>time(),'question'=>"$a + $b",'answer'=>$a+$b,'digest'=>hash('sha256',reader_consent_text())];$forms[$nonce]=$form;$_SESSION['reader_forms']=$forms;
    return ['nonce'=>$nonce]+$form;
}
function reader_feedback_cleanup(): void {
    global $db;
    $db->prepare('DELETE FROM reader_feedback WHERE created_at<?')->execute([time()-90*86400]);
    $db->prepare('DELETE FROM reader_feedback_attempts WHERE bucket<?')->execute([intdiv(time()-86400,3600)]);
}
function reader_feedback_save(array $input,string $address): void {
    global $db;
    if(setting('feedback_enabled')!=='1'||!reader_legal_ready())throw new RuntimeException('Приём сообщений пока не включён.');
    $nonce=(string)($input['submission']??'');$form=$_SESSION['reader_forms'][$nonce]??null;
    if(!$form||!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new RuntimeException('Обнови страницу и заполни форму заново.');
    $q=$db->prepare('SELECT 1 FROM reader_feedback WHERE submission=?');$q->execute([$nonce]);if($q->fetchColumn())return;
    $client=hash_hmac('sha256',$address,reader_feedback_secret());$bucket=intdiv(time(),3600);
    // Count failed attempts too: a fresh session cannot reset the IP-hour limit.
    foreach(['global'=>300,$client=>20] as $key=>$max){
        $db->prepare('INSERT INTO reader_feedback_attempts(client_key,bucket,attempts) VALUES(?,?,1) ON CONFLICT(client_key,bucket) DO UPDATE SET attempts=attempts+1')->execute([$key,$bucket]);
        $q=$db->prepare('SELECT attempts FROM reader_feedback_attempts WHERE client_key=? AND bucket=?');$q->execute([$key,$bucket]);
        if((int)$q->fetchColumn()>$max)throw new RuntimeException('Слишком много попыток. Попробуй позже.');
    }
    if(trim((string)($input['website']??''))!=='')return;
    if(time()-$form['issued']<3)throw new RuntimeException('Подожди несколько секунд перед отправкой.');
    if(time()-$form['issued']>3600)throw new RuntimeException('Форма устарела. Обнови страницу.');
    if(!preg_match('/^[0-9]{1,2}$/D',trim((string)($input['answer']??'')))||(int)$input['answer']!==$form['answer'])throw new RuntimeException('Проверь ответ на простой вопрос.');
    $digest=hash('sha256',reader_consent_text());
    if(($input['consent']??'')!=='1'||!hash_equals($digest,(string)($input['consent_digest']??''))||!hash_equals($digest,$form['digest']))throw new RuntimeException('Нужно отдельное согласие на обработку сообщения. Если текст согласия изменился, обнови страницу.');
    $message=trim((string)($input['message']??''));$contact=trim((string)($input['contact']??''));
    if($message===''||mb_strlen($message)>5000||mb_strlen($contact)>250)throw new RuntimeException('Напиши сообщение до 5000 символов. Контакт для ответа — до 250 символов.');
    if(preg_match_all('~https?://|www\.~iu',$message)>3)throw new RuntimeException('В одном сообщении можно оставить не больше трёх ссылок.');
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT count(*) FROM reader_feedback WHERE client_key=? AND created_at>=?');$q->execute([$client,time()-3600]);$hour=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT count(*) FROM reader_feedback WHERE client_key=? AND created_at>=?');$q->execute([$client,time()-86400]);$day=(int)$q->fetchColumn();
        $q=$db->prepare('SELECT 1 FROM reader_feedback WHERE client_key=? AND created_at>=?');$q->execute([$client,time()-60]);
        if($q->fetchColumn()||$hour>=5||$day>=10)throw new RuntimeException('Сообщение уже принято недавно. Подожди перед следующим.');
        $q=$db->prepare('SELECT count(*) FROM reader_feedback WHERE created_at>=?');$q->execute([time()-3600]);if((int)$q->fetchColumn()>=50)throw new RuntimeException('Форма временно занята. Попробуй позже.');
        $q=$db->prepare('SELECT 1 FROM reader_feedback WHERE message=? AND created_at>=?');$q->execute([$message,time()-300]);if($q->fetchColumn())throw new RuntimeException('Такое сообщение уже принято.');
        $db->prepare('INSERT INTO reader_feedback(submission,message,contact,client_key,created_at) VALUES(?,?,?,?,?)')->execute([$nonce,$message,$contact,$client,time()]);$id=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO reader_feedback_consents(feedback_id,version,accepted_at,document_hash,document) VALUES(?,?,?,?,?)')->execute([$id,READER_LEGAL_VERSION,time(),$digest,reader_consent_text()]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

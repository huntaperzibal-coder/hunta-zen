<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(str_starts_with($config['site_url'],'https://')&&($_SERVER['HTTPS']??'')!=='on'&&(int)($_SERVER['SERVER_PORT']??0)!==443&&!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){header('Location: '.rtrim($config['site_url'],'/').'/feedback.php',true,303);exit;}
header('Cache-Control: no-store');session_open();$error='';$enabled=setting('feedback_enabled')==='1'&&reader_legal_ready();
if($_SERVER['REQUEST_METHOD']==='POST'){
    if((int)($_SERVER['CONTENT_LENGTH']??0)>32768){http_response_code(413);exit('Сообщение слишком большое.');}
    verify_csrf();
    try{reader_feedback_save($_POST,(string)($_SERVER['REMOTE_ADDR']??''));redirect('/feedback.php?sent=1#message');}
    catch(PDOException $e){http_response_code(503);$error='Не удалось сохранить сообщение. Попробуй позже.';error_log('Reader feedback storage failure');}
    catch(RuntimeException $e){http_response_code(422);$error=$e->getMessage();}
}
page_start('Познакомиться — '.setting('title'));
echo '<section class="reader-service-page author-page"><a class="back" href="/">← К историям</a><p class="service-eyebrow">Человек за историями</p><h1>Познакомиться</h1>';
$photo=reader_public_photo('author_photo');
if($photo!=='')echo '<figure class="author-portrait"><img src="'.h(photo_url($photo)).'" alt="'.h(setting('author_name','Hunta')).'" decoding="async"></figure>';
echo '<div class="author-intro"><h2>'.h(setting('author_name','Hunta')).'</h2><p>'.nl2br(h(setting('author_bio','Я Hunta, проводник в горах Алтая. Здесь — мои истории и разговоры Гены и Винни: о логике, чувствах и том, как мы устраиваем себе жизнь.'))).'</p></div><section class="author-message" id="message"><h2>Оставить пару слов</h2><p class="hint">Мысль, вопрос или просто привет. Это личное сообщение — в ленту оно не попадёт.</p>';
if(isset($_GET['sent']))echo '<p class="feedback-success" role="status">Спасибо! Сообщение сохранено для автора.</p><a href="/feedback.php#message">Написать ещё</a>';
elseif(!$enabled){echo '<p class="hint">Форма скоро откроется. Сейчас автор готовит сведения об обработке данных.</p>';if(authenticated())echo '<p><a href="/admin.php?view=author">Заполнить сведения и включить форму →</a></p>';}
else{
    if($error!=='')echo '<p class="feedback-error" role="alert">'.h($error).'</p>';
    $nonce=(string)($_POST['submission']??'');$existing=$_SESSION['reader_forms'][$nonce]??null;
    $form=$existing&&$existing['issued']>=time()-3600&&hash_equals($existing['digest'],hash('sha256',reader_consent_text()))?['nonce'=>$nonce]+$existing:reader_feedback_issue();
    echo '<form method="post" action="/feedback.php#message">'.csrf().'<input type="hidden" name="submission" value="'.h($form['nonce']).'"><input type="hidden" name="consent_digest" value="'.h($form['digest']).'"><label for="feedback-message">Сообщение</label><textarea id="feedback-message" name="message" rows="6" maxlength="5000" required placeholder="Что хочется сказать?">'.h((string)($_POST['message']??'')).'</textarea><label for="feedback-contact">Контакт для ответа · необязательно</label><input id="feedback-contact" name="contact" maxlength="250" value="'.h((string)($_POST['contact']??'')).'" placeholder="@telegram или почта"><div class="reader-feedback-trap" aria-hidden="true"><label>Оставь пустым<input name="website" tabindex="-1" autocomplete="off"></label></div><div class="feedback-check"><label for="feedback-answer">Проверка от спама: сколько будет '.h($form['question']).'?</label><input id="feedback-answer" name="answer" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" autocomplete="off" required aria-describedby="feedback-check-note"><small id="feedback-check-note">Простой вопрос вместо капчи со стороннего сайта.</small></div><label class="feedback-consent"><input type="checkbox" name="consent" value="1" required><span>Даю <a href="/consent.php" target="_blank" rel="noopener">согласие на обработку сообщения и указанного контакта</a> для рассмотрения обращения и ответа.</span></label><p class="hint feedback-privacy"><a href="/privacy.php" target="_blank" rel="noopener">Политика обработки данных</a>. Контакт можно не оставлять. Рабочие сообщения хранятся до 90 дней.</p><button class="reader-primary-button" type="submit">Отправить сообщение '.dao_icon('mail').'</button></form>';
}
echo '</section></section>';page_end();

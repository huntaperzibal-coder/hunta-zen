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
echo '<section class="reader-service-page author-page"><nav class="reader-page-nav" aria-label="Навигация по разделам"><a class="back" href="/">← К историям</a><a href="/support.php">'.dao_icon('heart').'<span>Поддержать</span></a></nav><h1>Познакомиться</h1>';
$photo=reader_public_photo('author_photo');
if($photo!=='')echo '<figure class="author-portrait"><a class="story-image-link" data-lightbox-image href="'.h(photo_url($photo)).'" aria-label="Открыть фото автора полностью"><img src="'.h(photo_url($photo)).'" alt="'.h(setting('author_name','Hunta')).'" decoding="async"></a></figure>';
echo '<section class="author-message" id="message"><h2>Обратная связь</h2>';
if(isset($_GET['sent']))echo '<p class="feedback-success" role="status">Спасибо! Сообщение сохранено для автора.</p><a href="/feedback.php#message">Написать ещё</a>';
elseif(!$enabled){echo '<p class="hint">Форма скоро откроется. Сейчас автор готовит сведения об обработке данных.</p>';if(authenticated())echo '<p><a href="/admin.php?view=author">Заполнить сведения и включить форму →</a></p>';}
else{
    if($error!=='')echo '<p class="feedback-error" role="alert">'.h($error).'</p>';
    $nonce=(string)($_POST['submission']??'');$existing=$_SESSION['reader_forms'][$nonce]??null;
    $form=$existing&&$existing['issued']>=time()-3600&&hash_equals($existing['digest'],hash('sha256',reader_consent_text()))?['nonce'=>$nonce]+$existing:reader_feedback_issue();
    echo '<form method="post" action="/feedback.php#message">'.csrf().'<input type="hidden" name="submission" value="'.h($form['nonce']).'"><input type="hidden" name="consent_digest" value="'.h($form['digest']).'"><label for="feedback-message">Сообщение</label><textarea id="feedback-message" name="message" rows="4" maxlength="5000" required placeholder="Твоё сообщение…">'.h((string)($_POST['message']??'')).'</textarea><label for="feedback-contact">Контакт для ответа · необязательно</label><input id="feedback-contact" name="contact" maxlength="250" value="'.h((string)($_POST['contact']??'')).'" placeholder="@telegram или почта"><div class="reader-feedback-trap" aria-hidden="true"><label>Оставь пустым<input name="website" tabindex="-1" autocomplete="off"></label></div><div class="feedback-check"><label for="feedback-answer">Сколько будет '.h($form['question']).'?</label><input id="feedback-answer" name="answer" inputmode="numeric" pattern="[0-9]{1,2}" maxlength="2" autocomplete="off" required></div><label class="feedback-consent"><input type="checkbox" name="consent" value="1" required><span>Даю <a href="/consent.php" target="_blank" rel="noopener">согласие на обработку сообщения и указанного контакта</a> для ответа.</span></label><p class="hint feedback-privacy"><a href="/privacy.php" target="_blank" rel="noopener">Политика обработки данных</a>. </p><button class="reader-primary-button" type="submit">Отправить '.dao_icon('mail').'</button></form>';
}
echo '</section></section>';
if($photo!=='')echo '<dialog id="reader-lightbox" class="reader-lightbox" aria-label="Фото автора"><button type="button" class="lightbox-close" aria-label="Закрыть изображение">×</button><button type="button" class="lightbox-prev" aria-label="Предыдущее изображение" hidden>‹</button><div class="lightbox-stage"><img alt=""><p class="lightbox-caption"></p></div><button type="button" class="lightbox-next" aria-label="Следующее изображение" hidden>›</button></dialog><script src="/assets/js/reader-actions.js?v=4" defer></script>';
page_end();

<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Cache-Control: no-store');session_open();$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    if((int)($_SERVER['CONTENT_LENGTH']??0)>32768){http_response_code(413);exit('Сообщение слишком большое.');}
    verify_csrf();
    try{reader_feedback_save($_POST,(string)($_SERVER['REMOTE_ADDR']??''));redirect('/feedback.php?sent=1');}
    catch(PDOException $e){http_response_code(503);$error='Не удалось сохранить сообщение. Попробуй позже.';error_log('Reader feedback storage failure');}
    catch(RuntimeException $e){http_response_code(422);$error=$e->getMessage();}
}
page_start('Написать автору — '.setting('title'));
echo '<section class="reader-service-page"><a class="back" href="/">← К историям</a><h1>Написать автору</h1>';
if(isset($_GET['sent']))echo '<p role="status">Спасибо! Сообщение сохранено для автора.</p><a href="/">Вернуться к историям</a>';
else{
    if($error!=='')echo '<p role="alert">'.h($error).'</p>';
    echo '<form method="post">'.csrf().'<input type="hidden" name="submission" value="'.h((string)($_POST['submission']??bin2hex(random_bytes(16)))).'"><label for="feedback-message">Сообщение</label><textarea id="feedback-message" name="message" rows="7" maxlength="5000" required placeholder="Мысль, вопрос или просто привет…">'.h((string)($_POST['message']??'')).'</textarea><label for="feedback-contact">Контакт для ответа · необязательно</label><input id="feedback-contact" name="contact" maxlength="250" value="'.h((string)($_POST['contact']??'')).'" placeholder="@telegram или почта"><div class="reader-feedback-trap" aria-hidden="true"><label>Оставь пустым<input name="website" tabindex="-1" autocomplete="off"></label></div><button type="submit">Отправить</button><p class="hint">Сообщение и контакт увидит только автор. Не указывай контакт, если ответ не нужен.</p></form>';
}
echo '</section>';page_end();

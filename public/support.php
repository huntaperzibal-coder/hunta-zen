<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
page_start('Поддержать — '.setting('title'));$url=reader_donation_url();
echo '<section class="reader-service-page support-page"><nav class="reader-page-nav" aria-label="Навигация по разделам"><a class="back" href="/">← К историям</a><a href="/feedback.php">Автор</a></nav><div class="support-intro"><img class="support-emblem" src="/assets/brand/dialogue.svg?v=2" width="64" height="64" alt=""><p class="service-eyebrow">Гена и Винни</p><h1>Чтобы разговор продолжался</h1><p>'.nl2br(h(setting('support_text','Если тебе здесь хорошо — можно поддержать автора. Чтобы у Гены и Винни всегда находилось время на разговор.'))).'</p></div>';
if($url!==''){
    echo '<div class="support-panel"><div class="support-action"><h2>Поддержать эти истории</h2><p>Сумму выбираешь ты. Спасибо за внимание к этим разговорам.</p><a class="reader-primary-button reader-support-link" href="'.h($url).'" target="_blank" rel="noopener">'.dao_icon('heart').' Поддержать автора <span aria-hidden="true">↗</span></a><p class="hint">На телефоне удобнее нажать кнопку. Оплата откроется на отдельной защищённой странице сервиса.</p></div>';
    $qr=reader_public_photo('support_qr');$src=$qr!==''&&setting('support_qr_url')===$url?photo_url($qr):($url===READER_SUPPORT_DEFAULT?'/assets/brand/support-cloudtips.png':'');
    if($src!=='')echo '<div class="support-qr"><div class="support-qr-paper"><img src="'.h($src).'" width="232" height="232" alt="QR-код: поддержать Гена и Винни" decoding="async"></div><p>Наведи камеру телефона</p><a href="'.h($src).'" download="gena-vinni-qr.png">Сохранить QR-код</a></div>';
    echo '</div><p class="support-footnote">Поддержка добровольная, без подписки и доступа «по билету». Данные карты на этом сайте не запрашиваются. <a href="/privacy.php">Об обработке данных</a></p>';
}else echo '<p><a href="/feedback.php">Познакомиться с автором →</a></p>';
echo '</section>';page_end();

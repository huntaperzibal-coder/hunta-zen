<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
page_start('Поддержать — '.setting('title'));$url=reader_support_url(setting('support_url'));
echo '<section class="reader-service-page"><a class="back" href="/">← К историям</a><h1>Поддержать эти истории</h1><p>'.nl2br(h(setting('support_text','Если тебе здесь хорошо — можно поддержать автора. Чтобы у Гены и Винни всегда находилось время на разговор.'))).'</p>';
if($url!=='')echo '<a class="reader-support-link" href="'.h($url).'" target="_blank" rel="noopener">Перейти к поддержке ↗</a>';
else echo '<a class="reader-support-link" href="/feedback.php">Обсудить поддержку с автором</a>';
echo '</section>';page_end();

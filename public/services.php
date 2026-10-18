<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
page_start('Меню — '.setting('title'));
echo '<section class="reader-service-page"><a class="back" href="/">← К историям</a><h1>Меню</h1><nav class="reader-service-links" aria-label="Сервисы сайта">'.reader_service_links().'</nav></section>';page_end();

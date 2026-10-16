<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
$path=getenv('HUNTA_CONFIG') ?: dirname(__DIR__).'/storage/config.php';
if (!is_file($path)) require __DIR__.'/install.php';
else {
    // Копия до изменения структуры уже установленной версии.
    require __DIR__.'/backup.php';
    require __DIR__.'/migrate.php';
    require __DIR__.'/use-existing-author.php';
}

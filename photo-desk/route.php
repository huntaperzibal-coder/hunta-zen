<?php
declare(strict_types=1);
// Dispatch before the blog config, database, sessions or canonical redirects are loaded.
(static function(): void {
    if(PHP_SAPI==='cli')return;
    $host=strtolower(rtrim(explode(':',(string)($_SERVER['HTTP_HOST']??''))[0],'.'));
    if($host!=='files.hunta-zen.ru')return;
    require __DIR__.'/public/index.php';
    exit;
})();

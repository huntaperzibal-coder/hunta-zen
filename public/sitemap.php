<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');header('Cache-Control: no-cache');
if(setting('indexed')!=='1'){http_response_code(404);exit;}
echo seo_sitemap();

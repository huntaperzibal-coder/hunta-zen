<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-cache');
echo "User-agent: *\n";
if(setting('indexed')!=='1')echo "Disallow: /\n";
else echo "Disallow: /admin.php\nDisallow: /editor-upload.php\nDisallow: /illustration-status.php\nDisallow: /privacy.php\nDisallow: /consent.php\nDisallow: /services.php\nSitemap: ".seo_base()."/sitemap.xml\n";

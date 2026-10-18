<?php
declare(strict_types=1);
$dir=sys_get_temp_dir().'/zen-seo-'.bin2hex(random_bytes(5));mkdir($dir,0700);mkdir($dir.'/photos',0700);
file_put_contents($dir.'/config.php','<?php return '.var_export(['storage'=>$dir,'site_url'=>'http://example.test'],true).';');putenv('HUNTA_CONFIG='.$dir.'/config.php');
require dirname(__DIR__).'/app/bootstrap.php';$db->exec(file_get_contents(ROOT.'/database.sql'));
function seo_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'OK: '.$label."\n";}
try{
    seo_check(seo_base()==='https://example.test','legacy HTTP config produces HTTPS canonical base');
    $_SERVER['SCRIPT_NAME']='/index.php';$_GET=['page'=>2,'category'=>'gena','utm_source'=>'test'];seo_check(seo_canonical()==='https://example.test/?category=gena&page=2','pagination self canonical drops tracking parameters');
    $_GET=['p'=>'test-story','page'=>9,'category'=>'gena'];seo_check(seo_canonical()==='https://example.test/?p=test-story','post canonical ignores unrelated list filters');
    $_SERVER['SCRIPT_NAME']='/feedback.php';seo_check(seo_canonical()==='https://example.test/feedback.php','author canonical is independent of article parameters');
    seo_check(setting('indexed')==='1','launch migration enables indexing');$db->exec("UPDATE settings SET value='0' WHERE key='indexed'");$db->exec(file_get_contents(ROOT.'/database.sql'));seo_check(setting('indexed')==='0','later manual indexing opt-out survives migrations');
    $stamp=now();$q=$db->prepare("INSERT INTO posts(slug,title,body,status,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?)");$q->execute(['public-story','Мысль','Одна мысль.','published',$stamp,$stamp,$stamp]);$id=(int)$db->lastInsertId();$q->execute(['private-story','Секрет','Приватный текст','draft',null,$stamp,$stamp]);
    $p=post($id);seo_check(seo_description($p)==='Одна мысль.','description falls back to story content');$p['excerpt']='Описание автора';seo_check(seo_description($p)==='Описание автора','manual description takes priority');$data=seo_post_data($p);seo_check($data['@type']==='BlogPosting'&&isset($data['datePublished'],$data['dateModified'],$data['author']['url']),'article structured data includes public author, dates and canonical');
    $inline=$p;$inline['cover']='';$inline['body']=DAO_RICH_PREFIX.'<p><img src="/media.php?file='.str_repeat('a',32).'.png" alt="Иллюстрация"></p>';seo_check(seo_post_data($inline)['image']==='https://example.test/media.php?file='.str_repeat('a',32).'.png','inline illustration used for article metadata when cover is absent');
    $xml=seo_sitemap();$doc=new DOMDocument();seo_check($doc->loadXML($xml)&&str_contains($xml,'public-story')&&!str_contains($xml,'private-story')&&!str_contains($xml,'admin.php'),'sitemap valid XML and excludes drafts and admin');
    $db->prepare("UPDATE posts SET status='draft' WHERE id=?")->execute([$id]);seo_check(!str_contains(seo_sitemap(),'public-story'),'unpublished story removed from sitemap');
    seo_check(seo_verification_code('<meta name="google-site-verification" content="abc_DEF-123" />')==='abc_DEF-123','verification accepts pasted complete meta tag');
    try{seo_verification_code('"><script>alert(1)</script>');throw new LogicException('accepted script');}catch(RuntimeException $e){seo_check(true,'verification rejects script injection');}
}finally{$db=null;foreach(glob($dir.'/*') as $f){if(is_dir($f))rmdir($f);else unlink($f);}rmdir($dir);}

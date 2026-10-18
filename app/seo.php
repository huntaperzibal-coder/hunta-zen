<?php
declare(strict_types=1);
function seo_base(): string {
    global $config;
    $base=rtrim((string)$config['site_url'],'/');$host=strtolower((string)parse_url($base,PHP_URL_HOST));
    return in_array($host,['localhost','127.0.0.1','::1'],true)?$base:(string)preg_replace('~^http://~i','https://',$base);
}
function seo_redirect(): void {
    if(PHP_SAPI==='cli')return;
    $base=seo_base();$canonicalHost=strtolower((string)parse_url($base,PHP_URL_HOST));
    $host=strtolower(explode(':',(string)($_SERVER['HTTP_HOST']??''))[0]);
    if(!in_array($host,[$canonicalHost,'www.'.$canonicalHost],true)||in_array($host,['localhost','127.0.0.1','::1'],true))return;
    $secure=($_SERVER['HTTPS']??'')==='on'||(int)($_SERVER['SERVER_PORT']??0)===443||strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))==='https';
    if((str_starts_with($base,'https://')&&!$secure)||$host!==$canonicalHost){
        $uri=(string)($_SERVER['REQUEST_URI']??'/');if(!str_starts_with($uri,'/')||preg_match('/[\r\n]/',$uri))$uri='/';
        header('Location: '.$base.$uri,true,in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true)?301:308);exit;
    }
}
function seo_canonical(): string {
    $script=$_SERVER['SCRIPT_NAME']??'/index.php';
    if($script!=='/index.php'&&$script!=='/')return seo_base().$script;
    $query=[];
    if((string)($_GET['p']??'')!=='')$query['p']=(string)$_GET['p'];
    else{
        if((string)($_GET['category']??'')!=='')$query['category']=(string)$_GET['category'];
        if((int)($_GET['page']??1)>1)$query['page']=(int)$_GET['page'];
    }
    return seo_base().'/'.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
}
function seo_description(array $p): string {
    $text=trim((string)($p['excerpt']??''))?:body_plain((string)$p['body']);
    $text=trim((string)preg_replace('/\s+/u',' ',$text));
    return mb_strlen($text)>180?rtrim(mb_substr($text,0,177)).'…':$text;
}
function seo_cover(array $p): string {
    if(!empty($p['cover']))return (string)$p['cover'];
    if(preg_match('~<img\b[^>]*\bsrc=["\']([^"\']+)["\']~i',body_for_editor((string)$p['body']),$match))return article_photo_name(html_entity_decode($match[1],ENT_QUOTES|ENT_HTML5,'UTF-8'))??'';
    return '';
}
function seo_post_data(array $p): array {
    $data=['@context'=>'https://schema.org','@type'=>'BlogPosting','headline'=>$p['title'],'description'=>seo_description($p),'url'=>seo_base().'/?p='.rawurlencode($p['slug']),'mainEntityOfPage'=>seo_base().'/?p='.rawurlencode($p['slug']),'inLanguage'=>'ru','author'=>['@type'=>'Person','name'=>setting('author_name','Hunta'),'url'=>seo_base().'/feedback.php'],'isPartOf'=>['@type'=>'Blog','name'=>setting('title'),'url'=>seo_base().'/']];
    foreach(['published_at'=>'datePublished','updated_at'=>'dateModified'] as $key=>$field)if(!empty($p[$key])&&strtotime($p[$key])!==false)$data[$field]=date(DATE_ATOM,strtotime($p[$key]));
    $cover=seo_cover($p);if($cover!=='')$data['image']=seo_base().photo_url($cover);
    return $data;
}
function seo_sitemap(): string {
    global $db;
    $base=seo_base();$entries=[['loc'=>$base.'/'],['loc'=>$base.'/feedback.php'],['loc'=>$base.'/support.php']];
    foreach($db->query("SELECT DISTINCT c.slug FROM categories c JOIN post_categories pc ON pc.category_id=c.id JOIN posts p ON p.id=pc.post_id WHERE p.status='published'") as $row)$entries[]=['loc'=>$base.'/?category='.rawurlencode($row['slug'])];
    foreach($db->query("SELECT slug,updated_at FROM posts WHERE status='published' ORDER BY id") as $row){$entry=['loc'=>$base.'/?p='.rawurlencode($row['slug'])];if(strtotime($row['updated_at'])!==false)$entry['lastmod']=date(DATE_ATOM,strtotime($row['updated_at']));$entries[]=$entry;}
    $xml='<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach($entries as $entry){$xml.='<url>';foreach($entry as $key=>$value)$xml.='<'.$key.'>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</'.$key.'>';$xml.='</url>';}
    return $xml.'</urlset>';
}
function seo_verification_code(string $value): string {
    $value=trim($value);
    if(preg_match('~^<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*>\s*$~i',$value,$match))$value=$match[1];
    if($value!==''&&!preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$value))throw new RuntimeException('Вставь код подтверждения из поля content или целый метатег поисковика.');
    return $value;
}

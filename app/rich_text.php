<?php
declare(strict_types=1);
const DAO_RICH_PREFIX='<!--dao:rich-v1-->';
function rich_body(string $body): bool { return str_starts_with($body,DAO_RICH_PREFIX); }
function safe_article_link(string $url): ?string {
    $url=trim($url);
    if ($url==='' || preg_match('/[\x00-\x20\\\\]/',$url)) return null;
    if (str_starts_with($url,'#')) return $url;
    if (str_starts_with($url,'/') && !str_starts_with($url,'//')) return $url;
    if (preg_match('~^https?://~i',$url) && filter_var($url,FILTER_VALIDATE_URL)) return $url;
    if (str_starts_with(strtolower($url),'mailto:') && filter_var(substr($url,7),FILTER_VALIDATE_EMAIL)) return $url;
    return null;
}
function article_photo_name(string $url): ?string {
    global $config;
    $base=rtrim($config['site_url'],'/');
    if (str_starts_with($url,$base.'/')) $url=substr($url,strlen($base));
    return preg_match('~^/media\.php\?file=([a-f0-9]{32}\.(?:jpg|png|webp))$~D',$url,$m)?$m[1]:null;
}
function sanitize_article_html(string $html): string {
    if (!class_exists('DOMDocument')) throw new RuntimeException('Для визуального редактора нужно расширение PHP DOM.');
    $document=new DOMDocument('1.0','UTF-8');
    $previous=libxml_use_internal_errors(true);
    try { $document->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); }
    finally { libxml_clear_errors();libxml_use_internal_errors($previous); }
    $allowed=['p','h2','h3','h4','strong','b','em','i','u','s','blockquote','ul','ol','li','a','br','hr','figure','figcaption','img','video','table','thead','tbody','tfoot','tr','td','th','pre','code','sub','sup'];
    $drop=['script','style','iframe','object','embed','svg','math','template','noscript','form','input','button','select','textarea','meta','link','base'];
    $walk=function(DOMNode $node)use(&$walk,$allowed,$drop):string {
        if ($node instanceof DOMText) return h($node->nodeValue);
        if (!($node instanceof DOMElement)) return '';
        $tag=strtolower($node->tagName);
        if (in_array($tag,$drop,true)) return '';
        $content='';foreach($node->childNodes as $child)$content.=$walk($child);
        if (!in_array($tag,$allowed,true)) return $content;
        $attrs='';
        if ($tag==='a') {
            $url=safe_article_link($node->getAttribute('href'));if (!$url)return $content;
            $attrs=' href="'.h($url).'"';
            if ($node->getAttribute('target')==='_blank')$attrs.=' target="_blank" rel="noopener noreferrer"';
            if ($node->hasAttribute('title'))$attrs.=' title="'.h($node->getAttribute('title')).'"';
        }
        if ($tag==='video') {
            $name=article_video_name($node->getAttribute('src'));if(!$name)return '';
            return '<video src="'.h(photo_url($name)).'" controls playsinline preload="metadata"></video>';
        }
        if ($tag==='img') {
            $name=article_photo_name($node->getAttribute('src'));if (!$name)return '';
            $attrs=' src="'.h(photo_url($name)).'" alt="'.h($node->getAttribute('alt')).'" loading="lazy"';
            foreach(['width','height'] as $key){$value=$node->getAttribute($key);if(preg_match('/^[0-9]+$/D',$value) && (int)$value>0 && (int)$value<=4096)$attrs.=' '.$key.'="'.(int)$value.'"';}
        }
        if (in_array($tag,['td','th'],true))foreach(['colspan','rowspan'] as $key){$value=(int)$node->getAttribute($key);if($value>1 && $value<=100)$attrs.=' '.$key.'="'.$value.'"';}
        if (in_array($tag,['p','h2','h3','h4','td','th','figure'],true) && preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\s*(?:;|$)/i',$node->getAttribute('style'),$m))$attrs.=' class="align-'.strtolower($m[1]).'"';
        if (!str_contains($attrs,' class=') && in_array($tag,['p','h2','h3','h4','td','th','figure'],true) && preg_match('/^align-(left|center|right|justify)$/D',$node->getAttribute('class'),$m))$attrs.=' class="align-'.$m[1].'"';
        if ($tag==='figure')$attrs.=' data-photo="1"';
        if (in_array($tag,['img','br','hr'],true))return '<'.$tag.$attrs.'>';
        return '<'.$tag.$attrs.'>'.$content.'</'.$tag.'>';
    };
    $result='';foreach($document->getElementsByTagName('body')->item(0)->childNodes as $node)$result.=$walk($node);
    return trim($result);
}
function body_for_editor(string $body): string { return rich_body($body)?sanitize_article_html(substr($body,strlen(DAO_RICH_PREFIX))):render_legacy_body($body); }
function body_plain(string $body): string {
    if (!rich_body($body)) return trim(str_replace('**','',preg_replace('/!\[[^\]]*\]\([^)]+\)/','',$body)));
    $html=substr($body,strlen(DAO_RICH_PREFIX));
    $html=preg_replace('~</(?:p|h[2-4]|li|blockquote|tr|figcaption)>|<br\s*/?>~i',"\n",$html);
    return trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
}

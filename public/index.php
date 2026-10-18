<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$slug=(string)($_GET['p']??'');
$categorySlug=(string)($_GET['category']??'');
$category=null;$current=null;$page=max(1,(int)($_GET['page']??1));
$select="SELECT p.*,c.title AS category_title,c.slug AS category_slug FROM posts p LEFT JOIN post_categories pc ON pc.post_id=p.id LEFT JOIN categories c ON c.id=pc.category_id";
if($slug!==''){
    $q=$db->prepare($select." WHERE p.slug=? AND p.status='published'");$q->execute([$slug]);$current=$q->fetch();
    if(!$current){http_response_code(404);page_start('Запись не найдена');echo '<h1>Здесь пока тихо</h1><a href="/">Вернуться к историям</a>';page_end();exit;}
    $categorySlug=(string)($current['category_slug']??'');
}
if($categorySlug!==''){
    $category=category_by_slug($categorySlug);
    if(!$category){http_response_code(404);page_start('Категория не найдена');echo '<h1>Категория не найдена</h1><a href="/">Все истории</a>';page_end();exit;}
}
$q=$db->prepare($select." WHERE p.status='published'".($category?' AND pc.category_id=?':'')." ORDER BY p.published_at DESC,p.id DESC LIMIT 11 OFFSET ?");
$index=1;if($category)$q->bindValue($index++,(int)$category['id'],PDO::PARAM_INT);
$q->bindValue($index,($current?0:($page-1)*10),PDO::PARAM_INT);$q->execute();$listed=$q->fetchAll();
$stories=$current?[$current]:array_slice($listed,0,10);
if(!$current&&$page>1&&!$stories){http_response_code(404);page_start('Страница не найдена');echo '<h1>Здесь больше нет историй</h1><a href="/">К началу ленты</a>';page_end();exit;}
$title=$current?$current['title'].' — '.setting('title'):($category?category_public_title($category['slug'],$category['title']).' — ':'').setting('title').($page>1?' — страница '.$page:'');
$description=$current?seo_description($current):($category?category_public_title($category['slug'],$category['title']).'. Философские диалоги о жизни.':setting('description')).($page>1?' Страница '.$page.'.':'');
$seo=$current?seo_post_data($current):['@context'=>'https://schema.org','@type'=>($category||$page>1)?'CollectionPage':'WebSite','name'=>$title,'url'=>seo_canonical(),'inLanguage'=>'ru'];
page_start($title,false,$description,$current?seo_cover($current):'',$seo);
echo '<div class="reading-layout"><aside class="reading-sidebar" aria-label="Навигация по историям">'.category_links('/',['category'=>$categorySlug]);
if($stories){
    echo '<details class="reading-toc" open><summary>'.($current?'Другие истории':'В этой ленте').'</summary><nav aria-label="Оглавление ленты">';
    foreach(array_slice($listed,0,10) as $item){
        $href=$current?'/?p='.rawurlencode($item['slug']):'#post-'.(int)$item['id'];
        echo '<a href="'.h($href).'">'.h($item['title']).'</a>';
    }
    echo '</nav></details>';
}
echo '</aside><section class="reading-feed" aria-label="Лента историй">';
if($current)echo '<a class="back" href="/'.($category?'?category='.h(rawurlencode($category['slug'])):'').'">← К ленте целиком</a>';
if(!$stories)echo '<p class="empty">'.($category?'В этой линии пока нет опубликованных историй.':'Скоро здесь появятся первые истории.').'</p>';
foreach($stories as $p){
    $line=in_array($p['category_slug']??'', ['gena','vinnie'],true)?$p['category_slug']:'general';
    $postUrl=seo_base().'/?p='.rawurlencode($p['slug']);
    echo '<article class="story" data-line="'.h($line).'" id="post-'.(int)$p['id'].'"><div class="story-meta"><time datetime="'.h(date('Y-m-d',strtotime($p['published_at']))).'">'.h(date('d.m.Y',strtotime($p['published_at']))).'</time>';
    if(!empty($p['category_title']))echo '<a href="/?category='.h(rawurlencode($p['category_slug'])).'">'.h(category_public_title($p['category_slug'],$p['category_title'])).' <span class="story-line-caption">· '.h(category_public_caption($p['category_slug'],$p['category_title'])).'</span></a>';
    echo '<div class="story-actions" data-post-url="'.h($postUrl).'" data-post-title="'.h($p['title']).'"><button type="button" class="story-action" data-share hidden aria-label="Скопировать ссылку на запись «'.h($p['title']).'»" title="Скопировать ссылку"><svg class="share-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4m-6.8 7 6.8 4"/></svg><svg class="copied-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg></button></div>';
    echo '</div>'.($current?'<h1>':'<h2><a href="/?p='.h(rawurlencode($p['slug'])).'">').h($p['title']).($current?'</h1>':'</a></h2>');
    if($current)echo '<p class="story-author"><a href="/feedback.php">'.h(setting('author_name','Hunta')).'</a></p>';
    if($p['cover'])echo '<a class="story-image-link" href="'.h(photo_url($p['cover'])).'" target="_blank" rel="noopener" aria-label="Открыть иллюстрацию полностью"><img loading="lazy" class="cover" '.reader_image_attributes($p['cover'],true).' alt="Иллюстрация к истории «'.h($p['title']).'»"></a>';
    echo '<div class="prose">'.reader_body_images(render_body($p['body'])).'</div><div class="story-ending" aria-hidden="true"><span class="story-ending-mark"></span></div></article>';
}
if(!$current){
    $suffix=$category?'&amp;category='.h(rawurlencode($category['slug'])):'';
    if($page>1||count($listed)>10){
        echo '<nav class="pagination" aria-label="Страницы ленты">';
        if($page>1)echo '<a href="/?page='.($page-1).$suffix.'">← Новее</a>';
        if(count($listed)>10)echo '<a href="/?page='.($page+1).$suffix.'">Раньше →</a>';
        echo '</nav>';
    }
}
echo '</section></div><p id="reader-action-status" class="reader-toast" role="status" aria-live="polite"></p><dialog id="reader-lightbox" class="reader-lightbox" aria-label="Просмотр иллюстраций"><button type="button" class="lightbox-close" aria-label="Закрыть изображение">×</button><button type="button" class="lightbox-prev" aria-label="Предыдущее изображение">‹</button><div class="lightbox-stage"><img alt=""><p class="lightbox-caption"></p></div><button type="button" class="lightbox-next" aria-label="Следующее изображение">›</button></dialog><script src="/assets/js/reader-actions.js?v=6" defer></script><script src="/assets/js/reader-feed.js?v=1" defer></script>';
page_end();

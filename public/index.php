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
$title=$current?$current['title'].' — '.setting('title'):($category?category_public_title($category['slug'],$category['title']).' — ':'').setting('title');
page_start($title,false,$current['excerpt']??'',$current['cover']??'');
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
    echo '<article class="story" data-line="'.h($line).'" id="post-'.(int)$p['id'].'"><div class="story-meta"><time datetime="'.h(date('Y-m-d',strtotime($p['published_at']))).'">'.h(date('d.m.Y',strtotime($p['published_at']))).'</time>';
    if(!empty($p['category_title']))echo '<a href="/?category='.h(rawurlencode($p['category_slug'])).'">'.h(category_public_title($p['category_slug'],$p['category_title'])).' <span class="story-line-caption">· '.h(category_public_caption($p['category_slug'],$p['category_title'])).'</span></a>';
    echo '</div>'.($current?'<h1>':'<h2><a href="/?p='.h(rawurlencode($p['slug'])).'">').h($p['title']).($current?'</h1>':'</a></h2>');
    if($p['cover'])echo '<a class="story-image-link" href="'.h(photo_url($p['cover'])).'" target="_blank" rel="noopener" aria-label="Открыть иллюстрацию полностью"><img loading="lazy" class="cover" src="'.h(photo_url($p['cover'])).'" alt="Иллюстрация к истории «'.h($p['title']).'»"></a>';
    echo '<div class="prose">'.render_body($p['body']).'</div></article>';
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
echo '</section></div>';
page_end();

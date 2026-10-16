<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$slug=(string)($_GET['p']??'');
if ($slug!=='') {
    $q=$db->prepare("SELECT p.*,c.title AS category_title,c.slug AS category_slug FROM posts p LEFT JOIN post_categories pc ON pc.post_id=p.id LEFT JOIN categories c ON c.id=pc.category_id WHERE p.slug=? AND p.status='published'"); $q->execute([$slug]); $p=$q->fetch();
    if (!$p) { http_response_code(404); page_start('Запись не найдена'); echo '<h1>Здесь пока тихо</h1><a href="/">Вернуться к историям</a>'; page_end(); exit; }
    page_start($p['title'].' — '.setting('title'),false,$p['excerpt'],$p['cover']);
    echo '<article><a class="back" href="/">← Все истории</a><p class="eyebrow">'.h(date('d.m.Y',strtotime($p['published_at']))).'</p><h1>'.h($p['title']).'</h1>';
    if (!empty($p['category_title'])) echo '<a class="category-chip" href="/?category='.h(rawurlencode($p['category_slug'])).'">'.h($p['category_title']).'</a>';
    if ($p['cover']) echo '<img class="cover" src="'.h(photo_url($p['cover'])).'" alt="">';
    echo '<div class="prose">'.render_body($p['body']).'</div></article>';
} else {
    $categorySlug=(string)($_GET['category']??'');$category=$categorySlug!==''?category_by_slug($categorySlug):null;
    if($categorySlug!=='' && !$category){http_response_code(404);page_start('Категория не найдена');echo '<h1>Категория не найдена</h1><a href="/">Все истории</a>';page_end();exit;}
    page_start(($category?$category['title'].' — ':'').setting('title'));
    echo '<section class="intro"><p class="eyebrow">Личный блог</p><h1>'.h(setting('title')).'</h1><p>'.h(setting('description')).'</p></section>';
    echo category_links('/',['category'=>$categorySlug]);
    $page=max(1,(int)($_GET['page']??1));
    $q=$db->prepare("SELECT p.*,c.title AS category_title,c.slug AS category_slug FROM posts p LEFT JOIN post_categories pc ON pc.post_id=p.id LEFT JOIN categories c ON c.id=pc.category_id WHERE p.status='published'".($category?' AND pc.category_id=?':'')." ORDER BY p.published_at DESC,p.id DESC LIMIT 11 OFFSET ?");
    $index=1;if($category)$q->bindValue($index++,(int)$category['id'],PDO::PARAM_INT);$q->bindValue($index,($page-1)*10,PDO::PARAM_INT);$q->execute();$posts=$q->fetchAll();

    if (!$posts) echo '<p class="empty">'.($category?'В этой категории пока нет опубликованных историй.':'Скоро здесь появятся первые истории.').'</p>';
    foreach (array_slice($posts,0,10) as $p) {
        echo '<article class="story"><p class="eyebrow">'.h(date('d.m.Y',strtotime($p['published_at']))).'</p><h2><a href="/?p='.h(rawurlencode($p['slug'])).'">'.h($p['title']).'</a></h2>';
        if (!empty($p['category_title'])) echo '<a class="category-chip" href="/?category='.h(rawurlencode($p['category_slug'])).'">'.h($p['category_title']).'</a>';
        if ($p['cover']) echo '<a href="/?p='.h(rawurlencode($p['slug'])).'"><img loading="lazy" class="cover" src="'.h(photo_url($p['cover'])).'" alt=""></a>';
        echo '<p>'.nl2br(h($p['excerpt'] ?: mb_substr(body_plain($p['body']),0,350))).'</p><a class="read" href="/?p='.h(rawurlencode($p['slug'])).'">Читать историю →</a></article>';
    }
    $categorySuffix=$category?'&amp;category='.h(rawurlencode($category['slug'])):'';
    echo '<nav class="pagination">'; if ($page>1) echo '<a href="/?page='.($page-1).$categorySuffix.'">← Новее</a>'; if (count($posts)>10) echo '<a href="/?page='.($page+1).$categorySuffix.'">Раньше →</a>'; echo '</nav>';
}
page_end();

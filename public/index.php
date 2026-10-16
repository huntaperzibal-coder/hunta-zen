<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$slug=(string)($_GET['p']??'');
if ($slug!=='') {
    $q=$db->prepare("SELECT * FROM posts WHERE slug=? AND status='published'"); $q->execute([$slug]); $p=$q->fetch();
    if (!$p) { http_response_code(404); page_start('Запись не найдена'); echo '<h1>Здесь пока тихо</h1><a href="/">Вернуться к историям</a>'; page_end(); exit; }
    page_start($p['title'].' — '.setting('title'),false,$p['excerpt'],$p['cover']);
    echo '<article><a class="back" href="/">← Все истории</a><p class="eyebrow">'.h(date('d.m.Y',strtotime($p['published_at']))).'</p><h1>'.h($p['title']).'</h1>';
    if ($p['cover']) echo '<img class="cover" src="'.h(photo_url($p['cover'])).'" alt="">';
    echo '<div class="prose">'.render_body($p['body']).'</div></article>';
} else {
    page_start(setting('title'));
    echo '<section class="intro"><p class="eyebrow">Личный блог</p><h1>'.h(setting('title')).'</h1><p>'.h(setting('description')).'</p></section>';
    $page=max(1,(int)($_GET['page']??1)); $q=$db->prepare("SELECT * FROM posts WHERE status='published' ORDER BY published_at DESC,id DESC LIMIT 11 OFFSET ?"); $q->bindValue(1,($page-1)*10,PDO::PARAM_INT);$q->execute();$posts=$q->fetchAll();
    if (!$posts) echo '<p class="empty">Скоро здесь появятся первые истории.</p>';
    foreach (array_slice($posts,0,10) as $p) {
        echo '<article class="story"><p class="eyebrow">'.h(date('d.m.Y',strtotime($p['published_at']))).'</p><h2><a href="/?p='.h(rawurlencode($p['slug'])).'">'.h($p['title']).'</a></h2>';
        if ($p['cover']) echo '<a href="/?p='.h(rawurlencode($p['slug'])).'"><img loading="lazy" class="cover" src="'.h(photo_url($p['cover'])).'" alt=""></a>';
        echo '<p>'.nl2br(h($p['excerpt'] ?: mb_substr(preg_replace('/!\[[^\]]*\]\([^)]+\)/','',$p['body']),0,350))).'</p><a class="read" href="/?p='.h(rawurlencode($p['slug'])).'">Читать историю →</a></article>';
    }
    echo '<nav class="pagination">'; if ($page>1) echo '<a href="/?page='.($page-1).'">← Новее</a>'; if (count($posts)>10) echo '<a href="/?page='.($page+1).'">Раньше →</a>'; echo '</nav>';
}
page_end();

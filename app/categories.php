<?php
declare(strict_types=1);
function post_categories(): array { global $db;return $db->query('SELECT id,slug,title FROM categories ORDER BY sort_order,id')->fetchAll(); }
function category_by_slug(string $slug): ?array {
    global $db;$q=$db->prepare('SELECT id,slug,title FROM categories WHERE slug=?');$q->execute([$slug]);return $q->fetch() ?: null;
}
function category_options(int $current): string {
    $html='<option value="0">Без категории</option>';
    foreach(post_categories() as $c)$html.='<option value="'.(int)$c['id'].'"'.((int)$c['id']===$current?' selected':'').'>'.h($c['title']).'</option>';
    return $html;
}
function category_links(string $base,array $query=[]): string {
    $selected=(string)($query['category']??'');unset($query['category']);
    $url=$base.($query?'?'.http_build_query($query):'');
    $html='<nav class="category-tabs" aria-label="Категории"><a href="'.h($url).'"'.($selected===''?' class="active" aria-current="page"':'').'>Все категории</a>';
    foreach(post_categories() as $c){$href=$base.'?'.http_build_query($query+['category'=>$c['slug']]);$html.='<a href="'.h($href).'"'.($selected===$c['slug']?' class="active" aria-current="page"':'').'>'.h($c['title']).'</a>';}
    return $html.'</nav>';
}

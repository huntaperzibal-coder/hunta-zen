<?php
declare(strict_types=1);
/** Разметка адаптирована из adminka/orda.php, render_orda_menu.php и login.php ТОПО.
 * Контент открывается в том же документе: навигация сохраняет защиту несохранённого текста.
 * Палитра, формы, кнопки и шрифты — ресурсы Орды; знаки и иконки — свои, «Дао».
 */
function orda_page_start(string $title): void {
    global $ordaPageMode;
    $login=!authenticated();$ordaPageMode=$login?'auth':'shell';
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.h($title).'</title>'.dao_brand_head(true).'<link rel="stylesheet" href="/styles_core/adminka/stili_orda.css?v=1"><link rel="stylesheet" href="/assets/css/orda_blog.css?v=4"></head><body class="'.($login?'auth':'orda-shell').'">';
    if ($login) { echo '<div class="auth-box">';return; }
    $view=(string)($_GET['view']??'list');$status=(string)($_GET['status']??'');
    $current=match($view){'edit','preview'=>'list','settings'=>'settings','service'=>'service','telegram'=>'telegram',default=>$status ?: 'list'};
    $items=[
        ['id'=>'list','title'=>'Все записи','file'=>'/admin.php','icon'=>'stories'],
        ['id'=>'draft','title'=>'Черновики и заметки','file'=>'/admin.php?status=draft','icon'=>'draft'],
        ['id'=>'published','title'=>'На сайте','file'=>'/admin.php?status=published','icon'=>'published'],
        ['id'=>'telegram','title'=>'Telegram','file'=>'/admin.php?view=telegram','icon'=>'telegram'],
        ['id'=>'settings','title'=>'Настройки сайта','file'=>'/admin.php?view=settings','icon'=>'settings','divider'=>'САЙТ'],
        ['id'=>'service','title'=>'Резервные копии и журнал','file'=>'/admin.php?view=service','icon'=>'backup','divider'=>'ОБСЛУЖИВАНИЕ'],
    ];
    echo '<div class="layout"><nav class="side" id="ordaSide" aria-label="Меню админки"><div class="brand"><a href="/admin.php" class="orda-home"><div class="orda-logo">'.dao_icon('logo').'</div><span class="orda-title">Дао</span></a><button type="button" class="orda-mobile-toggle" aria-expanded="false" aria-controls="ordaMenu">Меню '.dao_icon('menu').'</button></div><div class="orda-site-title">'.h(setting('title')).'</div><ul class="orda-nav" id="ordaMenu">';
    foreach($items as $item){
        if(isset($item['divider'])) echo '<li class="orda-nav-divider">'.h($item['divider']).'</li>';
        echo '<li><a href="'.h($item['file']).'" class="orda-nav-link'.($current===$item['id']?' active':'').'"'.($current===$item['id']?' aria-current="page"':'').'>'.dao_icon($item['icon']).'<span class="orda-nav-title">'.h($item['title']).'</span></a></li>';
    }
    echo '<li class="orda-nav-divider">ПРОСМОТР</li><li><a class="orda-nav-link" href="/" target="_blank" rel="noopener">'.dao_icon('site').'<span class="orda-nav-title">Открыть сайт ↗</span></a></li></ul><div class="orda-logout"><div class="whois">'.dao_icon('logo').h($_SESSION['user_login']).'</div><form method="post">'.csrf().'<button type="submit" class="orda-logout-button" name="action" value="logout" data-confirm="Выйти из админки?">Выйти</button></form></div></nav><main class="main" id="ordaMain"><div class="hub orda-blog-content"><nav class="orda-breadcrumbs"><a href="/admin.php">Дао</a><span class="separator">›</span><span>'.h(match($view){'edit'=>'Редактор записи','preview'=>'Предпросмотр','settings'=>'Настройки сайта','service'=>'Обслуживание','telegram'=>'Telegram',default=>'Записи'}).'</span></nav>';
}
function orda_page_end(): void {
    global $ordaPageMode;
    echo $ordaPageMode==='auth'?'</div>':'</div></main></div>';
    echo '<script src="/adminka/js/orda_confirm.js?v=1" defer></script><script src="/adminka/js/orda_save_shortcut.js?v=1" defer></script><script src="/adminka/js/orda_flash_message.js?v=1" defer></script><script src="/adminka/js/blog_shell.js?v=1" defer></script></body></html>';
}

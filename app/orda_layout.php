<?php
declare(strict_types=1);
/** Разметка адаптирована из adminka/orda.php, render_orda_menu.php и login.php ТОПО.
 * Контент открывается в том же документе: навигация сохраняет защиту несохранённого текста.
 * Палитра, формы, кнопки, шрифты и иконки — исходные ресурсы Орды.
 */
function orda_icon(string $id): string {
    static $icons=null;
    $icons ??= json_decode(file_get_contents(__DIR__.'/orda_icons.json'),true,512,JSON_THROW_ON_ERROR);
    return '<span class="icon svg-icon" aria-hidden="true">'.($icons[$id]['svg']??'').'</span>';
}
function orda_page_start(string $title): void {
    global $ordaPageMode;
    $login=!authenticated();$ordaPageMode=$login?'auth':'shell';
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.h($title).'</title><link rel="stylesheet" href="/styles_core/adminka/stili_orda.css?v=1"><link rel="stylesheet" href="/assets/css/orda_blog.css?v=1"></head><body class="'.($login?'auth':'orda-shell').'">';
    if ($login) { echo '<div class="auth-box">';return; }
    $view=(string)($_GET['view']??'list');$status=(string)($_GET['status']??'');
    $current=match($view){'edit','preview'=>'list','settings'=>'settings','service'=>'service','telegram'=>'telegram',default=>$status ?: 'list'};
    $items=[
        ['id'=>'list','title'=>'Все записи','file'=>'/admin.php','icon'=>'8krvl2fc'],
        ['id'=>'draft','title'=>'Черновики и заметки','file'=>'/admin.php?status=draft','icon'=>'i0d4qcu5'],
        ['id'=>'published','title'=>'На сайте','file'=>'/admin.php?status=published','icon'=>'8krvl2fc'],
        ['id'=>'telegram','title'=>'Telegram','file'=>'/admin.php?view=telegram','icon'=>'9rhdtfhu'],
        ['id'=>'settings','title'=>'Настройки сайта','file'=>'/admin.php?view=settings','icon'=>'h5gi62zc','divider'=>'САЙТ'],
        ['id'=>'service','title'=>'Резервные копии и журнал','file'=>'/admin.php?view=service','icon'=>'kt3hcat8','divider'=>'ОБСЛУЖИВАНИЕ'],
    ];
    echo '<div class="layout"><nav class="side" id="ordaSide" aria-label="Меню админки"><div class="brand"><a href="/admin.php" class="orda-home"><div class="orda-logo">'.orda_icon('f2x4hafy').'</div><span class="orda-title">Тамга</span></a><button type="button" class="orda-mobile-toggle" aria-expanded="false" aria-controls="ordaMenu">Меню ☰</button></div><div class="orda-site-title">'.h(setting('title')).'</div><ul class="orda-nav" id="ordaMenu">';
    foreach($items as $item){
        if(isset($item['divider'])) echo '<li class="orda-nav-divider">'.h($item['divider']).'</li>';
        echo '<li><a href="'.h($item['file']).'" class="orda-nav-link'.($current===$item['id']?' active':'').'"'.($current===$item['id']?' aria-current="page"':'').'>'.orda_icon($item['icon']).'<span class="orda-nav-title">'.h($item['title']).'</span></a></li>';
    }
    echo '<li class="orda-nav-divider">ПРОСМОТР</li><li><a class="orda-nav-link" href="/" target="_blank" rel="noopener">'.orda_icon('orda').'<span class="orda-nav-title">Открыть сайт ↗</span></a></li></ul><div class="orda-logout"><div class="whois">'.h($_SESSION['user_login']).'</div><form method="post">'.csrf().'<button type="submit" class="orda-logout-button" name="action" value="logout" data-confirm="Выйти из админки?">Выйти</button></form></div></nav><main class="main" id="ordaMain"><div class="hub orda-blog-content"><nav class="orda-breadcrumbs"><a href="/admin.php">Тамга</a><span class="separator">›</span><span>'.h(match($view){'edit'=>'Редактор записи','preview'=>'Предпросмотр','settings'=>'Настройки сайта','service'=>'Обслуживание','telegram'=>'Telegram',default=>'Записи'}).'</span></nav>';
}
function orda_page_end(): void {
    global $ordaPageMode;
    echo $ordaPageMode==='auth'?'</div>':'</div></main></div>';
    echo '<script src="/adminka/js/orda_confirm.js?v=1" defer></script><script src="/adminka/js/orda_save_shortcut.js?v=1" defer></script><script src="/adminka/js/orda_flash_message.js?v=1" defer></script><script src="/adminka/js/blog_shell.js?v=1" defer></script></body></html>';
}

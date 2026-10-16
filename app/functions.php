<?php
declare(strict_types=1);
function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return date('c'); }
function setting(string $key, string $fallback = ''): string {
    global $db; $q=$db->prepare('SELECT value FROM settings WHERE key=?'); $q->execute([$key]);
    $v=$q->fetchColumn(); return $v === false ? $fallback : (string)$v;
}
function audit(string $action, ?int $id=null): void { global $db; $db->prepare('INSERT INTO audit(action,post_id,created_at) VALUES(?,?,?)')->execute([$action,$id,now()]); }
function session_open(): void {
    if (session_status()===PHP_SESSION_ACTIVE) return;
    global $storage;
    if (!is_dir($storage.'/sessions') && !mkdir($storage.'/sessions',0700,true) && !is_dir($storage.'/sessions')) throw new RuntimeException('Не удалось открыть сессию.');
    session_save_path($storage.'/sessions');
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>'/']);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function authenticated(): bool {
    session_open();
    $login=trim((string)($_SESSION['user_login']??''));
    if (empty($_SESSION['user_id']) || $login==='') return false;
    $user=ordaFindUser($login);
    if (!$user || !isset($_SESSION['orda_pw_fp']) || !hash_equals(ordaGuardPasswordFingerprint($user['password_hash']),(string)$_SESSION['orda_pw_fp'])) {
        unset($_SESSION['user_id'],$_SESSION['user_login'],$_SESSION['orda_pw_fp']);return false;
    }
    return true;
}
function guard(): void { if (!authenticated()) { header('Location: /admin.php?view=login',true,303);exit; } }
function csrf(): string { session_open(); return '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function verify_csrf(): void { session_open(); if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { http_response_code(403); exit('Сессия формы устарела. Обнови страницу.'); } }
function redirect(string $url): never { header('Location: '.$url,true,303); exit; }
function post(int $id): array { global $db; $q=$db->prepare('SELECT p.*, COALESCE(pc.category_id,0) AS category_id,c.title AS category_title,c.slug AS category_slug FROM posts p LEFT JOIN post_categories pc ON pc.post_id=p.id LEFT JOIN categories c ON c.id=pc.category_id WHERE p.id=?'); $q->execute([$id]); $p=$q->fetch(); if (!$p) throw new RuntimeException('Запись не найдена.'); return $p; }
function slugify(string $text): string {
    $from=preg_split('//u','абвгдеёжзийклмнопрстуфхцчшщъыьэюя',-1,PREG_SPLIT_NO_EMPTY);
    $to=['a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya'];
    return trim((string)preg_replace('/[^a-z0-9]+/','-',strtr(mb_strtolower($text),array_combine($from,$to))),'-') ?: 'zapis';
}
function unique_slug(string $title, int $id): string {
    global $db; $base=slugify($title); $slug=$base; $i=2; $q=$db->prepare('SELECT id FROM posts WHERE slug=? AND id<>?');
    do { $q->execute([$slug,$id]); if (!$q->fetch()) return $slug; $slug=$base.'-'.$i++; } while(true);
}
function save_post(int $id, array $input, bool $autosave=false): array {
    global $db; $old=post($id);
    if ((int)($input['revision']??0)!==(int)$old['revision']) throw new RuntimeException('Запись уже изменена в другой вкладке. Скопируй свой текст и обнови страницу.');
    $title=trim((string)($input['title']??'')); $body=trim((string)($input['body']??''));
    if (mb_strlen($title)>250 || strlen($body)>1000000) throw new RuntimeException('Слишком большой текст или заголовок.');
    if (($input['body_format']??'')==='html') {
        $html=sanitize_article_html($body);
        $body=trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'))!=='' || str_contains($html,'<img ')?DAO_RICH_PREFIX.$html:'';
    }
    $status=$old['status'];
    if (!$autosave && isset($input['status'])) {
        if (!in_array($input['status'],['draft','published'],true)) throw new RuntimeException('Неизвестное состояние.');
        $status=$input['status'];
    }
    if ($status==='published' && ($title==='' || $body==='')) throw new RuntimeException('Для публикации нужны заголовок и текст.');
    // Публичный адрес закрепляется при первой публикации.
    $slug=$old['published_at'] ? $old['slug'] : unique_slug($title ?: 'Черновик',$id);
    $published=$old['published_at'] ?: ($status==='published' ? now() : null);
    $categoryId=null;
    if (array_key_exists('category_id',$input)) {
        $raw=(string)$input['category_id'];
        if (!preg_match('/^[0-9]+$/D',$raw)) throw new RuntimeException('Неизвестная категория.');
        $categoryId=(int)$raw;
        if ($categoryId>0) { $q=$db->prepare('SELECT 1 FROM categories WHERE id=?');$q->execute([$categoryId]);if(!$q->fetchColumn())throw new RuntimeException('Категория не найдена.'); }
    }
    $db->beginTransaction();
    try {
        $q=$db->prepare('UPDATE posts SET title=?,body=?,excerpt=?,telegram_text=?,status=?,slug=?,published_at=?,updated_at=?,revision=revision+1 WHERE id=? AND revision=?');
        $q->execute([$title,$body,trim((string)($input['excerpt']??'')),trim((string)($input['telegram_text']??'')),$status,$slug,$published,now(),$id,$old['revision']]);
        if ($q->rowCount()!==1) throw new RuntimeException('Конфликт сохранения. Текст остался в редакторе.');
        if ($categoryId!==null) {
            if ($categoryId===0) $db->prepare('DELETE FROM post_categories WHERE post_id=?')->execute([$id]);
            else $db->prepare('INSERT INTO post_categories(post_id,category_id) VALUES(?,?) ON CONFLICT(post_id) DO UPDATE SET category_id=excluded.category_id')->execute([$id,$categoryId]);
        }
        if (!$autosave) audit('save:'.$status,$id);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction())$db->rollBack();throw $e; }

    return post($id);
}
function photo_upload(array $file): string {
    global $storage;
    if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Фотография не загрузилась. Проверь размер файла.');
    if (($file['size']??0)>12*1024*1024) throw new RuntimeException('Фото должно быть меньше 12 МБ.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
    if (!$ext || !getimagesize($file['tmp_name'])) throw new RuntimeException('Подойдут JPEG, PNG или WebP.');
    $name=bin2hex(random_bytes(16)).'.'.$ext;
    if (!move_uploaded_file($file['tmp_name'],$storage.'/photos/'.$name)) throw new RuntimeException('Не удалось сохранить фото.');
    chmod($storage.'/photos/'.$name,0600); return $name;
}
function photo_url(string $name, ?int $id=null): string { return '/media.php?file='.rawurlencode($name).($id?'&post='.$id:''); }
function render_body(string $body): string {
    return rich_body($body)?sanitize_article_html(substr($body,strlen(DAO_RICH_PREFIX))):render_legacy_body($body);
}
function render_legacy_body(string $body): string {
    // Текст никогда не исполняется как HTML. Фотографии — только наши локальные имена.
    $safe=h($body);
    $safe=preg_replace_callback('/!\[([^\]\n]*)\]\(([a-f0-9]{32}\.(?:jpg|png|webp))\)/',fn($m)=>'</p><figure><img loading="lazy" src="'.h(photo_url($m[2])).'" alt="'.$m[1].'"><figcaption>'.$m[1].'</figcaption></figure><p>',$safe);
    $safe=preg_replace('/\*\*([^*\n]+)\*\*/','<strong>$1</strong>',$safe);
    return '<p>'.str_replace(["\r\n","\n\n","\n"],["\n",'</p><p>','<br>'],$safe).'</p>';
}
function page_start(string $title, bool $admin=false, string $description='', string $cover=''): void {
    $indexed=!$admin && setting('indexed')==='1';
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
    $stylePolicy=$admin?"'self' 'unsafe-inline'":"'self'";
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src $stylePolicy; script-src 'self'; frame-src 'self' blob:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    if (!$indexed) header('X-Robots-Tag: noindex, nofollow');
    if ($admin) { orda_page_start($title);return; }
    global $config;
    $canonical=rtrim($config['site_url'],'/').'/'.(isset($_GET['p'])?'?p='.rawurlencode((string)$_GET['p']):(!empty($_GET['category'])?'?category='.rawurlencode((string)$_GET['category']):''));
    $og='<meta property="og:type" content="article"><meta property="og:title" content="'.h($title).'"><meta property="og:description" content="'.h($description ?: setting('description')).'"><meta property="og:url" content="'.h($canonical).'"><link rel="canonical" href="'.h($canonical).'">';
    if ($cover) $og.='<meta property="og:image" content="'.h(rtrim($config['site_url'],'/').photo_url($cover)).'">';
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><meta name="description" content="'.h($description ?: setting('description')).'">'.($admin?'':$og).dao_brand_head(false).'<script src="/assets/js/reader-theme.js?v=1"></script><link rel="stylesheet" href="/assets/css/app.css?v=5"><link rel="stylesheet" href="/assets/css/reader.css?v=1"><body class="'.($admin?'workshop':'blog').'"><header><a class="brand" href="/"><img class="site-avatar" src="/assets/brand/dialogue.svg?v=2" width="42" height="42" alt="">'.h(setting('title')).'</a><nav>';
    if ($admin && authenticated()) echo '<a href="/admin.php">Записи</a><a href="/admin.php?view=settings">Настройки</a><a href="/admin.php?view=service">Обслуживание</a><form method="post">'.csrf().'<button class="quiet" name="action" value="logout">Выйти</button></form>';
    else { $tg=setting('telegram_url'); if (preg_match('~^https://t\.me/[A-Za-z0-9_]+$~',$tg)) echo '<a href="'.h($tg).'">Telegram ↗</a>'; }
    echo '<button class="theme-toggle" type="button" aria-label="Включить тёмную тему" aria-pressed="false" hidden><span class="theme-icon" aria-hidden="true">☾</span><span class="theme-label">Тёмная</span></button></nav></header><main>';
}
function page_end(): void { global $ordaPageMode; if (isset($ordaPageMode)) { orda_page_end();return; } echo '</main><footer>Место для мыслей, историй и разговоров.</footer></body></html>'; }

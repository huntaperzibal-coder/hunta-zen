<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex, nofollow');
session_open();
$action=(string)($_POST['action']??''); $view=(string)($_GET['view']??'list'); $error=''; $unsaved=null; $savedRevision=null;
$telegramInline=in_array($action,['telegram_schedule','telegram_cancel'],true) && ($_POST['telegram_inline']??'')==='1';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    if ($action==='login') {
        $ip=(string)($_SERVER['REMOTE_ADDR']??'');
        $login=trim((string)($_POST['login']??''));$loginKey=mb_strtolower($login,'UTF-8');
        $wait=max(ordaThrottleBlockedSeconds('login_ip',$ip),ordaThrottleBlockedSeconds('login_user',$loginKey));
        $user=ordaFindUser($login);
        if ($wait>0) $error='Слишком много неудачных попыток. Попробуй через '.(int)ceil($wait/60).' мин.';
        elseif ($user && password_verify(trim((string)($_POST['password']??'')),$user['password_hash'])) {
            ordaThrottleClear('login_user',$loginKey);ordaLoginSession($user['login'],$user['password_hash']);audit('login');redirect('/admin.php');
        } else {
            ordaThrottleFail('login_ip',$ip);ordaThrottleFail('login_user',$loginKey);$error='Неверный логин или пароль';
        }
        $view='login';
    } else {
        guard();
        try {
            if ($action==='logout') { $_SESSION=[]; session_destroy();redirect('/admin.php?view=login'); }
            if ($action==='telegram_connection') { telegram_save_connection($_POST);redirect('/admin.php?view=telegram&saved=1'); }
            if ($action==='feedback_read') { $db->prepare('UPDATE reader_feedback SET read_at=? WHERE id=?')->execute([time(),(int)($_POST['message_id']??0)]);redirect('/admin.php?view=feedback'); }
            if ($action==='new') {
                $stamp=now();$db->prepare('INSERT INTO posts(slug,created_at,updated_at) VALUES(?,?,?)')->execute(['draft-'.bin2hex(random_bytes(8)),$stamp,$stamp]);redirect('/admin.php?view=edit&id='.$db->lastInsertId());
            }
            if ($action==='illustrator_download') {
                $kind=(string)($_POST['kind']??'');
                if(!in_array($kind,['key','hosts'],true))throw new RuntimeException('Файл не найден.');
                if($kind==='key' && (($_SERVER['HTTPS']??'')!== 'on') && (int)($_SERVER['SERVER_PORT']??0)!==443)throw new RuntimeException('Для скачивания приватного ключа открой админку по HTTPS. Можно также скачать файл через защищённый файловый менеджер Beget.');
                $file=$storage.($kind==='key'?'/illustrator-actions-key':'/illustrator-actions-known-hosts');
                if(!is_file($file))throw new RuntimeException('Сначала подключи Actions через SSH.');
                header('Content-Type: application/octet-stream');header('Cache-Control: no-store');
                header('Content-Disposition: attachment; filename="'.($kind==='key'?'hunta-illustrator-key.txt':'hunta-illustrator-known-hosts.txt').'"');
                readfile($file);exit;
            }
            if ($action==='illustrator_probe') {
                if(illustration_key()==='')throw new RuntimeException('Сначала сохрани ключ OpenRouter.');
                if(in_array(setting('illustrator_probe_state'),['queued','running'],true))throw new RuntimeException('Проверка уже запущена. Обнови страницу позже.');
                $db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')->execute(['illustrator_probe_state','queued']);
                try{illustration_dispatch(true);}catch(Throwable $e){$db->prepare('INSERT OR REPLACE INTO settings(key,value) VALUES(?,?)')->execute(['illustrator_probe_state','failed']);throw $e;}
                redirect('/admin.php?view=illustrator#probe');
            }
            if ($action==='illustrator_settings') { illustration_save_settings($_POST,$_FILES);illustration_save_github_token((string)($_POST['github_token']??''));redirect('/admin.php?view=illustrator&saved=1'); }
            if (str_starts_with($action,'illustration_delete:')) {
                $deleteId=(int)substr($action,strlen('illustration_delete:'));
                $action='illustration_delete';
            }
            if (str_starts_with($action,'illustration_cover:') || str_starts_with($action,'illustration_inline:')) {
                [$applyKind,$applyId]=explode(':',$action,2);
                // Validate ownership before saving editor content.
                illustration_result((int)$applyId,(int)($_POST['id']??0));
                $action='illustration_apply';
            }
            if (in_array($action,['illustration_delete','illustration_dispatch','illustrate','illustration_apply','save','autosave','publish','unpublish','upload','telegram','telegram_schedule','telegram_cancel','resolve'],true)) {
                $id=(int)($_POST['id']??0);
                $input=$_POST;
                if ($action==='publish') $input['status']='published';
                if ($action==='unpublish') $input['status']='draft';
                $p=save_post($id,$input,$action==='autosave'); $savedRevision=$p['revision'];
                if ($action==='illustration_delete') illustration_delete($deleteId,$id);
                if ($action==='illustration_dispatch') illustration_dispatch();
                if ($action==='illustrate') illustration_enqueue($p,(string)($_POST['illustration_wishes']??''));
                if ($action==='illustration_apply') { $p=illustration_apply($p,(int)$applyId,$applyKind==='illustration_cover'?'cover':'inline');$savedRevision=$p['revision']; }
                if ($action==='upload') {
                    $name=photo_upload($_FILES['photo']??[]);
                    if (($_POST['photo_kind']??'')==='inline') {
                        $addition=rich_body($p['body'])?'<figure><img src="'.h(photo_url($name)).'" alt=""><figcaption>Подпись к фото</figcaption></figure>':"\n\n![Подпись к фото](".$name.")\n";
                        $db->prepare('UPDATE posts SET body=body || ?,revision=revision+1 WHERE id=?')->execute([$addition,$id]);
                    } else $db->prepare('UPDATE posts SET cover=?,revision=revision+1 WHERE id=?')->execute([$name,$id]);
                }
                if ($action==='telegram') { telegram_cancel_schedule($id);telegram_send($id); }
                if ($action==='telegram_schedule') telegram_schedule_post($id,(string)($_POST['telegram_due']??''));
                if ($action==='telegram_cancel') $cancelledCount=telegram_cancel_schedule($id);
                if ($action==='resolve') {
                    if (empty($_POST['checked_channel'])) throw new RuntimeException('Сначала проверь результат в Telegram-канале.');
                    telegram_resolve($id,max(0,(int)($_POST['message_id']??0)));
                }
                if ($telegramInline) {
                    $p=post($id);$q=$db->prepare('SELECT * FROM telegram_deliveries WHERE post_id=? AND channel=?');$q->execute([$id,$config['telegram_channel']]);
                    ob_start();telegram_editor($p,$q->fetch()?:null);$html=ob_get_clean();
                    header('Content-Type: application/json');echo json_encode(['revision'=>$p['revision'],'html'=>$html,'message'=>$action==='telegram_cancel'?($cancelledCount>0?'Ожидающая отправка отменена.':'Ожидающей отправки уже нет. Состояние обновлено ниже.'):'Отправка запланирована на '.date('d.m.Y H:i',DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$_POST['telegram_due'],new DateTimeZone('Asia/Novosibirsk'))->getTimestamp()).' (UTC+7).'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
                }
                if ($action==='autosave') { header('Content-Type: application/json'); echo json_encode(['revision'=>$p['revision'],'saved'=>date('H:i:s')]);exit; }
                redirect('/admin.php?view=edit&id='.$id.'&saved=1'.(in_array($action,['illustration_delete','illustration_dispatch','illustrate','illustration_apply'],true)?'#illustrations':(in_array($action,['telegram','telegram_schedule','telegram_cancel','resolve'],true)?'#telegram':'' )));
            }
            if ($action==='settings') {
                $title=trim((string)($_POST['title']??''));if ($title==='') throw new RuntimeException('У сайта должно быть название.');
                $url=trim((string)($_POST['telegram_url']??''));
                if ($url!=='' && !preg_match('~^https://t\.me/[A-Za-z0-9_]+$~',$url)) throw new RuntimeException('Ссылка канала должна начинаться с https://t.me/');
                $support=trim((string)($_POST['support_url']??setting('support_url')));
                if($support!=='' && reader_support_url($support)==='')throw new RuntimeException('Ссылка поддержки должна начинаться с https:// и не содержать логина или пароля.');
                $supportText=trim((string)($_POST['support_text']??setting('support_text')));if(mb_strlen($supportText)>2000)throw new RuntimeException('Текст поддержки — максимум 2000 символов.');
                $db->beginTransaction();
                $q=$db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
                foreach (['title'=>$title,'description'=>trim((string)($_POST['description']??'')),'telegram_url'=>$url,'support_url'=>$support,'support_text'=>$supportText,'indexed'=>isset($_POST['indexed'])?'1':'0'] as $k=>$v) $q->execute([$k,$v]);
                $db->commit();audit('settings');redirect('/admin.php?view=settings&saved=1');
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error=$e instanceof RuntimeException?$e->getMessage():'Не удалось выполнить действие. Текст сохранён в форме; скопируй его перед обновлением страницы.';
            if ($telegramInline) { http_response_code(409);header('Content-Type: application/json');echo json_encode(['error'=>$error,'revision'=>$savedRevision],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit; }
            if ($action==='autosave') { http_response_code(409); header('Content-Type: application/json');echo json_encode(['error'=>$error]);exit; }
            if (isset($id)) { $view='edit'; $_GET['id']=$id; $unsaved=$_POST; $unsaved['revision']=$savedRevision ?? ($_POST['revision']??0); }
        }
    }
}
if (!authenticated()) $view='login'; else guard();
page_start('Дао — '.setting('title'),true);
if ($error) echo '<div class="auth-error" role="alert">'.h($error).'</div>';
if (isset($_GET['saved'])) echo '<div class="auth-success">Сохранено.</div>';
if ($view==='login') {
    echo '<div class="auth-title">'.dao_icon('logo').'<span>Дао</span><small>'.h(setting('title')).'</small></div><form method="post">'.csrf().'<input type="hidden" name="action" value="login"><label class="sr-only" for="login">Логин</label><input type="text" id="login" class="auth-input" name="login" placeholder="Логин" autocomplete="username" required><div class="password-wrap"><label class="sr-only" for="password">Пароль</label><input id="password" class="auth-input" type="password" name="password" placeholder="Пароль" autocomplete="current-password" required><button type="button" class="pass-toggle" aria-label="Показать пароль" aria-pressed="false">'.dao_icon('eye').'</button></div><button type="submit" class="auth-btn">Войти</button></form>';

} elseif ($view==='edit' || $view==='preview') {
    try { $p=post((int)($_GET['id']??0)); } catch (Throwable $e) { http_response_code(404); echo '<h1>Запись не найдена</h1>';page_end();exit; }
    if ($view==='preview') {
        echo '<a href="/admin.php?view=edit&id='.$p['id'].'">← В редактор</a><p class="eyebrow">Предпросмотр · '.($p['status']==='draft'?'черновик':'опубликовано').'</p><h1>'.h($p['title']).'</h1>';
        if ($p['cover']) echo '<img class="cover" src="'.h(photo_url($p['cover'])).'" alt="">';
        echo '<div class="prose">'.render_body($p['body']).'</div>';
    } else {
        if ($unsaved) foreach (['title','body','excerpt','telegram_text','revision','category_id'] as $field) if (isset($unsaved[$field])) $p[$field]=$unsaved[$field];
        if ($unsaved && ($unsaved['body_format']??'')==='html') $p['body']=DAO_RICH_PREFIX.sanitize_article_html((string)$unsaved['body']);
        $q=$db->prepare('SELECT * FROM telegram_deliveries WHERE post_id=? AND channel=?');$q->execute([$p['id'],$config['telegram_channel']]);$delivery=$q->fetch();
        echo '<div class="toolbar"><a href="/admin.php">← Записи</a><span class="status-chip">'.($p['status']==='published'?'На сайте':'Черновик').'</span><a target="_blank" href="/admin.php?view=preview&id='.$p['id'].'">Предпросмотр ↗</a><a href="#illustrations">Иллюстрации ↓</a></div>';
        echo '<form id="editor" data-video-limit="'.video_upload_limit().'" class="form-db" data-unsaved="'.($unsaved?'1':'0').'" method="post" enctype="multipart/form-data">'.csrf().'<input type="hidden" name="id" value="'.$p['id'].'"><input type="hidden" name="revision" value="'.$p['revision'].'"><label class="sr-only" for="title">Заголовок</label><input type="text" id="title" class="title-input user-input" name="title" maxlength="250" placeholder="Как назовём эту историю?" value="'.h($p['title']).'"><div class="post-category-field"><label for="category_id">Категория</label><select class="user-input" id="category_id" name="category_id">'.category_options((int)$p['category_id']).'</select></div><input type="hidden" name="body_format" value="html"><label class="sr-only" for="body">Текст</label><textarea id="body" class="writing user-input" name="body" placeholder="Что у тебя на душе?">'.h(body_for_editor($p['body'])).'</textarea><div class="savebar"><button type="submit" class="btn-main" name="action" value="save">Сохранить</button><span id="save-status" role="status">'.($unsaved?'Есть несохранённые изменения':'Все изменения сохранены').'</span><button class="btn-secondary" name="action" value="'.($p['status']==='published'?'unpublish':'publish').'">'.($p['status']==='published'?'Вернуть в черновики':'Опубликовать на сайте').'</button></div>';
        echo '<details><summary>Фотографии</summary><p class="hint">JPEG, PNG, WebP до 12 МБ. Загрузка сохраняет и текст записи.</p>';
        if ($p['cover']) echo '<img class="thumb" src="'.h(photo_url($p['cover'])).'" alt="Обложка">';
        echo '<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"><select class="user-input" name="photo_kind"><option value="cover">Обложка</option><option value="inline">Вставить в текст</option></select><button class="btn-secondary" name="action" value="upload">Добавить фото</button><p class="hint">Фотографии в текст удобнее добавлять кнопкой «Фото» на панели редактора или перетаскиванием файла. Здесь можно загрузить обложку.</p></details>';
        echo '<details><summary>Анонс в ленте</summary><label>Короткое вступление<textarea class="user-input" name="excerpt" rows="3" placeholder="Если оставить пустым — возьмём начало истории">'.h($p['excerpt']).'</textarea></label></details>';
        telegram_editor($p,$delivery?:null);
        echo '</form>';
        illustration_editor($p);
        echo '<script src="/assets/tinymce/tinymce.min.js?v=8.5.0" defer></script><script src="/assets/js/editor-clipboard.js?v=1" defer></script><script src="/assets/js/editor.js?v=15" defer></script>';
    }
} elseif ($view==='illustrator') {
    illustration_settings_page();
} elseif ($view==='settings') {
    echo '<p class="eyebrow">Твой уголок</p><h1>Настройки</h1><form method="post">'.csrf().'<input type="hidden" name="action" value="settings"><label>Название сайта<input type="text" class="user-input" name="title" required value="'.h(setting('title')).'"></label><label>Описание<textarea class="user-input" name="description" rows="3">'.h(setting('description')).'</textarea></label><label>Ссылка на Telegram-канал<input type="text" class="user-input" name="telegram_url" placeholder="https://t.me/channel" value="'.h(setting('telegram_url')).'"></label><label>Ссылка для поддержки<input class="user-input" type="url" name="support_url" value="'.h(setting('support_url')).'" placeholder="https://…"></label><label>Текст на странице поддержки<textarea class="user-input" name="support_text" rows="3" maxlength="2000">'.h(setting('support_text','Если тебе здесь хорошо — можно поддержать автора. Чтобы у Гены и Винни всегда находилось время на разговор.')).'</textarea></label><label><input type="checkbox" name="indexed" value="1"'.(setting('indexed')==='1'?' checked':'').'> Разрешить индексацию поисковиками</label><button type="submit" class="btn-main">Сохранить</button></form><p class="hint">Бот и канал для отправки подключаются в разделе <a href="/admin.php?view=telegram">Telegram</a>. Ссылка выше — только ссылка для читателей сайта.</p>';
} elseif ($view==='feedback') {
    echo '<h1>Обратная связь</h1><p class="hint">Личные сообщения читателей сайта. Контакты не публикуются.</p>';
    $messages=$db->query('SELECT * FROM reader_feedback ORDER BY read_at IS NULL DESC,id DESC LIMIT 100')->fetchAll();
    if(!$messages)echo '<p>Сообщений пока нет.</p>';
    foreach($messages as $message){
        echo '<details'.($message['read_at']===null?' open':'').'><summary>'.($message['read_at']===null?'Новое · ':'Прочитано · ').h(date('d.m.Y H:i',(int)$message['created_at'])).'</summary><p>'.nl2br(h($message['message'])).'</p><p>Контакт: '.h($message['contact']?:'не указан').'</p>';
        if($message['read_at']===null)echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="feedback_read"><input type="hidden" name="message_id" value="'.(int)$message['id'].'"><button class="btn-secondary" type="submit">Отметить прочитанным</button></form>';
        echo '</details>';
    }
} elseif ($view==='telegram') {
    telegram_page();
} elseif ($view==='service') {
    echo '<h1>Обслуживание</h1><p>База: SQLite · схема '.h($db->query('SELECT max(version) FROM schema_versions')->fetchColumn()).'</p><p>Telegram: '.(!empty($config['telegram_token']) && !empty($config['telegram_channel'])?'настроен':'ещё не подключён').'</p><p>Резервная копия базы и фотографий через SSH:</p><pre>php tools/backup.php</pre><p>Обновление кода с резервной копией:</p><pre>~/zen-sync.sh</pre><h2>Последние действия</h2><ul class="audit">';
    foreach ($db->query('SELECT * FROM audit ORDER BY id DESC LIMIT 30') as $row) echo '<li>'.h($row['created_at']).' · '.h($row['action']).($row['post_id']?' · запись '.(int)$row['post_id']:'').'</li>';
    echo '</ul>';
} else {
    echo '<div class="section-head"><div><p class="eyebrow">Мастерская автора</p><h1>Мои истории</h1></div><form method="post">'.csrf().'<button type="submit" class="btn-main" name="action" value="new">＋ Написать</button></form></div>';
    $filter=(string)($_GET['status']??'');if (!in_array($filter,['draft','published'],true))$filter='';
    $categorySlug=(string)($_GET['category']??'');$categorySuffix=$categorySlug!==''?'&amp;category='.h(rawurlencode($categorySlug)):'';
    echo '<nav class="tabs"><a href="/admin.php'.($categorySlug!==''?'?category='.h(rawurlencode($categorySlug)):'').'">Все</a><a href="/admin.php?status=draft'.$categorySuffix.'">Черновики и заметки</a><a href="/admin.php?status=published'.$categorySuffix.'">На сайте</a></nav>';
    $categorySlug=(string)($_GET['category']??'');$category=$categorySlug!==''?category_by_slug($categorySlug):null;
    echo category_links('/admin.php',array_filter(['status'=>$filter,'category'=>$categorySlug],fn($v)=>$v!==''));
    $where=[];$params=[];
    if($filter){$where[]='p.status=?';$params[]=$filter;}
    if($category){$where[]='pc.category_id=?';$params[]=$category['id'];}
    elseif($categorySlug!=='')$where[]='0=1';
    $channel=(string)($config['telegram_channel']??'');
    $q=$db->prepare('SELECT p.*,c.title AS category_title,d.state AS telegram_state,s.state AS telegram_schedule_state,s.due_at AS telegram_due_at FROM posts p LEFT JOIN post_categories pc ON pc.post_id=p.id LEFT JOIN categories c ON c.id=pc.category_id LEFT JOIN telegram_deliveries d ON d.post_id=p.id AND d.channel=? LEFT JOIN telegram_schedule s ON s.id=(SELECT max(ts.id) FROM telegram_schedule ts WHERE ts.post_id=p.id AND ts.channel=?)'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY p.updated_at DESC LIMIT 100');$q->execute(array_merge([$channel,$channel],$params));$posts=$q->fetchAll();
    if (!$posts) echo '<div class="empty"><h2>Здесь начинается история</h2><p>Сохрани мысль в черновик. Возвращайся к ней, когда захочется.</p></div>';
    $counts=$db->query("SELECT COUNT(*) AS total,SUM(status='draft') AS drafts,SUM(status='published') AS published FROM posts")->fetch();
    echo '<div class="index-stats"><div class="index-stat"><div class="index-stat-value">'.(int)$counts['total'].'</div><div class="index-stat-label">Всего</div></div><div class="index-stat"><div class="index-stat-value">'.(int)$counts['drafts'].'</div><div class="index-stat-label">Черновики</div></div><div class="index-stat"><div class="index-stat-value">'.(int)$counts['published'].'</div><div class="index-stat-label">На сайте</div></div></div>';
    if ($posts) {
        echo '<div class="table-scroll"><table class="index-table"><thead><tr><th>Название</th><th>Сайт</th><th>Telegram</th><th>Изменено</th><th></th></tr></thead><tbody>';
        foreach($posts as $p) echo '<tr><td><a class="post-title" href="/admin.php?view=edit&amp;id='.$p['id'].'">'.h($p['title'] ?: 'Пока без названия').'</a><p class="post-excerpt">'.h(mb_substr(body_plain($p['body']),0,120)).'</p>'.(!empty($p['category_title'])?'<span class="category-chip">'.h($p['category_title']).'</span>':'').'</td><td><span class="status-chip '.$p['status'].'">'.($p['status']==='draft'?'Черновик':'На сайте').'</span></td><td>'.telegram_list_indicator($p).'</td><td>'.h(date('d.m.Y H:i',strtotime($p['updated_at']))).'</td><td><a class="btn-edit" href="/admin.php?view=edit&amp;id='.$p['id'].'">Открыть в редакторе</a></td></tr>';
        echo '</tbody></table></div>';
    }

}
page_end();

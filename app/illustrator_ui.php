<?php
declare(strict_types=1);
function illustration_editor(array $p): void {
    global $db,$storage;
    echo '<section id="illustrations" class="illustrator"><h2>Иллюстрации к истории</h2><p class="hint">Иллюстратор найдёт главную мысль текста и выразит её одним визуальным образом. Каждый запуск создаёт один вариант. Текст сохранится перед запуском.</p>';
    $profile=illustration_style_profile((string)($p['category_slug']??''));
    echo '<p class="hint">Стиль по категории: '.h($profile['title']??'общая техника и палитра из настроек').'.</p>';
    if(illustration_key()==='')echo '<p><a href="/admin.php?view=illustrator">Подключить OpenRouter →</a></p>';
    echo '<label for="illustration_wishes">Пожелания художнику</label><textarea id="illustration_wishes" form="editor" class="user-input" name="illustration_wishes" rows="3" maxlength="2000" placeholder="Можно оставить пустым. Или: подчеркни парадокс, больше пустоты, тонкая ирония…"></textarea><button form="editor" class="btn-main" name="action" value="illustrate"'.(illustration_key()===''?' disabled':'').'>Проиллюстрировать / ещё вариант</button> <a href="/admin.php?view=illustrator">Стиль иллюстраций</a>';
    if(illustration_backend()==='actions')echo '<p class="hint">Обработка: GitHub Actions. Тексты и картинки передаются по SSH.</p><button form="editor" class="btn-secondary" name="action" value="illustration_dispatch">Запустить ожидающую очередь</button> <a href="https://github.com/'.ILLUSTRATOR_REPOSITORY.'/actions/workflows/illustrator.yml" target="_blank" rel="noopener">Состояние в GitHub ↗</a>';
    $heartbeat=is_file($storage.'/illustrator-heartbeat')?(int)file_get_contents($storage.'/illustrator-heartbeat'):0;
    if(illustration_backend()!=='actions' && time()-$heartbeat>180)echo '<p class="hint">Фоновый обработчик ещё не подключён или давно не запускался. Настройка — в разделе «Иллюстратор».</p>';
    $q=$db->prepare('SELECT * FROM illustration_jobs WHERE post_id=? ORDER BY id DESC LIMIT 30');$q->execute([$p['id']]);
    $jobs=$q->fetchAll();$active=false;
    echo '<div class="illustration-grid" id="illustration-history" data-post="'.(int)$p['id'].'">';
    foreach($jobs as $job){
        $running=in_array($job['state'],['queued','planning','generating'],true);$active=$active||$running;
        echo '<article class="illustration-card"><p><strong>Вариант '.$job['id'].'</strong> · '.h(['queued'=>'В очереди','planning'=>'Читает историю','generating'=>'Рисует','done'=>'Готово','failed'=>'Не завершён','unknown'=>'Нужна проверка'][$job['state']]??$job['state']).'</p><p class="hint">'.h($job['created_at']).' · версия текста '.(int)$job['revision'].'</p>';
        if($job['image']){
            echo '<a href="'.h(photo_url($job['image'])).'" target="_blank" rel="noopener"><img loading="lazy" src="'.h(photo_url($job['image'])).'" alt="Вариант иллюстрации"></a><div class="illustration-actions"><button form="editor" class="btn-secondary" name="action" value="illustration_cover:'.$job['id'].'">На обложку</button><button form="editor" class="btn-secondary" name="action" value="illustration_inline:'.$job['id'].'">Добавить в конец текста</button></div>';
        }
        if($job['error'])echo '<p role="status">'.h($job['error']).'</p>';
        if($job['plan']){
            $plan=json_decode($job['plan'],true);
            if(is_array($plan['style_profile']??null))echo '<p class="hint">'.h((string)($plan['style_profile']['title']??'')).'</p>';
            echo '<details><summary>Мысль и визуальный образ</summary><p>'.h(is_string($plan['scene']??null)?$plan['scene']:'').'</p><p>'.h((string)($plan['prompt']??'')).'</p></details>';
        }
        if((float)$job['cost']>0)echo '<p class="hint">Стоимость, сообщённая API: $'.h(number_format((float)$job['cost'],4,'.','')).'</p>';
        echo '</article>';
    }
    echo '</div><p id="illustration-progress" role="status" data-active="'.($active?'1':'0').'">'.($active?'Генерация идёт в фоне. Можно продолжать писать; готовый вариант появится здесь.':'').'</p></section><script src="/assets/js/illustrator.js?v=1" defer></script>';
}
function illustration_settings_page(): void {
    global $storage;
    $s=illustration_settings();
    echo '<p class="eyebrow">Художник твоих историй</p><h1>Иллюстратор</h1><p>Читатель выделяет главную философскую мысль поста и находит для неё визуальную метафору. Художник создаёт одну цельную картинку. Один пост — одна мысль — одна иллюстрация.</p><form method="post" enctype="multipart/form-data">'.csrf().'<input type="hidden" name="action" value="illustrator_settings"><label>Ключ OpenRouter '.(illustration_key()!==''?'· сохранён':'· ещё не задан').'<input class="user-input" type="password" name="api_key" autocomplete="new-password" placeholder="Пустое поле сохраняет прежний ключ"></label><p class="hint">Ключ хранится на сайте и передаётся обработчику по SSH. Один вариант — запрос читателю и запрос художнику. Списание идёт с баланса OpenRouter; лимит расходов можно поставить в настройках ключа.</p>';
    echo '<label>Токен запуска GitHub '.(illustration_github_token()!==''?'· сохранён':'· ещё не задан').'<input class="user-input" type="password" name="github_token" autocomplete="new-password" placeholder="Пустое поле сохраняет прежний токен"></label><p class="hint">Для запуска по кнопке: Fine-grained token, только репозиторий hunta-zen, разрешение Actions — Read and write. OpenRouter-ключ сюда не подходит. <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener">Создать токен ↗</a></p>';
    foreach(['reader'=>'Модель читателя','painter'=>'Модель художника (Image API)'] as $key=>$label)echo '<label>'.h($label).'<input class="user-input" name="'.$key.'" value="'.h($s[$key]).'" required></label>';
    echo '<label>Техника и палитра для постов без категории<textarea class="user-input" name="style" rows="4" maxlength="8000" required>'.h($s['style']).'</textarea></label><p class="hint">Для «Диалогов Винни» закреплены тёплая акварель и мягкий карандаш; для «Диалогов Гены» — холодная тушь и акварель, точные линии. Гамма выбирается по категории автоматически. Поле выше используется для постов без категории. Главную мысль и образ читатель найдёт в самом посте. Описания героев и прежние референсы персонажей в новых запросах не используются.</p><label>Максимум вариантов за день<input class="user-input" type="number" name="daily_limit" min="1" max="50" value="'.(int)$s['daily_limit'].'"></label><button class="btn-main">Сохранить настройки</button></form>';
    if(illustration_backend()==='actions'){
        echo '<section id="probe"><h2>Проверка художника</h2><p>Один платный запрос к Nano Banana 2 через Actions: «Заснеженная равнина на рассвете. Мягкая акварель. Без людей, животных и надписей». Без читателя, настроек стиля, героев и референсов. Очередь рассказов не обрабатывается.</p><form method="post">'.csrf().'<button class="btn-secondary" name="action" value="illustrator_probe">Проверить простым запросом · платно</button></form>';
        echo '<p>Состояние: '.h(['queued'=>'ожидает Actions','running'=>'запрос выполняется','done'=>'получено изображение','failed'=>'проверка не завершена'][setting('illustrator_probe_state')]??'ещё не запускалась').'. Обнови страницу для результата.</p>';
        $probe=json_decode(setting('illustrator_probe_result','{}'),true);
        if(is_array($probe)&&isset($probe['time']))echo '<p>'.h($probe['time']).' · Стоимость: $'.h((string)$probe['cost']).'</p><p>'.h($probe['error']).'</p>';
        if(is_array($probe)&&preg_match('/^[a-f0-9]{32}\.(png|jpg|webp)$/D',(string)($probe['image']??'')))echo '<p><a href="'.h(photo_url($probe['image'])).'" target="_blank" rel="noopener"><img style="max-width:100%;max-height:640px" src="'.h(photo_url($probe['image'])).'" alt="Проверка художника: заснеженная равнина на рассвете"></a></p>';
        echo '</section>';
    }
    illustration_actions_setup();
    $heartbeat=is_file($storage.'/illustrator-heartbeat')?(int)file_get_contents($storage.'/illustrator-heartbeat'):0;
    echo '<p>'.(illustration_backend()==='actions'?'Последний обмен с Actions: ':'Последний запуск: ').($heartbeat?h(date('d.m.Y H:i:s',$heartbeat)):'ещё не запускался').'</p>';
}

function illustration_actions_setup(): void {
    global $storage;
    echo '<h2>Подключение GitHub Actions</h2><p>Режим: <strong>'.(illustration_backend()==='actions'?'GitHub Actions':'локальный обработчик').'</strong></p>';
    if(illustration_backend()!=='actions'){
        echo '<p>Один раз после обновления выполни в SSH:</p><pre>PHP_BIN=/usr/local/bin/php8.2 HUNTA_SSH_USER=altaikemp_zen bash tools/install_illustrator_actions.sh</pre><p class="hint">Создаст отдельный ключ с доступом только к очереди иллюстратора. Существующие SSH-ключи сохранятся.</p>';
        return;
    }
    echo '<ol><li>Открой <a href="https://github.com/'.ILLUSTRATOR_REPOSITORY.'/settings/secrets/actions" target="_blank" rel="noopener">GitHub → Settings → Secrets and variables → Actions</a>.</li><li>В Secrets создай <strong>HUNTA_SSH_KEY</strong>. Скачай ключ ниже, открой как текст и вставь содержимое целиком.</li><li>В Secrets создай <strong>HUNTA_SSH_KNOWN_HOSTS</strong> — содержимое второго файла.</li><li>На вкладке Variables создай <strong>HUNTA_SSH_TARGET</strong>: <code>'.h(setting('illustrator_ssh_target')).'</code>.</li><li>Выше сохрани токен запуска GitHub с доступом Actions — Read and write только к hunta-zen.</li></ol>';
    foreach(['key'=>'Скачать отдельный SSH-ключ','hosts'=>'Скачать known_hosts'] as $kind=>$label){
        $file=$storage.($kind==='key'?'/illustrator-actions-key':'/illustrator-actions-known-hosts');
        if(is_file($file))echo '<form method="post">'.csrf().'<input type="hidden" name="action" value="illustrator_download"><button class="btn-secondary" name="kind" value="'.$kind.'">'.h($label).'</button></form>';
    }
    echo '<p class="hint">Скачивание приватного ключа в админке доступно по HTTPS. Если HTTPS ещё не подключён, скачай storage/illustrator-actions-key через защищённый файловый менеджер Beget. Приватный ключ вставляй только в GitHub Secrets. Не добавляй эти файлы в Git и не присылай их в чат. Ключ ограничен очередью иллюстратора: обычные команды SSH ему недоступны.</p><p><a href="https://github.com/'.ILLUSTRATOR_REPOSITORY.'/actions/workflows/illustrator.yml" target="_blank" rel="noopener">Открыть запуски иллюстратора ↗</a></p>';
}

<?php
declare(strict_types=1);
function illustration_editor(array $p): void {
    global $db,$storage;
    echo '<section id="illustrations" class="illustrator"><h2>Иллюстрации к истории</h2><p class="hint">Иллюстратор прочитает весь текст и выберет одну сцену. Каждый запуск создаёт один вариант. Текст сохранится перед запуском.</p>';
    if(illustration_key()==='')echo '<p><a href="/admin.php?view=illustrator">Подключить OpenRouter →</a></p>';
    echo '<label for="illustration_wishes">Пожелания художнику</label><textarea id="illustration_wishes" form="editor" class="user-input" name="illustration_wishes" rows="3" maxlength="2000" placeholder="Можно оставить пустым. Или: вечер у костра, без пафоса, чуть больше иронии…"></textarea><button form="editor" class="btn-main" name="action" value="illustrate"'.(illustration_key()===''?' disabled':'').'>Проиллюстрировать / ещё вариант</button> <a href="/admin.php?view=illustrator">Стиль и герои</a>';
    $heartbeat=is_file($storage.'/illustrator-heartbeat')?(int)file_get_contents($storage.'/illustrator-heartbeat'):0;
    if(time()-$heartbeat>180)echo '<p class="hint">Фоновый обработчик ещё не подключён или давно не запускался. Настройка — в разделе «Иллюстратор».</p>';
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
            echo '<details><summary>Выбранная сцена</summary><p>'.h(is_string($plan['scene']??null)?$plan['scene']:'').'</p><p>'.h((string)($plan['prompt']??'')).'</p></details>';
        }
        if((float)$job['cost']>0)echo '<p class="hint">Стоимость, сообщённая API: $'.h(number_format((float)$job['cost'],4,'.','')).'</p>';
        echo '</article>';
    }
    echo '</div><p id="illustration-progress" role="status" data-active="'.($active?'1':'0').'">'.($active?'Генерация идёт в фоне. Можно продолжать писать; готовый вариант появится здесь.':'').'</p></section><script src="/assets/js/illustrator.js?v=1" defer></script>';
}
function illustration_settings_page(): void {
    global $storage;
    $s=illustration_settings();
    echo '<p class="eyebrow">Художник твоих историй</p><h1>Иллюстратор</h1><p>Агент «Читатель → Художник»: сначала разбирает рассказ, затем рисует одну сцену. Общий стиль, описания и референсы помогают сохранять внешность героев.</p><form method="post" enctype="multipart/form-data">'.csrf().'<input type="hidden" name="action" value="illustrator_settings"><label>Ключ OpenRouter '.(illustration_key()!==''?'· сохранён':'· ещё не задан').'<input class="user-input" type="password" name="api_key" autocomplete="new-password" placeholder="Пустое поле сохраняет прежний ключ"></label><p class="hint">Ключ хранится только на сервере. Один вариант — запрос читателю и запрос художнику. Списание идёт с баланса OpenRouter; лимит расходов можно поставить в настройках ключа.</p>';
    foreach(['reader'=>'Модель читателя','painter'=>'Модель художника (Image API)'] as $key=>$label)echo '<label>'.h($label).'<input class="user-input" name="'.$key.'" value="'.h($s[$key]).'" required></label>';
    echo '<label>Общий стиль<textarea class="user-input" name="style" rows="4" maxlength="8000" required>'.h($s['style']).'</textarea></label><label>Кто такие Гена, Винни и другие герои<textarea class="user-input" name="characters" rows="6" maxlength="8000" placeholder="Для каждого: кто он, внешность, одежда, характер. Необязательно заполнять сразу.">'.h($s['characters']).'</textarea></label><p class="hint">Имена сами по себе не определяют внешность. Здесь можно описать собственных героев и прикрепить их образец или общий лист персонажей.</p><div class="illustration-references">';
    foreach($s['references'] as $name)echo '<img class="thumb" src="'.h(photo_url($name)).'" alt="Референс персонажей">';
    echo '</div><label>Добавить референс (до трёх, каждый до 4 МБ)<input type="file" name="reference" accept="image/png,image/jpeg,image/webp"></label><label><input type="checkbox" name="clear_references" value="1"> Убрать прежние референсы из будущих генераций</label><label>Максимум вариантов за день<input class="user-input" type="number" name="daily_limit" min="1" max="50" value="'.(int)$s['daily_limit'].'"></label><button class="btn-main">Сохранить настройки</button></form><h2>Фоновая работа</h2><p>Один раз после обновления выполни через SSH:</p><pre>PHP_BIN=/usr/local/bin/php8.2 bash tools/install_illustrator.sh</pre><p class="hint">Скрипт добавит обработчик в cron раз в минуту, сохранив остальные задания. Генерация продолжится, даже если закрыть страницу.</p>';
    $heartbeat=is_file($storage.'/illustrator-heartbeat')?(int)file_get_contents($storage.'/illustrator-heartbeat'):0;
    echo '<p>Последний запуск: '.($heartbeat?h(date('d.m.Y H:i:s',$heartbeat)):'ещё не запускался').'</p>';
}

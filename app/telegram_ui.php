<?php
declare(strict_types=1);
function telegram_status_label(string $state): string {
    return ['sent'=>'Отправлено','sending'=>'Проверь канал: отправка не завершена','unknown'=>'Проверь канал: результат неизвестен','failed'=>'Отправка остановлена','scheduled'=>'Запланировано','running'=>'Отправка началась','cancelled'=>'Отменено'][$state]??'Ещё не отправлено';
}
/** Delivery records describe the selected channel, not live Telegram membership. */
function telegram_list_indicator(array $row): string {
    $delivery=(string)($row['telegram_state']??'');
    $schedule=(string)($row['telegram_schedule_state']??'');
    $kind='none';$label='Не размещено';$note='';
    $hint='Нет подтверждённой отправки из админки в выбранный канал.';
    if($delivery==='sent'||(!empty($row['telegram_message_id'])&&telegram_nontext_message((string)($row['telegram_error']??'')))){
        $kind='sent';$label='✓ Размещено';$hint='Последняя отправка в выбранный канал подтверждена Telegram. Правки на сайте не отправляются автоматически.';
        if(in_array($schedule,['scheduled','running'],true))$note=$schedule==='running'?'Обновление отправляется':'Обновление запланировано';
    }elseif(in_array($delivery,['unknown','sending'],true)){
        $kind='check';$label='Проверь канал';$hint='Результат отправки не подтверждён. Сообщение могло попасть в канал; сначала проверь его.';
    }elseif(in_array($schedule,['scheduled','running'],true)){
        $kind='pending';$label=$schedule==='running'?'Отправляется':'Запланировано';$hint='Публикация ещё не подтверждена.';
    }elseif($delivery==='failed'||$schedule==='failed'){
        $kind='failed';$label='Ошибка отправки';$hint='Отправка остановлена. Подробности и проверка результата — в редакторе.';
    }
    if($schedule==='scheduled' && !empty($row['telegram_due_at']))$hint.=' Назначено на '.(new DateTimeImmutable('@'.(int)$row['telegram_due_at']))->setTimezone(new DateTimeZone('Asia/Novosibirsk'))->format('d.m.Y H:i').' (UTC+7).';
    return '<a class="tg-list-link" href="/admin.php?view=edit&amp;id='.(int)$row['id'].h(telegram_channel_query()).'#telegram" title="'.h($hint).'"><span class="status-chip tg-list-status tg-list-'.$kind.'">'.h($label).'</span>'.($note!==''?'<small class="tg-list-note">'.h($note).'</small>':'').'</a>';
}
function telegram_editor(array $p,?array $delivery): void {
    global $db,$config;
    $channel=(string)($config['telegram_channel']??'');
    $q=$db->prepare("SELECT * FROM telegram_schedule WHERE post_id=? AND channel=? AND state IN ('scheduled','running') ORDER BY id DESC");$q->execute([$p['id'],$channel]);$pending=$q->fetchAll();
    $running=false;foreach($pending as $job)if($job['state']==='running')$running=true;
    $q=$db->prepare("SELECT * FROM telegram_schedule WHERE post_id=? AND channel=? AND state NOT IN ('scheduled','running') ORDER BY id DESC LIMIT 5");$q->execute([$p['id'],$channel]);$history=$q->fetchAll();
    $due=date('Y-m-d\TH:i',time()+3600);
    foreach($pending as $job)if($job['channel']===$channel){$due=date('Y-m-d\TH:i',(int)$job['due_at']);break;}
    $footerOptions='';foreach(['auto'=>'По категории: только Винни и Гена','on'=>'Добавлять','off'=>'Не добавлять'] as $value=>$label)$footerOptions.='<option value="'.$value.'"'.(($p['telegram_footer']??'auto')===$value?' selected':'').'>'.h($label).'</option>';
    $custom=trim((string)$p['telegram_text'])!=='';
    $nontext=$delivery&&!empty($delivery['message_id'])&&telegram_nontext_message((string)$delivery['error']);
    $sent=($delivery['state']??'')==='sent'||$nontext;
    $needsCheck=$delivery && (in_array($delivery['state'],['unknown','sending'],true)||telegram_invalid_message($delivery['error']));
    $connected=$channel!==''&&!empty($config['telegram_token']);
    $options='';foreach(telegram_channels() as $key=>$connection)$options.='<option value="'.h((string)$key).'"'.((string)$key===$channel?' selected':'').'>'.h($connection['telegram_channel_title']??(string)$key).'</option>';
    echo '<details id="telegram" open><summary>Telegram</summary><input type="hidden" name="telegram_destination" value="'.h($channel).'"><label>Канал публикации<select class="user-input" name="telegram_next_channel">'.$options.'</select></label><button type="submit" class="btn-secondary" name="action" value="telegram_select">Выбрать канал</button><p class="hint">Выбор сохранит текст. Отправка, обновление и расписание ниже относятся к выбранному каналу.</p><div class="tg-destination"><span>Канал: <strong>'.h($config['telegram_channel_title']??($channel?:'не подключён')).'</strong></span><a href="/admin.php?view=telegram">Все каналы</a></div><p class="tg-delivery-status"><strong>'.h($sent?'✓ Пост размещён в канале':telegram_status_label((string)($delivery['state']??''))).'</strong></p><p id="telegram-feedback" role="status" aria-live="polite"></p>';
    if(count(telegram_channels())>1){
        echo '<ul class="tg-channel-statuses">';
        $states=$db->prepare('SELECT state,error,message_id FROM telegram_deliveries WHERE post_id=? AND channel=?');
        foreach(telegram_channels() as $key=>$connection){
            $states->execute([$p['id'],(string)$key]);$record=$states->fetch();$state=(string)($record['state']??'');
            if($record&&!empty($record['message_id'])&&telegram_nontext_message((string)$record['error']))$state='sent';
            $queued=$db->prepare("SELECT state FROM telegram_schedule WHERE post_id=? AND channel=? AND state IN ('scheduled','running') LIMIT 1");$queued->execute([$p['id'],(string)$key]);$queue=(string)($queued->fetchColumn()?:'');
            $label=telegram_status_label($state);if($queue!=='')$label=$state==='sent'?$label.' · '.telegram_status_label($queue):telegram_status_label($queue);
            echo '<li>'.h($connection['telegram_channel_title']??(string)$key).' — '.h($label).'</li>';
        }
        echo '</ul>';
    }
    if($nontext)echo '<p class="hint">'.h(telegram_nontext_notice()).'</p>';
    elseif(!empty($delivery['error']))echo '<p class="auth-error tg-delivery-error">'.h($delivery['error']).'</p>';
    if($pending){
        echo '<div class="tg-schedule-current"><strong>Отправка назначена</strong>';
        foreach($pending as $job)echo '<p>'.($job['state']==='running'?'Отправляется':'Ожидает отправки').' · <strong>'.h(date('d.m.Y H:i',(int)$job['due_at'])).'</strong> (UTC+7)'.($job['channel']!==$channel?'<br><span class="hint">В ранее выбранный канал: '.h(telegram_channel_name($job['channel'])).'</span>':'').'</p>';
        if(!$running)echo '<button type="submit" class="btn-secondary" name="action" value="telegram_cancel">'.(count($pending)>1?'Отменить все ожидающие отправки этого поста':'Отменить эту отправку').'</button><p class="hint">Отменяет только расписание выбранного канала. Уже опубликованный пост остаётся в канале.</p>';
        else echo '<p class="hint">Обработчик уже начал отправку. Дождись результата.</p>';
        echo '</div>';
    }
    echo '<label class="tg-footer-setting">Подпись и ссылка на блог<select class="user-input" name="telegram_footer">'.$footerOptions.'</select></label><p class="hint">В режиме «По категории» у остальных постов нет ни подписи «Гена и Винни», ни ссылки. Ссылка доступна только для опубликованного на сайте поста.</p>';
    if($needsCheck){
        echo '<p class="hint">Проверь этот пост в канале. Если он есть — укажи последнее число из ссылки на сообщение. Если удалён или отсутствует — укажи 0. Это только исправит привязку; публикация начнётся после отдельного нажатия отправки.</p><label><input type="checkbox" name="checked_channel" value="1"> Я проверил канал</label><label>Номер сообщения из ссылки; 0, если его нет<input class="user-input" type="number" name="message_id" min="0" value="0"></label><button class="btn-secondary" name="action" value="resolve">Сохранить результат проверки</button>';
    }elseif(!$nontext){
        $disabled=$running||!$connected?' disabled':'';
        if(!$connected)echo '<p class="hint">Сначала подключи канал по ссылке выше.</p>';
        echo '<div class="tg-publish-actions"><button type="submit" class="btn-main" name="action" value="telegram"'.$disabled.'>'.($sent?'Обновить в Telegram':'Отправить сейчас').'</button><details class="tg-schedule-controls"><summary>'.($pending?'Изменить отложенную отправку':'Отложить отправку').'</summary><label>Дата и время · Новосибирск (UTC+7)<input class="user-input" type="datetime-local" name="telegram_due" value="'.h($due).'"'.$disabled.'></label><button type="submit" class="btn-secondary" name="action" value="telegram_schedule"'.$disabled.'>'.($pending?'Обновить отложенную отправку':'Запланировать отправку').'</button><p class="hint">Отправится версия текста на момент нажатия этой кнопки. После правок обнови отложенную отправку.</p></details></div><p class="hint">'.($sent?'Кнопка обновит существующее сообщение.':'Публикация на сайте не обязательна.').($custom?' Отправится особый текст для Telegram из настроек ниже.':' Отправится весь рассказ с фотографиями и видео.').($pending?' Отправка сейчас отменит ожидание по расписанию.':'').'</p>';
    }
    if(!$delivery&&$connected)echo '<details class="tg-existing-post"><summary>Пост уже есть в этом канале?</summary><p class="hint">Открой нужное сообщение в выбранном канале, скопируй ссылку и возьми последнее число: например, в https://t.me/c/123456/35 номер сообщения — 35.</p><label>Тип существующего сообщения<select class="user-input" name="existing_message_kind"><option value="text">Текстовый пост или иллюстрация внутри текста</option><option value="media">Фото / видео с подписью — только привязка</option></select></label><label>Номер существующего сообщения<input class="user-input" type="number" name="existing_message_id" min="1" max="2147483647" step="1" placeholder="35"></label><label><input type="checkbox" name="existing_checked" value="1"> Я проверил: это тот же пост в выбранном канале</label><button type="submit" class="btn-secondary" name="action" value="telegram_bind">Привязать существующий пост</button><p class="hint">Привязка ничего не отправляет и не меняет в Telegram. Текстовый пост можно затем обновлять. Фото или видео с подписью останется привязанным, его редактируют вручную в Telegram. Удаление из админки относится к привязанному сообщению. Номер подтверждаешь ты — содержимое сообщения здесь не проверяется.</p></details>';
    echo '<details class="tg-content-options"><summary>'.($custom?'Особый текст для Telegram — включён':'Текст и предпросмотр').'</summary><p class="hint">По умолчанию отправляется рассказ из редактора. Поле ниже заменяет его текст только для Telegram.</p><label>Особый текст для канала (необязательно)<textarea class="user-input" name="telegram_text" rows="4" placeholder="Оставь пустым, чтобы отправить рассказ целиком">'.h($p['telegram_text']).'</textarea></label><details><summary>Предпросмотр сохранённого текста</summary><pre class="tg-preview">'.h(telegram_text($p)).'</pre></details></details>';
    if($history){
        echo '<details class="tg-schedule-history"><summary>История отправок — последние '.count($history).'</summary><p class="hint">Это прошлые задачи этого же поста, а не дополнительные публикации в очереди.</p><ul>';
        foreach($history as $job)echo '<li><strong>'.h(telegram_status_label($job['state'])).'</strong> · '.h(date('d.m.Y H:i',(int)$job['due_at'])).($job['error']!==''?'<br><span class="hint">'.h($job['error']).'</span>':'').'</li>';
        echo '</ul></details>';
    }
    echo '</details>';
}
function telegram_page(): void {
    global $config,$db,$storage;
    $channel=(string)($config['telegram_channel']??'');
    echo '<h1>Telegram</h1><h2>Каналы</h2><p>Тексты общие, публикации и расписание отдельные для каждого канала.</p>';
    foreach(telegram_channels() as $key=>$connection){
        echo '<div class="tg-channel-card"><h3>'.h($connection['telegram_channel_title']??(string)$key).'</h3><p>'.h((string)$key).((string)$key===$channel?' · выбран':'').((string)$key===($config['telegram_default_channel']??$channel)?' · по умолчанию':'').'</p><p><a class="btn-secondary" href="/admin.php?view=telegram&amp;channel='.rawurlencode((string)$key).'">Открыть канал</a></p><form method="post">'.csrf().'<input type="hidden" name="action" value="telegram_default"><input type="hidden" name="default_channel" value="'.h((string)$key).'"><button class="btn-secondary" type="submit">Сделать каналом по умолчанию</button></form></div>';
    }
    echo '<h2>Добавить или обновить канал</h2><p>Добавь бота администратором каждого канала с правами публикации, редактирования и удаления сообщений. Можно использовать одного бота для всех каналов.</p><form method="post" autocomplete="off">'.csrf().'<input type="hidden" name="action" value="telegram_connection"><input type="hidden" name="telegram_destination" value="'.h($channel).'"><label>Название в админке<input class="user-input" type="text" name="channel_label" maxlength="100" placeholder="Основной или Тестовый"></label><label>Канал<input class="user-input" type="text" name="telegram_channel" placeholder="@имя_канала или -100…" required></label><label>Токен бота<input class="user-input" type="password" name="telegram_token" autocomplete="new-password" placeholder="'.(!empty($config['telegram_token'])?'Оставь пустым для бота выбранного канала':'Токен из BotFather').'"></label><label><input type="checkbox" name="make_default" value="1"> Использовать этот канал по умолчанию</label><button class="btn-main" type="submit">Проверить и сохранить канал</button><p class="hint">Проверка не публикует сообщений. Другие каналы, их сообщения и задания сохраняются. Чтобы обновить подключение, укажи тот же адрес канала. Секреты хранятся только на сервере.</p></form>';
    $heartbeat=is_file($storage.'/telegram-heartbeat')?(int)file_get_contents($storage.'/telegram-heartbeat'):0;
    echo '<h2>Отложенная отправка</h2><p>'.($heartbeat>time()-180?'Обработчик работает. Последняя проверка: '.h(date('H:i:s',$heartbeat)):'Обработчик сейчас не подтверждён. Пока он не запущен, отложенные публикации останутся в очереди.').'</p><p>Один раз добавь в панели Beget → Cron задачу каждую минуту:</p><pre>'.h('/usr/local/bin/php8.2 '.realpath(ROOT).'/tools/telegram_worker.php').'</pre><p class="hint">Команда работает на хостинге, без расхода минут GitHub Actions. При сетевой ошибке автоматического повтора нет: сначала проверь канал. Зависшие задания не отправляются повторно.</p>';
    $q=$db->query("SELECT s.*,p.title FROM telegram_schedule s JOIN posts p ON p.id=s.post_id ORDER BY s.id DESC LIMIT 30");
    echo '<div class="table-scroll"><table class="index-table"><thead><tr><th>Запись</th><th>Когда (UTC+7)</th><th>Состояние</th></tr></thead><tbody>';
    foreach($q as $job)echo '<tr><td><a href="/admin.php?view=edit&amp;id='.(int)$job['post_id'].'&amp;channel='.rawurlencode($job['channel']).'#telegram">'.h($job['title']).'</a><br><small>'.h(telegram_channel_name($job['channel'])).'</small></td><td>'.h(date('d.m.Y H:i',(int)$job['due_at'])).'</td><td>'.h(telegram_status_label($job['state'])).($job['error']?'<br>'.h($job['error']):'').'</td></tr>';
    echo '</tbody></table></div><h2>Записи в канале: '.h(telegram_channel_name($channel)).'</h2><div class="table-scroll"><table class="index-table"><thead><tr><th>Запись</th><th>В выбранном канале</th><th></th></tr></thead><tbody>';
    $q=$db->prepare("SELECT p.*,d.state,d.error,d.message_id FROM posts p LEFT JOIN telegram_deliveries d ON d.post_id=p.id AND d.channel=? WHERE trim(p.title)<>'' ORDER BY p.published_at DESC LIMIT 100");$q->execute([$channel]);
    foreach($q as $row)echo '<tr><td>'.h($row['title']).'</td><td>'.h(telegram_status_label(!empty($row['message_id'])&&telegram_nontext_message((string)($row['error']??''))?'sent':(string)($row['state']??''))).'</td><td><a class="btn-edit" href="/admin.php?view=edit&amp;id='.(int)$row['id'].h(telegram_channel_query()).'#telegram">Подготовить отправку</a></td></tr>';
    echo '</tbody></table></div>';
}

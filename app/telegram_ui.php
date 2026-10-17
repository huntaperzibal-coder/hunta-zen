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
    if($delivery==='sent'){
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
    return '<a class="tg-list-link" href="/admin.php?view=edit&amp;id='.(int)$row['id'].'#telegram" title="'.h($hint).'"><span class="status-chip tg-list-status tg-list-'.$kind.'">'.h($label).'</span>'.($note!==''?'<small class="tg-list-note">'.h($note).'</small>':'').'</a>';
}
function telegram_editor(array $p,?array $delivery): void {
    global $db,$config;
    $channel=(string)($config['telegram_channel']??'');
    $q=$db->prepare("SELECT * FROM telegram_schedule WHERE post_id=? AND state IN ('scheduled','running') ORDER BY id DESC");$q->execute([$p['id']]);$pending=$q->fetchAll();
    $running=false;foreach($pending as $job)if($job['state']==='running')$running=true;
    $q=$db->prepare("SELECT * FROM telegram_schedule WHERE post_id=? AND state NOT IN ('scheduled','running') ORDER BY id DESC LIMIT 5");$q->execute([$p['id']]);$history=$q->fetchAll();
    $due=date('Y-m-d\TH:i',time()+3600);
    foreach($pending as $job)if($job['channel']===$channel){$due=date('Y-m-d\TH:i',(int)$job['due_at']);break;}
    echo '<details id="telegram"><summary>Публикация в Telegram</summary><p class="hint">Канал: <strong>'.h($config['telegram_channel_title']??($channel?:'не подключён')).'</strong> · <a href="/admin.php?view=telegram">Подключение и очередь</a></p><p><strong>В канале: '.h(($delivery['state']??'')==='sent'?'размещено':telegram_status_label((string)($delivery['state']??''))).'</strong></p><p id="telegram-feedback" role="status" aria-live="polite"></p>';
    if($pending){
        echo '<div class="tg-schedule-current"><strong>Текущая отложенная отправка</strong>';
        foreach($pending as $job)echo '<p>'.($job['state']==='running'?'Отправляется':'Ожидает отправки').' · <strong>'.h(date('d.m.Y H:i',(int)$job['due_at'])).'</strong> (UTC+7)'.($job['channel']!==$channel?'<br><span class="hint">В ранее выбранный канал: '.h($job['channel']).'</span>':'').'</p>';
        if(!$running)echo '<button type="submit" class="btn-secondary" name="action" value="telegram_cancel">'.(count($pending)>1?'Отменить все ожидающие отправки этого поста':'Отменить эту отправку').'</button><p class="hint">Снимает этот пост с расписания. Текст на сайте и уже отправленные сообщения сохраняются.</p>';
        else echo '<p class="hint">Обработчик уже начал отправку. Дождись результата.</p>';
        echo '</div>';
    }else echo '<p class="hint tg-schedule-empty">Отложенной отправки нет.</p>';
    echo '<label>Другой текст для канала (необязательно)<textarea class="user-input" name="telegram_text" rows="4" placeholder="Оставь пустым — отправится рассказ целиком с форматированием">'.h($p['telegram_text']).'</textarea></label><details><summary>Посмотреть текст отправки</summary><pre class="tg-preview">'.h(telegram_text($p)).'</pre></details>';
    if($delivery && in_array($delivery['state'],['unknown','sending'],true)){
        echo '<p class="hint">Проверь, появилось ли сообщение в канале. До проверки повторная отправка остановлена.</p><label><input type="checkbox" name="checked_channel" value="1"> Я проверил канал</label><label>Номер сообщения из ссылки; 0, если его нет<input class="user-input" type="number" name="message_id" min="0" value="0"></label><button class="btn-secondary" name="action" value="resolve">Сохранить результат проверки</button>';
    }else{
        $disabled=$p['status']!=='published'||$running?' disabled':'';
        echo '<div class="tg-schedule-controls"><label>Дата и время отправки (Новосибирск, UTC+7)<input class="user-input" type="datetime-local" name="telegram_due" value="'.h($due).'"'.$disabled.'></label><button type="submit" class="btn-main" name="action" value="telegram_schedule"'.$disabled.'>'.($pending?'Обновить отложенную отправку':'Запланировать отправку').'</button><p class="hint">Отправится сохранённая сейчас версия текста. Чтобы учесть новые правки или изменить время, обнови отложенную отправку.</p></div><details><summary>Отправить сейчас</summary><button type="submit" class="btn-secondary" name="action" value="telegram"'.$disabled.'>'.(($delivery['state']??'')==='sent'?'Обновить публикацию в Telegram сейчас':'Отправить в Telegram сейчас').'</button><p class="hint">Сразу отправит этот пост и отменит его ожидающую отправку по расписанию.</p></details>';
    }
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
    echo '<h1>Telegram</h1><h2>Подключение канала</h2><p>Сначала подключи тестовый канал. Создай бота через <a href="https://t.me/BotFather" target="_blank" rel="noopener">BotFather</a>, добавь его администратором канала с правами публикации и редактирования сообщений.</p><p>Сейчас: <strong>'.h($config['telegram_channel_title']??($channel?:'канал не подключён')).'</strong>'.(!empty($config['telegram_bot_name'])?' · @'.h($config['telegram_bot_name']):'').'</p><form method="post" autocomplete="off">'.csrf().'<input type="hidden" name="action" value="telegram_connection"><label>Токен бота<input class="user-input" type="password" name="telegram_token" autocomplete="new-password" placeholder="'.(!empty($config['telegram_token'])?'Сохранён. Оставь пустым, чтобы не менять':'Токен из BotFather').'"></label><label>Тестовый канал<input class="user-input" type="text" name="telegram_channel" value="'.h($channel).'" placeholder="@имя_канала или -100…"></label><button class="btn-main" type="submit">Проверить и сохранить подключение</button><p class="hint">Проверка не публикует сообщений. Токен хранится в закрытом файле на сервере и не показывается в форме. При смене канала ожидающие задания не перенаправляются в новый канал.</p></form>';
    $heartbeat=is_file($storage.'/telegram-heartbeat')?(int)file_get_contents($storage.'/telegram-heartbeat'):0;
    echo '<h2>Отложенная отправка</h2><p>'.($heartbeat>time()-180?'Обработчик работает. Последняя проверка: '.h(date('H:i:s',$heartbeat)):'Обработчик сейчас не подтверждён. Пока он не запущен, отложенные публикации останутся в очереди.').'</p><p>Один раз добавь в панели Beget → Cron задачу каждую минуту:</p><pre>'.h('/usr/local/bin/php8.2 '.realpath(ROOT).'/tools/telegram_worker.php').'</pre><p class="hint">Команда работает на хостинге, без расхода минут GitHub Actions. При сетевой ошибке автоматического повтора нет: сначала проверь канал. Зависшие задания не отправляются повторно.</p>';
    $q=$db->query("SELECT s.*,p.title FROM telegram_schedule s JOIN posts p ON p.id=s.post_id ORDER BY s.id DESC LIMIT 30");
    echo '<div class="table-scroll"><table class="index-table"><thead><tr><th>Запись</th><th>Когда (UTC+7)</th><th>Состояние</th></tr></thead><tbody>';
    foreach($q as $job)echo '<tr><td><a href="/admin.php?view=edit&amp;id='.(int)$job['post_id'].'#telegram">'.h($job['title']).'</a><br><small>'.h($job['channel']).'</small></td><td>'.h(date('d.m.Y H:i',(int)$job['due_at'])).'</td><td>'.h(telegram_status_label($job['state'])).($job['error']?'<br>'.h($job['error']):'').'</td></tr>';
    echo '</tbody></table></div><h2>Записи в канале</h2><div class="table-scroll"><table class="index-table"><thead><tr><th>Запись</th><th>В выбранном канале</th><th></th></tr></thead><tbody>';
    $q=$db->prepare("SELECT p.*,d.state FROM posts p LEFT JOIN telegram_deliveries d ON d.post_id=p.id AND d.channel=? WHERE p.status='published' ORDER BY p.published_at DESC LIMIT 100");$q->execute([$channel]);
    foreach($q as $row)echo '<tr><td>'.h($row['title']).'</td><td>'.h(telegram_status_label((string)($row['state']??''))).'</td><td><a class="btn-edit" href="/admin.php?view=edit&amp;id='.(int)$row['id'].'#telegram">Подготовить отправку</a></td></tr>';
    echo '</tbody></table></div>';
}

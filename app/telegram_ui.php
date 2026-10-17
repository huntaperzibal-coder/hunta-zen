<?php
declare(strict_types=1);
function telegram_status_label(string $state): string {
    return ['sent'=>'Отправлено','sending'=>'Проверь канал: отправка не завершена','unknown'=>'Проверь канал: результат неизвестен','failed'=>'Отправка остановлена','scheduled'=>'Запланировано','running'=>'Отправка началась','cancelled'=>'Отменено'][$state]??'Ещё не отправлено';
}
function telegram_editor(array $p,?array $delivery): void {
    global $db,$config;
    $channel=(string)($config['telegram_channel']??'');
    echo '<details id="telegram"><summary>Публикация в Telegram</summary><p class="hint">Канал: <strong>'.h($config['telegram_channel_title']??($channel?:'не подключён')).'</strong> · <a href="/admin.php?view=telegram">Подключение и очередь</a></p><p class="hint">'.h(telegram_status_label((string)($delivery['state']??''))).'. Полный текст и ссылка на блог. Иллюстрация загружается отдельно от предпросмотра ссылки. Всё в одном сообщении: подпись серии → иллюстрация → заголовок и полный рассказ → ссылка на блог.</p><label>Другой текст для канала (необязательно)<textarea class="user-input" name="telegram_text" rows="4" placeholder="Оставь пустым — отправится рассказ целиком с форматированием">'.h($p['telegram_text']).'</textarea></label><p class="hint">Отдельный текст отправляется без форматирования. Основной рассказ сохраняет форматирование. Лимит одного составного сообщения — 32768 символов и 500 блоков. Старый фотопост при обновлении заменяется новым сообщением; прежнее удаляется только после подтверждения отправки. Превью ниже соответствует последнему сохранению.</p><details><summary>Посмотреть полный текст отправки</summary><pre class="tg-preview">'.h(telegram_text($p)).'</pre></details>';
    if($delivery && in_array($delivery['state'],['unknown','sending'],true)){
        $q=$db->prepare("SELECT kind,ordinal,operation FROM telegram_parts WHERE post_id=? AND channel=? AND state='sending' LIMIT 1");$q->execute([$p['id'],$channel]);$part=$q->fetch();
        if($part)echo '<p>Проверь '.($part['kind']==='photo'?'сообщение с иллюстрацией':'текстовую часть № '.((int)$part['ordinal']+1)).($part['operation']==='delete'?' — запрос на удаление':'').'.</p>';
        echo '<label><input type="checkbox" name="checked_channel" value="1"> Я проверил канал</label><label>Номер этого сообщения из ссылки; 0, если его нет<input class="user-input" type="number" name="message_id" min="0" value="0"></label><button class="btn-secondary" name="action" value="resolve">Сохранить результат проверки</button>';
    }else{
        echo '<button type="submit" class="btn-main" name="action" value="telegram"'.($p['status']!=='published'?' disabled':'').'>'.(($delivery['state']??'')==='sent'?'Обновить публикацию в Telegram':'Отправить в Telegram сейчас').'</button><p class="hint">Публикация на сайте ничего не отправляет в канал. Отправка сейчас отменит ожидающую отложенную отправку этой записи.</p><label>Отложить до (Новосибирск, UTC+7)<input class="user-input" type="datetime-local" name="telegram_due" value="'.h(date('Y-m-d\TH:i',time()+3600)).'"></label><button class="btn-secondary" name="action" value="telegram_schedule"'.($p['status']!=='published'?' disabled':'').'>Запланировать отправку</button><p class="hint">Сохраняется текущая версия рассказа и выбранный канал. После правок нажми «Запланировать» заново. Возврат записи в черновики остановит отправку. Время исполнения зависит от запуска обработчика.</p>';
    }
    $q=$db->prepare('SELECT * FROM telegram_schedule WHERE post_id=? ORDER BY id DESC LIMIT 3');$q->execute([$p['id']]);
    foreach($q->fetchAll() as $job){
        echo '<p>'.h(telegram_status_label($job['state'])).' · '.h(date('d.m.Y H:i',(int)$job['due_at'])).' (UTC+7) · канал '.h($job['channel']).($job['error']!==''?'<br>'.h($job['error']):'').'</p>';
        if($job['state']==='scheduled')echo '<button class="btn-secondary" name="action" value="telegram_cancel">Отменить отложенную отправку</button>';
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

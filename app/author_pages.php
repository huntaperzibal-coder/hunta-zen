<?php
declare(strict_types=1);
const READER_LEGAL_VERSION='2026-10-18-v1';
const READER_SUPPORT_DEFAULT='https://pay.cloudtips.ru/p/c0bf19d3';
function reader_donation_url(): string { return reader_support_url(trim(setting('support_url'))?:READER_SUPPORT_DEFAULT); }
function reader_legal_ready(): bool {
    return trim(setting('operator_name'))!==''&&trim(setting('operator_address'))!==''&&filter_var(setting('operator_email'),FILTER_VALIDATE_EMAIL)!==false;
}
function reader_public_photo(string $key): string {
    $name=setting($key);return preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D',$name)?$name:'';
}
function reader_consent_text(): string {
    return 'Согласие на обработку персональных данных. Редакция '.READER_LEGAL_VERSION.'. Я добровольно даю оператору '.setting('operator_name').' (адрес для обращений: '.setting('operator_address').'; email: '.setting('operator_email').') согласие на обработку отправляемого мной сообщения, указанного по моему желанию контакта для ответа, даты отправки и факта согласия. Цель — получить и рассмотреть моё обращение, при наличии контакта ответить на него. Действия: сбор, запись, хранение, уточнение, использование для ответа и удаление; обработка автоматизированная и с участием автора. Рабочие записи хранятся не более 90 дней. Контакт можно не указывать. Согласие не разрешает публикацию сообщения, рекламные рассылки или использование данных для обучения ИИ. Отозвать согласие и запросить удаление или исправление можно письмом на '.setting('operator_email').'. Резервные копии доступны только владельцу; при запросе удаления он также проверяет сохранённые копии. Согласие действует до достижения цели, истечения указанного срока или отзыва, кроме случаев обработки на ином законном основании.';
}
function reader_legal_page(bool $consent): void {
    $title=$consent?'Согласие на обработку персональных данных':'Политика обработки персональных данных';page_start($title);
    echo '<section class="reader-service-page legal-page"><a class="back" href="/feedback.php">← Познакомиться</a><h1>'.h($title).'</h1>';
    if(!reader_legal_ready()){echo '<p>Сведения оператора ещё не заполнены. Приём сообщений через форму временно закрыт.</p></section>';page_end();return;}
    if($consent)echo '<p>'.h(reader_consent_text()).'</p>';
    else {
        echo '<p class="hint">Редакция '.h(READER_LEGAL_VERSION).'</p><h2>Кто обрабатывает данные</h2><p>'.h(setting('operator_name')).'. Адрес для обращений: '.h(setting('operator_address')).'. Email: <a href="mailto:'.h(setting('operator_email')).'">'.h(setting('operator_email')).'</a>.</p><h2>Для чего и какие данные</h2><p>Форма нужна для личного обращения к автору и ответа. В ней сохраняются текст сообщения, добровольно указанный контакт, дата отправки и подтверждение согласия с его текстом. Не указывай чужие данные, паспортные сведения, сведения о здоровье и другую чувствительную информацию.</p><h2>Основание и порядок обработки</h2><p>Основание для обращения — отдельное добровольное согласие. Сообщения записываются в SQLite на хостинге сайта, доступны автору в закрытой админке и не публикуются. Автор читает обращение и отвечает по оставленному контакту. Если выбран Telegram или email, при ответе используется соответствующий сервис. Форма сама ничего не отправляет в Telegram, OpenRouter или стороннюю капчу.</p><h2>Защита от спама и технические данные</h2><p>Используется техническая сессия cookie, одноразовая форма, проверка времени и простой вопрос. Для ограничения частоты сохраняется код IP-адреса, рассчитанный с секретным ключом. Исходный IP не записывается этой формой в её таблицы. Веб-сервер и хостинг могут вести технические журналы запросов по своим настройкам. Эти меры применяются для безопасной работы сайта.</p><h2>Сроки и удаление</h2><p>Рабочие сообщения и подтверждения согласия удаляются обработчиком сайта через 90 дней после отправки. Счётчики защиты от спама — через сутки. Резервные копии содержат данные на дату создания, хранятся отдельно с ограниченным доступом и удаляются владельцем; при обращении об удалении автор также проверяет такие копии. Восстановленные данные нужно повторно сверять с поступившими требованиями об удалении.</p><h2>Права и обращения</h2><p>Можно запросить сведения об обработке, исправление, ограничение или удаление данных и отозвать согласие письмом на <a href="mailto:'.h(setting('operator_email')).'">'.h(setting('operator_email')).'</a>. Укажи контакт или дату и содержание обращения, чтобы его можно было найти. Запрос рассматривается в сроки, установленные применимым законодательством; отдельные данные могут сохраняться при наличии иного законного основания.</p><h2>Сторонние платежи</h2><p>Поддержка автора оформляется на отдельной странице платёжного сервиса после перехода по кнопке или QR-коду. Этот блог не получает реквизиты банковской карты. Правила обработки платёжных данных публикует платёжный сервис. Загрузка QR-кода на этой странице сама по себе не обращается к нему.</p><h2>Технические меры</h2><p>Доступ к сообщениям защищён авторизацией, изменение данных — CSRF-проверкой. Применяются ограничения ввода, экранирование текста и закрытое хранение базы и резервных копий. Для отправки формы используется HTTPS. Файлы с секретами не размещаются в публичном каталоге.</p><p><a href="/consent.php">Отдельный текст согласия</a></p>';
    }
    echo '</section>';page_end();
}
function reader_author_settings_save(array $input,array $files): void {
    global $db;
    $values=[];
    foreach(['author_name'=>100,'author_bio'=>4000,'operator_name'=>300,'operator_address'=>600,'operator_email'=>250] as $key=>$limit){$value=trim((string)($input[$key]??setting($key)));if(mb_strlen($value)>$limit)throw new RuntimeException('Слишком длинное поле: '.$key);$values[$key]=$value;}
    if($values['operator_email']!==''&&!filter_var($values['operator_email'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Проверь email для обращений о данных.');
    $values['feedback_enabled']=isset($input['feedback_enabled'])?'1':'0';
    $missing=[];foreach(['operator_name'=>'ФИО','operator_address'=>'почтовый адрес','operator_email'=>'email'] as $key=>$label)if($values[$key]==='')$missing[]=$label;
    if($values['feedback_enabled']==='1'&&$missing)throw new RuntimeException('Чтобы включить форму, заполни: '.implode(', ',$missing).'.');
    foreach(['author_photo','support_qr'] as $key){
        if(isset($input['remove_'.$key]))$values[$key]='';
        if(($files[$key]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$values[$key]=photo_upload($files[$key]);if($key==='support_qr')$values['support_qr_url']=reader_donation_url();}
    }
    $db->beginTransaction();try{$q=$db->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');foreach($values as $key=>$value)$q->execute([$key,$value]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    audit('author:settings');
}
function reader_author_settings_page(?array $draft=null,string $error=''): void {
    global $storage;
    $value=fn(string $key,string $fallback=''):string=>$draft!==null&&array_key_exists($key,$draft)?(string)$draft[$key]:setting($key,$fallback);
    $checked=fn(string $key):bool=>$draft!==null?isset($draft[$key]):setting($key)==='1';
    $missing=[];foreach(['operator_name'=>'ФИО','operator_address'=>'почтовый адрес','operator_email'=>'email'] as $key=>$label)if(trim(setting($key))==='')$missing[]=$label;
    $enabled=setting('feedback_enabled')==='1'&&reader_legal_ready();
    echo '<div class="author-settings-page"><div class="author-settings-heading"><h1>Автор и обратная связь</h1><a class="btn-secondary" href="/feedback.php" target="_blank" rel="noopener">Открыть страницу ↗</a></div>';
    echo '<div id="author-settings-status" tabindex="-1" class="author-settings-status'.($error!==''?' is-error':($enabled?' is-enabled':'')).'" role="'.($error!==''?'alert':'status').'">';
    if($error!=='')echo '<strong>Не удалось сохранить</strong><p>'.h($error).' Введённые значения оставлены в полях ниже.</p>';
    elseif(isset($_GET['saved']))echo '<strong>Изменения сохранены.</strong>';
    if($error===''){
    echo '<p><b>'.($enabled?'Форма включена — читатели могут отправлять сообщения.':'Форма пока не принимает сообщения.').'</b></p>';
    if(!$enabled)echo '<p>'.($missing?'Заполни: '.h(implode(', ',$missing)).'. Затем включи переключатель «Принимать сообщения» и сохрани.':'Включи переключатель «Принимать сообщения» ниже и сохрани.').'</p>';
    else echo '<p>Сообщения приходят в <a href="/admin.php?view=feedback">«Обратная связь»</a> в админке.</p>';
    }
    echo '</div><form id="author-settings-form" class="form-db author-settings-form" action="/admin.php?view=author#author-settings-status" method="post" enctype="multipart/form-data">'.csrf().'<input type="hidden" name="action" value="author_settings"><section class="author-settings-card"><h2>Фото и имя</h2><label for="author-name">Имя автора<input id="author-name" type="text" class="user-input" name="author_name" maxlength="100" value="'.h($value('author_name','Hunta')).'"></label><label for="author-bio">Описание автора<textarea id="author-bio" class="user-input" name="author_bio" rows="3" maxlength="4000">'.h($value('author_bio','Я Hunta, проводник в горах Алтая.')).'</textarea></label><p class="hint">Описание сохраняется отдельно. На компактной странице сейчас показываются фото и форма.</p>';
    $photo=reader_public_photo('author_photo');if($photo!=='')echo '<img class="author-settings-photo" src="'.h(photo_url($photo)).'" alt="Текущее фото автора">';
    echo '<label for="author-photo">Выбрать фотографию<input id="author-photo" class="author-file-input" type="file" name="author_photo" accept="image/jpeg,image/png,image/webp"></label><p class="hint">JPEG, PNG или WebP, до 12 МБ. Если файл не выбран, прежнее фото останется.</p>';
    if($photo!=='')echo '<label class="author-check"><input type="checkbox" name="remove_author_photo"'.($checked('remove_author_photo')?' checked':'').'><span>Убрать фото со страницы</span></label>';
    echo '</section><section class="author-settings-card" id="author-legal-fields"><h2>Включение обратной связи</h2><p class="hint">Эти сведения появятся в политике и согласии. Сообщения читателей будут видны только тебе в админке.</p>';
    $fields=['operator_name'=>['ФИО / наименование оператора','text','Полное имя',300],'operator_address'=>['Почтовый адрес для обращений','text','Индекс, населённый пункт, улица, дом или абонентский ящик',600],'operator_email'=>['Email для обращений о данных','email','name@example.ru',250]];
    foreach($fields as $key=>[$label,$type,$placeholder,$limit])echo '<label for="'.$key.'">'.h($label).'<input id="'.$key.'" class="user-input" type="'.$type.'" name="'.$key.'" maxlength="'.$limit.'" placeholder="'.h($placeholder).'" value="'.h($value($key)).'"></label>';
    echo '<label class="author-check author-feedback-toggle"><input type="checkbox" name="feedback_enabled" value="1"'.($checked('feedback_enabled')?' checked':'').'><span><strong>Принимать сообщения</strong><small>После сохранения форма появится под фотографией.</small></span></label><p class="author-settings-links"><a href="/privacy.php" target="_blank" rel="noopener">Политика</a> · <a href="/consent.php" target="_blank" rel="noopener">Отдельное согласие</a></p></section><details class="author-settings-card author-extra"><summary>QR-код поддержки</summary><p class="hint">Для твоей ссылки CloudTips QR-код уже готов. Загружать его повторно не нужно. Свой файл понадобится только для другой ссылки.</p><label for="support-qr">Другой QR-код<input id="support-qr" class="author-file-input" type="file" name="support_qr" accept="image/jpeg,image/png,image/webp"></label><label class="author-check"><input type="checkbox" name="remove_support_qr"'.($checked('remove_support_qr')?' checked':'').'><span>Убрать загруженный QR-код</span></label><p class="hint">Ссылка оплаты: '.h(reader_donation_url()).'. <a href="/admin.php?view=settings">Изменить в настройках</a>.</p></details><div class="author-settings-save"><button class="btn-main" type="submit">Сохранить изменения</button><span role="status" id="author-submit-status">'.($error!==''?'Есть ошибка — проверь выделенное сообщение выше.':'').'</span></div></form>';
    $heartbeat=is_file($storage.'/telegram-heartbeat')?(int)file_get_contents($storage.'/telegram-heartbeat'):0;
    if($heartbeat<time()-600)echo '<details class="author-settings-technical"><summary>Фоновое удаление старых сообщений</summary><p class="hint">Нет свежего запуска обработчика. Для очистки через 90 дней нужен cron tools/telegram_worker.php. Команда указана в разделе Telegram.</p></details>';
    echo '</div><script src="/assets/js/author-settings.js?v=1" defer></script>';
}

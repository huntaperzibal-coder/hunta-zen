(() => {
  'use strict';
  const hints = [
    ['a[href="#illustrations"]', 'Перейти к пожеланиям художнику, кнопке генерации и готовым вариантам картинки.'],
    ['#title', 'Название истории. Его увидят читатели сайта и Telegram.'],
    ['#category_id', 'Выбери цикл историй. Категория сохраняется вместе с текстом.'],
    ['[name=action][value=save]', 'Сохранить текст и настройки записи. Быстрое сохранение: Ctrl+S.'],
    ['[name=action][value=publish]', 'Сделать историю доступной на сайте. В Telegram она отправляется отдельной кнопкой.'],
    ['[name=action][value=unpublish]', 'Убрать историю с сайта и оставить в черновиках. Сообщение в Telegram останется.'],
    ['a[href*="view=preview"]', 'Посмотреть сохранённую версию истории глазами читателя.'],
    ['[name=photo]', 'Выбери JPEG, PNG или WebP до 12 МБ. Для фото прямо в тексте удобнее кнопка «Фото» над текстом.'],
    ['[name=photo_kind]', 'Обложка появится над историей и в ленте. Вставка добавит фото в конец текста.'],
    ['[name=action][value=upload]', 'Загрузить выбранное фото. Текущие изменения текста тоже сохранятся.'],
    ['[name=excerpt]', 'Короткий текст в ленте сайта. Оставь пустым, чтобы использовать начало истории.'],
    ['[name=telegram_text]', 'Отдельный текст для Telegram. Если оставить пустым, используем начало истории. Ссылка добавляется автоматически.'],
    ['[name=action][value=telegram]', 'Отправить историю в настроенный Telegram-канал или обновить ранее отправленное сообщение.'],
    ['[name=illustration_wishes]', 'Необязательные пожелания: настроение, сцена, время суток. Сам рассказ иллюстратор прочитает целиком.'],
    ['[name=action][value=illustrate]', 'Сохранить текст и заказать один платный вариант через OpenRouter. Картинка появится ниже; страницу можно закрыть.'],
    ['[name=action][value^="illustration_cover:"]', 'Сохранить текущий текст и поставить этот вариант на обложку. Остальные варианты останутся в истории.'],
    ['[name=action][value^="illustration_inline:"]', 'Сохранить текущий текст и добавить картинку в конец. Затем её можно переместить в редакторе.'],
    ['[name=api_key]', 'Вставь ключ из своего аккаунта OpenRouter. Он хранится только на сервере. Пустое поле сохраняет прежний ключ.'],
    ['[name=reader]', 'Модель, которая читает рассказ и выбирает сцену. Укажи идентификатор OpenRouter вида автор/модель.'],
    ['[name=painter]', 'Модель, которая рисует картинку. Нужна поддержка Image API, а для референсов — input_references.'],
    ['[name=style]', 'Постоянный художественный стиль всех историй: техника, цвета и настроение.'],
    ['[name=characters]', 'Опиши внешность и характер каждого героя, чтобы он оставался узнаваемым в разных историях.'],
    ['[name=reference]', 'Образец внешности героев или лист персонажей. До трёх картинок по 4 МБ. Загружается при сохранении настроек.'],
    ['[name=clear_references]', 'Убрать образцы из будущих генераций. Прежние варианты картинок сохранятся.'],
    ['[name=daily_limit]', 'Ограничивает число заказанных вариантов за день. Лимит расходов в деньгах задаётся у ключа в OpenRouter.']
  ];
  const toolbarHints = [
    [/undo|отмен/i, 'Отменить последнее изменение текста (Ctrl+Z).'],
    [/redo|повтор/i, 'Вернуть отменённое изменение (Ctrl+Y).'],
    [/абзац|paragraph|blocks|^блок (?!цит)/i, 'Выбрать обычный абзац или подзаголовок для текущего фрагмента.'],
    [/bold|жирн/i, 'Выделить выбранный текст полужирным (Ctrl+B).'],
    [/italic|курсив/i, 'Выделить выбранный текст курсивом (Ctrl+I).'],
    [/underline|подч[её]рк/i, 'Подчеркнуть выбранный текст (Ctrl+U).'],
    [/strikethrough|зач[её]рк/i, 'Зачеркнуть выбранный текст.'],
    [/bullet|маркирован/i, 'Создать список с точками. Повторное нажатие возвращает обычный абзац.'],
    [/numbered|нумерован/i, 'Создать нумерованный список.'],
    [/blockquote|цитат/i, 'Оформить выбранные абзацы как цитату.'],
    [/link|ссылк/i, 'Добавить или изменить ссылку у выбранного текста (Ctrl+K).'],
    [/фотограф|фото/i, 'Загрузить фотографии с компьютера и вставить в текущее место текста.'],
    [/image|изображен/i, 'Изменить выбранную картинку, её подпись и описание или вставить изображение.'],
    [/table|таблиц/i, 'Вставить таблицу или изменить её строки и столбцы.'],
    [/left|левому/i, 'Выровнять текущий абзац по левому краю.'],
    [/center|центру/i, 'Выровнять текущий абзац по центру.'],
    [/right|правому/i, 'Выровнять текущий абзац по правому краю.'],
    [/remove.*format|очист.*формат|удал.*формат/i, 'Убрать оформление выбранного текста, сохранив сами слова.'],
    [/search|поиск|найти/i, 'Найти слово в истории и при необходимости заменить его.'],
    [/fullscreen|полноэкран|полный экран/i, 'Развернуть редактор на весь экран. Повторное нажатие вернёт обычный вид.'],
    [/save|сохран/i, 'Сохранить запись (Ctrl+S).']
  ];
  const tip = document.createElement('div');
  tip.id = 'dao-editor-tip'; tip.className = 'dao-editor-tip'; tip.role = 'tooltip'; tip.hidden = true;
  document.body.append(tip);
  let current = null;
  function annotate() {
    for (const [selector, help] of hints) document.querySelectorAll(selector).forEach(node => { node.dataset.help = help; });
    document.querySelectorAll('.tox-toolbar__group button').forEach(button => {
      const label = button.getAttribute('aria-label') || button.title || '';
      const match = toolbarHints.find(([pattern]) => pattern.test(label));
      if (label) button.dataset.help = match ? match[1] : label;
    });
  }
  function hide() {
    if (current) {
      const ids = (current.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== tip.id);
      if (ids.length) current.setAttribute('aria-describedby', ids.join(' ')); else current.removeAttribute('aria-describedby');
    }
    current = null; tip.hidden = true; document.body.classList.remove('dao-help-open');
  }
  function show(node) {
    if (!node || node.disabled) return;
    hide(); current = node; tip.textContent = node.dataset.help; tip.hidden = false;
    node.setAttribute('aria-describedby', ((node.getAttribute('aria-describedby') || '') + ' ' + tip.id).trim());
    const box = node.getBoundingClientRect();
    tip.style.left = Math.max(8, Math.min(box.left, innerWidth - tip.offsetWidth - 8)) + 'px';
    tip.style.top = (box.bottom + 8 + tip.offsetHeight <= innerHeight ? box.bottom + 8 : Math.max(8, box.top - tip.offsetHeight - 8)) + 'px';
    document.body.classList.add('dao-help-open');
  }
  document.addEventListener('pointerover', event => { const node = event.target.closest('[data-help]'); if (node && node !== current) show(node); });
  document.addEventListener('pointerout', event => { if (current && !current.contains(event.relatedTarget)) hide(); });
  document.addEventListener('focusin', event => { const node = event.target.closest('[data-help]'); if (node) show(node); else hide(); });
  document.addEventListener('focusout', hide);
  document.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
  document.addEventListener('scroll', hide, true); window.addEventListener('resize', hide);
  annotate();
  let pending = false;
  new MutationObserver(records => {
    if (pending || !records.some(record => Array.from(record.addedNodes).some(node => node.nodeType === 1 && node !== tip && !tip.contains(node)))) return;
    pending = true; requestAnimationFrame(() => { pending = false; annotate(); });
  }).observe(document.body, {childList: true, subtree: true});
})();

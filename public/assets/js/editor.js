(() => {
  'use strict';
  const form = document.querySelector('#editor');
  if (!form) return;
  const status = document.querySelector('#save-status');
  let dirty = form.dataset.unsaved === '1', busy = false, stopped = false;
  let submitting = false, replay = false, pendingSubmit = null, editor = null;
  const activeUploads = new Set();
  const fields = ['title', 'body', 'excerpt', 'telegram_text', 'category_id'];
  const snapshot = () => fields.map(name => form.elements[name].value).join('\u0000');
  function markDirty() {
    dirty = true;
    status.textContent = 'Есть несохранённые изменения';
  }
  function syncBody() { if (editor && !editor.removed) form.elements.body.value = editor.getContent(); }
  async function prepareBody() {
    if (activeUploads.size) await Promise.all([...activeUploads]);
    if (editor && !editor.removed) {
      const results = await editor.uploadImages();
      if (results.some(result => result.status === false)) throw Error('Не все фото загрузились. Дождись загрузки или удали неудачное фото.');
      syncBody();
    }
    if (/src=["'](?:blob:|data:)/i.test(form.elements.body.value)) throw Error('Фото ещё загружается. Подожди перед сохранением.');
  }
  function uploadFile(file, progress = () => {}, kind = 'photo') {
    const task = new Promise((resolve, reject) => {
      const data = new FormData();
      data.append('csrf', form.elements.csrf.value);
      data.append('id', form.elements.id.value);
      data.append('kind', kind);
      data.append('file', file, file.name || 'photo.png');
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '/editor-upload.php');
      xhr.timeout = kind === 'video' ? 180000 : 60000;
      xhr.upload.onprogress = e => { if (e.lengthComputable) progress(e.loaded / e.total * 100); };
      xhr.onload = () => {
        let result;
        try { result = JSON.parse(xhr.responseText); }
        catch { reject('Сервер не принял файл. Проверь размер файла или войди заново.'); return; }
        if (xhr.status < 200 || xhr.status >= 300 || !result.location) { reject(result.error || 'Не удалось загрузить файл.'); return; }
        resolve(result.location);
      };
      xhr.onerror = () => reject('Нет связи. Файл не загрузился.');
      xhr.ontimeout = () => reject('Загрузка заняла слишком долго. Попробуй снова.');
      xhr.send(data);
    });
    activeUploads.add(task);
    task.then(() => activeUploads.delete(task), () => activeUploads.delete(task));
    return task;
  }
  if (window.tinymce) {
    tinymce.init({
      selector: '#body', base_url: '/assets/tinymce', suffix: '.min', license_key: 'gpl',
      language: 'ru', language_url: '/assets/tinymce/langs/ru.js',
      plugins: 'autoresize lists advlist autolink link image fullscreen searchreplace wordcount code table charmap',
      menubar: false, promotion: false, branding: false,
      toolbar: 'undo redo | daocopyall daopasteall | blocks daolinebreak | bold italic underline strikethrough | bullist numlist blockquote | link daophoto daovideo image table | alignleft aligncenter alignright | removeformat daomarks | searchreplace fullscreen | daosave',
      toolbar_mode: 'sliding', block_formats: 'Абзац=p; Подзаголовок=h2; Малый заголовок=h3',
      height: 760, min_height: window.innerWidth <= 700 ? 480 : 760, max_height: 6000,
      autoresize_bottom_margin: 24, autoresize_overflow_padding: 22, resize: true, elementpath: false,
      content_css: ['/assets/css/article-editor.css?v=7', '/assets/css/reading-type.css?v=2'],
      convert_urls: false, relative_urls: false, remove_script_host: true,
      extended_valid_elements: 'video[src|controls|playsinline|preload]',
      paste_data_images: true, automatic_uploads: true,
      images_file_types: 'jpg,jpeg,png,webp', images_reuse_filename: false,
      images_upload_handler: (blob, progress) => uploadFile(blob.blob(), progress),
      image_caption: true, image_description: true, image_title: false,
      image_advtab: false, image_dimensions: false,
      file_picker_types: 'image',
      file_picker_callback: callback => {
        const picker = document.createElement('input'); picker.type = 'file'; picker.accept = 'image/jpeg,image/png,image/webp';
        picker.onchange = async () => {
          if (!picker.files[0]) return;
          try { callback(await uploadFile(picker.files[0]), {alt: ''}); }
          catch (message) { editor.windowManager.alert(String(message)); }
        };
        picker.click();
      },
      setup: ed => {
        let ready = false;
        ed.ui.registry.addButton('daovideo', {
          text: 'Видео', tooltip: 'Вставить MP4 в текст (H.264 / AAC)',
          onAction: () => {
            const picker = document.createElement('input');picker.type = 'file';picker.accept = 'video/mp4,.mp4';
            const bookmark = ed.selection.getBookmark();
            picker.onchange = async () => {
              const file = picker.files[0];if (!file) return;
              const limit = Number(form.dataset.videoLimit);
              if (file.size > limit) { ed.windowManager.alert(`Видео слишком большое. Лимит: ${(limit / 1048576).toFixed(1)} МБ.`);return; }
              status.textContent = 'Загружаю видео…';
              try {
                const url = await uploadFile(file, percent => { status.textContent = `Загружаю видео: ${Math.round(percent)}%`; }, 'video');
                ed.focus();ed.selection.moveToBookmark(bookmark);
                ed.undoManager.transact(() => ed.insertContent('<figure><video src="' + ed.dom.encode(url) + '" controls playsinline preload="metadata"></video></figure><p></p>'));
                syncBody();markDirty();
              } catch (message) { status.textContent = 'Видео не загружено';ed.windowManager.alert(String(message)); }
            };
            picker.click();
          }
        });
        const refreshMarks = () => {
          const body = ed.getBody();
          if (!body) return;
          ed.undoManager.ignore(() => {
            const bookmark = ed.selection.getBookmark();
            body.querySelectorAll('.dao-break-mark').forEach(mark => mark.remove());
            if (body.classList.contains('dao-show-marks')) body.querySelectorAll('br:not([data-mce-bogus])').forEach(br => {
              const mark = ed.getDoc().createElement('span');
              mark.className = 'dao-break-mark'; mark.dataset.mceBogus = 'all';
              mark.contentEditable = 'false'; mark.setAttribute('aria-hidden', 'true'); mark.textContent = '↵';
              br.before(mark);
            });
            ed.selection.moveToBookmark(bookmark);
          });
        };
        ed.on('input SetContent Undo Redo', refreshMarks);
        ed.ui.registry.addButton('daolinebreak', {
          text: '↵ Перенос строки', tooltip: 'Новая строка без нового абзаца (Shift+Enter)',
          onAction: () => {
            ed.focus();
            ed.undoManager.transact(() => ed.execCommand('InsertLineBreak'));
            refreshMarks(); syncBody(); markDirty();
          }
        });
        ed.ui.registry.addToggleButton('daomarks', {
          text: '¶', tooltip: 'Разметка: ¶ — абзац (Enter), ↵ — перенос (Shift+Enter). Рамка показывает границы абзаца.',
          onAction: api => {
            const visible = ed.getBody().classList.toggle('dao-show-marks');
            refreshMarks();
            api.setActive(visible);
          }
        });
        const matchReadingScale = () => {
          if (!ready || ed.removed) return;
          const bodyScale = parseFloat(getComputedStyle(document.body).zoom) || 1;
          const rootScale = parseFloat(getComputedStyle(document.documentElement).zoom) || 1;
          ed.getBody().style.zoom = String(1 / (bodyScale * rootScale));
          ed.execCommand('mceAutoResize');
        };
        ed.on('init', () => { editor = ed; ready = true; matchReadingScale(); });
        window.addEventListener('resize', matchReadingScale);
        ed.on('remove', () => window.removeEventListener('resize', matchReadingScale));
        ed.on('input change undo redo SetContent', () => { if (ready) { syncBody(); markDirty(); } });
        ed.addShortcut('meta+s', 'Сохранить запись', () => form.querySelector('[name="action"][value="save"]').click());
        let pasting = false;
        const pasteFromClipboard = async () => {
          if (pasting) return;
          pasting = true;
          const original = ed.getContent();
          try {
            const text = await navigator.clipboard.readText();
            if (ed.removed) return;
            const parsed = window.DaoClipboard.fromText(text);
            if (!parsed.html) { status.textContent = 'Буфер пуст — текст не изменён'; return; }
            if (ed.getContent() !== original) { status.textContent = 'Текст изменился во время чтения буфера. Нажми «Вставить текст» ещё раз.'; return; }
            ed.undoManager.add();
            ed.undoManager.transact(() => ed.setContent(parsed.html));
            syncBody(); markDirty(); ed.focus();
            status.textContent = 'Текст заменён из буфера. Отмена — Ctrl+Z';
          } catch (_) {
            ed.windowManager.alert('Браузер не разрешил прочитать буфер. Разреши доступ к буферу обмена для сайта и нажми «Вставить текст» ещё раз.');
          } finally { pasting = false; }
        };
        ed.ui.registry.addButton('daocopyall', {text: 'Скопировать всё', tooltip: 'Скопировать заголовок и весь текст для работы в чате', onAction: async () => {
          const html = ed.getContent(), title = form.elements.title.value;
          const plain = window.DaoClipboard.toText(html, title);
          try {
            if (window.ClipboardItem && navigator.clipboard?.write) {
              try { await navigator.clipboard.write([new ClipboardItem({'text/plain': new Blob([plain], {type: 'text/plain'}), 'text/html': new Blob(['<h1>' + ed.dom.encode(title) + '</h1>' + html], {type: 'text/html'})})]); }
              catch (_) { await navigator.clipboard.writeText(plain); }
            } else await navigator.clipboard.writeText(plain);
            status.textContent = 'Заголовок и текст скопированы';
            const label = ed.getContainer().querySelector('[data-mce-name="daocopyall"] .tox-tbtn__select-label');
            if (label) { label.textContent = 'Скопировано ✓'; setTimeout(() => { if (label.isConnected) label.textContent = 'Скопировать всё'; }, 2000); }
          } catch (_) {
            ed.windowManager.open({title: 'Скопировать текст', size: 'large', initialData: {text: plain}, body: {type: 'panel', items: [{type: 'textarea', name: 'text', label: 'Браузер не разрешил копирование. Выдели текст и нажми Ctrl+C.'}]}, buttons: [{type: 'cancel', text: 'Закрыть'}]});
          }
        }});
        ed.ui.registry.addButton('daopasteall', {text: 'Вставить текст', tooltip: 'Полностью заменить текст редактора содержимым буфера (отмена: Ctrl+Z)', onAction: pasteFromClipboard});
        ed.ui.registry.addButton('daosave', {icon: 'save', tooltip: 'Сохранить запись (Ctrl+S)', onAction: () => form.querySelector('[name="action"][value="save"]').click()});
        ed.ui.registry.addButton('daophoto', {
          icon: 'image', text: 'Фото', tooltip: 'Загрузить фотографию в текст',
          onAction: () => {
            const bookmark = ed.selection.getBookmark(2, true);
            const picker = document.createElement('input'); picker.type = 'file'; picker.accept = 'image/jpeg,image/png,image/webp'; picker.multiple = true;
            picker.onchange = async () => {
              if (!picker.files.length) return;
              ed.setProgressState(true);
              try {
                const photos = [];
                for (const file of picker.files) photos.push(await uploadFile(file));
                ed.focus(); ed.selection.moveToBookmark(bookmark);
                ed.undoManager.transact(() => ed.insertContent(photos.map(src => '<figure class="image"><img src="' + ed.dom.encode(src) + '" alt=""><figcaption>Подпись к фотографии</figcaption></figure><p></p>').join('')));
                syncBody(); markDirty();
              } catch (message) { ed.windowManager.alert(String(message)); }
              finally { ed.setProgressState(false); }
            };
            picker.click();
          }
        });
      }
    }).catch(() => { status.textContent = 'Визуальный редактор не загрузился. Текст доступен в поле ниже; обнови страницу после сохранения.'; });
  }
  form.addEventListener('input', event => {
    if (event.target.matches('[name=title],[name=body],[name=excerpt],[name=telegram_text],[name=category_id]')) markDirty();
  });
  async function updateTelegram(button) {
    const before = snapshot(), data = new FormData(form);
    data.delete('photo'); data.set('action', button.value); data.set('telegram_inline', '1');
    const block = document.querySelector('#telegram');
    const controls = [...block.querySelectorAll('button,input,textarea')];
    const previousDisabled = controls.map(control => control.disabled);
    controls.forEach(control => { control.disabled = true; });
    const feedback = block.querySelector('#telegram-feedback');
    feedback.textContent = button.value === 'telegram_cancel' ? 'Отменяю отправку…' : 'Планирую отправку…';
    feedback.setAttribute('role', 'status');
    try {
      let response;
      try { response = await fetch('/admin.php', {method: 'POST', body: data, headers: {'Accept': 'application/json'}}); }
      catch (_) { stopped = true; throw Error('Связь прервалась. Обнови страницу и проверь, изменилось ли расписание.'); }
      if (!response.headers.get('content-type')?.includes('application/json')) { stopped = true; throw Error('Не удалось подтвердить действие. Обнови страницу и проверь расписание.'); }
      const result = await response.json();
      if (result.revision) form.elements.revision.value = result.revision;
      if (!response.ok) { if (!result.revision) stopped = true; throw Error(result.error || 'Не удалось изменить расписание'); }
      if (!result.html) throw Error('Нет подтверждения расписания. Обнови страницу и проверь результат.');
      const top = block.getBoundingClientRect().top;
      block.outerHTML = result.html;
      const replacement = document.querySelector('#telegram'); replacement.open = true;
      const message = replacement.querySelector('#telegram-feedback'); message.textContent = result.message;
      const delta = replacement.getBoundingClientRect().top - top;
      const scroller = document.querySelector('.orda-shell .main');
      if (scroller) scroller.scrollTop += delta; else window.scrollBy(0, delta);
      dirty = before !== snapshot();
      status.textContent = dirty ? 'Есть новые изменения' : 'Текст сохранён';
    } catch (error) {
      const message = String(error.message || error);
      feedback.textContent = message; feedback.setAttribute('role', 'alert');
      status.textContent = message;
    } finally {
      controls.forEach((control, index) => { control.disabled = previousDisabled[index]; });
    }
  }
  async function submitPending() {
    if (busy || !pendingSubmit) return;
    const button = pendingSubmit; pendingSubmit = null; busy = true;
    try {
      await prepareBody();
      if (['telegram_schedule', 'telegram_cancel'].includes(button.value)) { await updateTelegram(button); busy = false; if (pendingSubmit && !stopped) submitPending(); return; }
      // Leave the original submit event before replaying it. Browsers ignore
      // requestSubmit during that event when uploadImages resolves immediately.
      setTimeout(() => { replay = true; busy = false; form.requestSubmit(button); }, 0);
    } catch (error) {
      status.textContent = String(error.message || error); dirty = true; busy = false;
    }
  }
  async function save() {
    if (!dirty || busy || stopped || submitting) return;
    busy = true; status.textContent = 'Сохраняю…';
    try {
      await prepareBody();
      const before = snapshot(); const data = new FormData(form); data.delete('photo'); data.set('action', 'autosave');
      const response = await fetch('/admin.php', {method: 'POST', body: data, headers: {'Accept': 'application/json'}});
      if (!response.headers.get('content-type')?.includes('application/json')) throw Error('Вход истёк. Скопируй текст перед повторным входом.');
      const result = await response.json();
      if (!response.ok) { stopped = true; throw Error(result.error || 'Не удалось сохранить'); }
      form.elements.revision.value = result.revision; syncBody(); dirty = before !== snapshot();
      status.textContent = dirty ? 'Есть новые изменения' : 'Сохранено в ' + result.saved;
    } catch (error) { status.textContent = String(error.message || error); }
    finally { busy = false; if (pendingSubmit && !stopped) submitPending(); }
  }
  setInterval(save, 5000);
  form.addEventListener('submit', async event => {
    if (replay) { replay = false; submitting = true; return; }
    event.preventDefault();
    const button = event.submitter;
    const confirmation = button?.dataset.editorConfirm;
    if (confirmation && !await window.ordaConfirm(confirmation, 'Удалить', 'Отмена')) return;
    pendingSubmit = button;
    if (busy) status.textContent = 'Завершаю сохранение…';
    else submitPending();
  });
  if (location.hash === '#telegram') document.querySelector('#telegram').open = true;
  window.addEventListener('beforeunload', event => { if ((dirty || busy || activeUploads.size) && !submitting) { event.preventDefault(); event.returnValue = ''; } });
})();

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
  function uploadFile(file, progress = () => {}) {
    const task = new Promise((resolve, reject) => {
      const data = new FormData();
      data.append('csrf', form.elements.csrf.value);
      data.append('id', form.elements.id.value);
      data.append('file', file, file.name || 'photo.png');
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '/editor-upload.php');
      xhr.timeout = 60000;
      xhr.upload.onprogress = e => { if (e.lengthComputable) progress(e.loaded / e.total * 100); };
      xhr.onload = () => {
        let result;
        try { result = JSON.parse(xhr.responseText); }
        catch { reject('Сервер не принял фото. Проверь размер файла или войди заново.'); return; }
        if (xhr.status < 200 || xhr.status >= 300 || !result.location) { reject(result.error || 'Не удалось загрузить фото.'); return; }
        resolve(result.location);
      };
      xhr.onerror = () => reject('Нет связи. Фотография не загрузилась.');
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
      toolbar: 'undo redo | daoproofread | blocks | bold italic underline strikethrough | bullist numlist blockquote | link daophoto image table | alignleft aligncenter alignright | removeformat daomarks | searchreplace fullscreen | daosave',
      toolbar_mode: 'sliding', block_formats: 'Абзац=p; Подзаголовок=h2; Малый заголовок=h3',
      height: 760, min_height: window.innerWidth <= 700 ? 480 : 760, max_height: 6000,
      autoresize_bottom_margin: 24, autoresize_overflow_padding: 22, resize: true, elementpath: false,
      content_css: ['/assets/css/article-editor.css?v=6', '/assets/css/reading-type.css?v=1'],
      convert_urls: false, relative_urls: false, remove_script_host: true,
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
        const refreshMarks = () => {
          const body = ed.getBody();
          if (!body) return;
          ed.undoManager.ignore(() => {
            body.querySelectorAll('.dao-break-mark').forEach(mark => mark.remove());
            if (body.classList.contains('dao-show-marks')) body.querySelectorAll('br:not([data-mce-bogus])').forEach(br => {
              const mark = ed.getDoc().createElement('span');
              mark.className = 'dao-break-mark'; mark.dataset.mceBogus = 'all';
              mark.contentEditable = 'false'; mark.setAttribute('aria-hidden', 'true'); mark.textContent = '↵';
              br.before(mark);
            });
          });
        };
        ed.on('input SetContent Undo Redo', refreshMarks);
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
        let proofPending = null, proofBusy = false;
        const proofNodes = container => {
          const nodes = [];
          const walk = node => {
            if (node.nodeType === 1 && ['PRE','CODE','SCRIPT','STYLE','NOSCRIPT'].includes(node.tagName)) return;
            if (node.nodeType === 3) { if (node.nodeValue.trim() !== '') nodes.push(node); return; }
            Array.from(node.childNodes).forEach(walk);
          };
          walk(container); return nodes;
        };
        const showProof = (result, original) => {
          const patches = result.plan?.patches;
          if (!Array.isArray(patches)) throw Error('Ответ проверки не распознан. Текст не изменён.');
          const source = ed.getDoc().createElement('div'); source.innerHTML = original;
          const nodes = proofNodes(source);
          for (const patch of patches) if (!Number.isInteger(patch.id) || !nodes[patch.id] || typeof patch.text !== 'string') throw Error('Некорректная правка. Текст не изменён.');
          const price = Number(result.cost || 0).toFixed(4);
          if (!patches.length) { ed.windowManager.alert('Явных ошибок не найдено. Стоимость проверки: $' + price); return; }
          const html = '<p>Проверь изменения перед применением. Стоимость: $' + price + '</p>' + patches.map(p => '<section><p><b>Было:</b><br>' + ed.dom.encode(nodes[p.id].nodeValue).replace(/\n/g, '<br>') + '</p><p><b>Предлагается:</b><br>' + ed.dom.encode(p.text).replace(/\n/g, '<br>') + '</p><hr></section>').join('');
          ed.windowManager.open({title: 'Проверка текста — ' + patches.length + ' фрагм.', size: 'large',
            body: {type: 'panel', items: [{type: 'htmlpanel', html}]},
            buttons: [{type: 'cancel', text: 'Отмена'}, {type: 'submit', text: 'Применить', primary: true}],
            onSubmit: dialog => {
              if (ed.getContent() !== original) { ed.windowManager.alert('За время проверки текст изменился. Правки не применены. Запусти новую проверку текущей версии.'); return; }
              patches.forEach(p => { nodes[p.id].nodeValue = p.text; });
              ed.undoManager.add();
              ed.undoManager.transact(() => ed.setContent(source.innerHTML));
              syncBody(); markDirty(); dialog.close();
            }
          });
        };
        const runProof = async api => {
          if (proofBusy) return;
          proofBusy = true; api.setEnabled(false);
          try {
            if (!proofPending) {
              await prepareBody();
              const original = ed.getContent();
              const data = new FormData();
              for (const name of ['csrf','id','revision']) data.set(name, form.elements[name].value);
              data.set('body', original);
              status.textContent = 'Запускаю проверку текста…';
              const response = await fetch('/proofread.php', {method: 'POST', body: data});
              if (!response.headers.get('content-type')?.includes('application/json')) throw Error('Не удалось начать проверку. Обнови страницу после сохранения текста.');
              const result = await response.json(); if (!response.ok) throw Error(result.error || 'Проверка не запущена.');
              proofPending = {job: result.job, original};
            }
            const pending = proofPending;
            for (let attempt = 0; attempt < 120; attempt++) {
              if (ed.removed) return;
              const response = await fetch('/proofread.php?job=' + pending.job + '&id=' + encodeURIComponent(form.elements.id.value), {cache: 'no-store'});
              if (!response.headers.get('content-type')?.includes('application/json')) throw Error('Нет ответа проверки. Нажми «Проверить текст» для получения результата без повторного запроса к ИИ.');
              const result = await response.json(); if (!response.ok) throw Error(result.error || 'Не удалось получить результат проверки.');
              if (result.state === 'done') { proofPending = null; status.textContent = 'Проверка готова. Рассказ ещё не изменён.'; showProof(result, pending.original); return; }
              if (['failed','unknown'].includes(result.state)) { proofPending = null; throw Error(result.error || 'Проверка остановлена. Текст не изменён.'); }
              if (result.error) { status.textContent = result.error; throw Error(result.error); }
              status.textContent = 'Корректор проверяет текст. Можно продолжать писать.';
              await new Promise(resolve => setTimeout(resolve, 5000));
            }
            throw Error('Проверка ещё не завершена. Нажми «Проверить текст» позже, чтобы получить результат без нового запроса к ИИ.');
          } catch (error) { status.textContent = String(error.message || error); ed.windowManager.alert(String(error.message || error)); }
          finally { proofBusy = false; if (!ed.removed) api.setEnabled(true); }
        };
        ed.ui.registry.addButton('daoproofread', {text: 'Проверить текст', tooltip: 'Исправить ошибки и опечатки; сначала показать правки', onAction: runProof});
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
  async function submitPending() {
    if (busy || !pendingSubmit) return;
    const button = pendingSubmit; pendingSubmit = null; busy = true;
    try {
      await prepareBody();
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

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
      toolbar: 'undo redo | blocks | bold italic underline strikethrough | bullist numlist blockquote | link daophoto image table | alignleft aligncenter alignright | removeformat | searchreplace fullscreen | daosave',
      toolbar_mode: 'sliding', block_formats: 'Абзац=p; Подзаголовок=h2; Малый заголовок=h3',
      height: 760, min_height: window.innerWidth <= 700 ? 480 : 760, max_height: 6000,
      autoresize_bottom_margin: 24, autoresize_overflow_padding: 22, resize: true, elementpath: false,
      content_css: '/assets/css/article-editor.css?v=4',
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
        ed.on('init', () => { editor = ed; ready = true; });
        ed.on('input change undo redo SetContent', () => { if (ready) { syncBody(); markDirty(); } });
        ed.addShortcut('meta+s', 'Сохранить запись', () => form.querySelector('[name="action"][value="save"]').click());
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
      replay = true; busy = false; form.requestSubmit(button);
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
  form.addEventListener('submit', event => {
    if (replay) { replay = false; submitting = true; return; }
    event.preventDefault(); pendingSubmit = event.submitter;
    if (busy) status.textContent = 'Завершаю сохранение…';
    else submitPending();
  });
  if (location.hash === '#telegram') document.querySelector('#telegram').open = true;
  window.addEventListener('beforeunload', event => { if ((dirty || busy || activeUploads.size) && !submitting) { event.preventDefault(); event.returnValue = ''; } });
})();

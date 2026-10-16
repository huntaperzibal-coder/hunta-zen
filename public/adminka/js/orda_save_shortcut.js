/*
 * 📄 /adminka/js/orda_save_shortcut.js
 * ТОПО / Ctrl+S (Cmd+S на Mac) сохраняет форму — так же, как клик по
 * кнопке «Сохранить», без системного диалога браузера «Сохранить
 * страницу как…», который иначе перехватывает это сочетание.
 *
 * Подключается один раз глобально, через render_vhod.php — общий футер
 * почти всех writer-страниц Орды (117+ файлов на момент написания),
 * поэтому работает сразу везде, без правок каждой отдельной формы.
 *
 * Не ловит текст внутри TinyMCE (site/writer_db/articles/editor.js) —
 * у него текст живёт в iframe, это отдельный document, keydown в нём
 * не всплывает сюда. Там сочетание навешено отдельно, через
 * editor.addShortcut('meta+s', ...) в самом editor.js.
 */
(function () {
  'use strict';

  function findSubmitButton(form) {
    var inside = form.querySelector('button[name="action"][value="save"]') || form.querySelector('button[type="submit"], input[type="submit"]');
    if (inside) {
      return inside;
    }
    if (!form.id) {
      return null;
    }
    return document.querySelector(
      'button[type="submit"][form="' + CSS.escape(form.id) + '"], ' +
      'input[type="submit"][form="' + CSS.escape(form.id) + '"]'
    );
  }

  document.addEventListener('keydown', function (e) {
    var key = e.key ? e.key.toLowerCase() : '';
    if (key !== 's' || !(e.ctrlKey || e.metaKey) || e.shiftKey || e.altKey) {
      return;
    }

    var active = document.activeElement;
    var form = active && active.closest ? active.closest('form') : null;
    if (!form) {
      form = document.querySelector('#editor') || document.querySelector('.orda-blog-content form') || document.querySelector('.auth-box form');
    }
    if (!form) {
      return;
    }

    var btn = findSubmitButton(form);
    if (btn && btn.disabled) {
      return;
    }

    e.preventDefault();

    if (btn) {
      btn.click();
    } else if (typeof form.requestSubmit === 'function') {
      form.requestSubmit();
    } else {
      form.submit();
    }
  });
})();


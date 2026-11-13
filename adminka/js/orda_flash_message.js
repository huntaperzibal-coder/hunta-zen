/*
 * 📄 /adminka/js/orda_flash_message.js
 * ТОПО / Сообщение об успехе (.auth-success, «Сохранено.» и т.п.) —
 * маяк: появляется, несколько секунд заметно горит и гаснет само, а
 * не остаётся зелёной полосой на странице до следующей перезагрузки.
 *
 * Только .auth-success — ошибки (.auth-error) сами не гаснут: их
 * нужно прочитать и исправить, а не просто заметить мельком.
 */
(function () {
  'use strict';

  var SHOW_MS = 3000;
  var FADE_MS = 400;

  document.querySelectorAll('.auth-success').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity ' + FADE_MS + 'ms ease';
      el.style.opacity = '0';
      setTimeout(function () {
        el.style.display = 'none';
      }, FADE_MS);
    }, SHOW_MS);
  });
})();

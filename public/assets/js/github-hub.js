(() => {
  'use strict';
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-copy-command]');if (!button) return;
    const source = document.getElementById(button.dataset.copyCommand);if (!source) return;
    const status = button.parentElement.querySelector('.github-copy-status');
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(source.textContent);
      else {
        const field = document.createElement('textarea');field.value = source.textContent;
        field.style.cssText = 'position:fixed;opacity:0';document.body.append(field);field.select();
        const copied = document.execCommand('copy');field.remove();button.focus();
        if (!copied) throw Error('copy');
      }
      status.textContent = '';button.textContent = 'Скопировано ✓';
      clearTimeout(button.restoreTimer);button.restoreTimer = setTimeout(() => { button.textContent = 'Скопировать команду'; }, 1800);
    } catch (_) { status.textContent = 'Выдели команду и скопируй через Ctrl+C.'; }
  });
})();

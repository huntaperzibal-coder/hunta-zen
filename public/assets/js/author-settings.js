(() => {
  'use strict';
  const form = document.getElementById('author-settings-form');if (!form) return;
  const notice = document.getElementById('author-settings-status');
  if (location.hash === '#author-settings-status') notice?.focus({preventScroll: true});
  form.addEventListener('submit', () => {
    document.getElementById('author-submit-status').textContent = 'Сохраняю…';
    form.querySelector('button[type="submit"]').disabled = true;
  });
})();

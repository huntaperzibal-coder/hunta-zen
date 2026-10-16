(() => {
  const root = document.documentElement;
  const preference = window.matchMedia('(prefers-color-scheme: dark)');
  let saved = null;
  try { saved = localStorage.getItem('hunta-reader-theme'); } catch (_) {}
  if (!['light', 'dark'].includes(saved)) saved = null;
  const apply = theme => {
    root.dataset.theme = theme;
    const button = document.querySelector('.theme-toggle');
    if (!button) return;
    const dark = theme === 'dark';
    button.hidden = false;
    button.setAttribute('aria-pressed', String(dark));
    button.setAttribute('aria-label', dark ? 'Включить светлую тему' : 'Включить тёмную тему');
    button.querySelector('.theme-icon').textContent = dark ? '☀' : '☾';
    button.querySelector('.theme-label').textContent = dark ? 'Светлая' : 'Тёмная';
  };
  apply(saved || (preference.matches ? 'dark' : 'light'));
  document.addEventListener('DOMContentLoaded', () => {
    apply(root.dataset.theme);
    document.querySelector('.theme-toggle')?.addEventListener('click', () => {
      saved = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem('hunta-reader-theme', saved); } catch (_) {}
      apply(saved);
    });
  });
  preference.addEventListener('change', event => {
    if (!saved) apply(event.matches ? 'dark' : 'light');
  });
})();

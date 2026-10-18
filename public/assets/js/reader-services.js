(() => {
  const trigger = document.querySelector('[data-service-toggle]');
  const dialog = document.querySelector('#reader-services');
  if (!trigger || !dialog || typeof dialog.showModal !== 'function') return;
  let scroll = '';
  trigger.addEventListener('click', event => {
    event.preventDefault();
    if (dialog.open) return;
    scroll = document.documentElement.style.overflow;
    dialog.showModal(); document.documentElement.style.overflow = 'hidden';
    trigger.setAttribute('aria-expanded', 'true');
  });
  dialog.querySelector('[data-service-close]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => {
    const box = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
  });
  dialog.addEventListener('close', () => {
    document.documentElement.style.overflow = scroll;
    trigger.setAttribute('aria-expanded', 'false'); trigger.focus({preventScroll: true});
  });
})();

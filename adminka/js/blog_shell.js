// Adapted shell interactions: ordinary links keep the editor's unsaved-change protection.
document.querySelector('.orda-mobile-toggle')?.addEventListener('click', event => {
  const side = document.querySelector('.side');
  const open = side.classList.toggle('menu-open');
  event.currentTarget.setAttribute('aria-expanded', String(open));
});
document.querySelector('.pass-toggle')?.addEventListener('click', event => {
  const input = document.querySelector('#password');
  const visible = input.type === 'password';
  input.type = visible ? 'text' : 'password';
  event.currentTarget.setAttribute('aria-pressed', String(visible));
  event.currentTarget.setAttribute('aria-label', visible ? 'Скрыть пароль' : 'Показать пароль');
});
document.addEventListener('submit', async event => {
  const button = event.submitter;
  if (!button?.dataset.confirm || button.dataset.confirmed === '1') return;
  event.preventDefault();
  if (await window.ordaConfirm(button.dataset.confirm)) {
    button.dataset.confirmed = '1';
    event.target.requestSubmit(button);
    delete button.dataset.confirmed;
  }
});

const form = document.querySelector('#editor');
const status = document.querySelector('#save-status');
let dirty = form.dataset.unsaved === '1', busy = false, stopped = false, submitting = false, pendingSubmit = null;
form.addEventListener('input', e => {
  if (e.target.matches('[name=title],[name=body],[name=excerpt],[name=telegram_text]')) {
    dirty = true; status.textContent = 'Есть несохранённые изменения';
  }
});
async function save() {
  if (!dirty || busy || stopped || submitting) return;
  busy = true;
  const data = new FormData(form); data.delete('photo'); data.set('action', 'autosave');
  const snapshot = ['title','body','excerpt','telegram_text'].map(n => form.elements[n].value).join('\u0000');
  status.textContent = 'Сохраняю…';
  try {
    const response = await fetch('/admin.php', {method: 'POST', body: data, headers: {'Accept': 'application/json'}});
    if (!response.headers.get('content-type')?.includes('application/json')) throw Error('Вход истёк. Скопируй текст перед повторным входом.');
    const result = await response.json();
    if (!response.ok) { stopped = true; throw Error(result.error || 'Не удалось сохранить'); }
    form.elements.revision.value = result.revision;
    const current = ['title','body','excerpt','telegram_text'].map(n => form.elements[n].value).join('\u0000');
    dirty = snapshot !== current;
    status.textContent = dirty ? 'Есть новые изменения' : 'Сохранено в ' + result.saved;
  } catch (error) { status.textContent = error.message || 'Нет связи. Текст остаётся в редакторе.'; }
  finally {
    busy = false;
    if (pendingSubmit && !stopped) { const button = pendingSubmit; pendingSubmit = null; form.requestSubmit(button); }
  }
}
setInterval(save, 5000);
form.addEventListener('submit', e => {
  if (busy) { e.preventDefault(); pendingSubmit = e.submitter; status.textContent = 'Завершаю автосохранение…'; return; }
  submitting = true;
});
window.addEventListener('beforeunload', e => { if ((dirty || busy) && !submitting) { e.preventDefault(); e.returnValue = ''; } });

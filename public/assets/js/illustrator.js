(() => {
  const history = document.getElementById('illustration-history');
  const progress = document.getElementById('illustration-progress');
  if (!history || progress?.dataset.active !== '1') return;
  let previous = null;
  const poll = async () => {
    try {
      const response = await fetch('/illustration-status.php?id=' + encodeURIComponent(history.dataset.post), {cache: 'no-store'});
      if (!response.ok) throw new Error('status');
      const jobs = await response.json();
      const signature = JSON.stringify(jobs);
      if (previous !== signature) {
        previous = signature;
        const page = await fetch(location.href, {cache: 'no-store'});
        if (!page.ok) throw new Error('page');
        const doc = new DOMParser().parseFromString(await page.text(), 'text/html');
        const fresh = doc.getElementById('illustration-history');
        if (fresh) history.replaceChildren(...Array.from(fresh.childNodes));
      }
      const active = jobs.some(job => ['queued', 'planning', 'generating'].includes(job.state));
      progress.textContent = active ? 'Иллюстратор работает. Можно продолжать писать.' : 'Обработка завершена. Выбери вариант из истории.';
      if (active) setTimeout(poll, 7000);
    } catch (_) {
      progress.textContent = 'Не удалось проверить состояние. Попробуем ещё раз.';
      setTimeout(poll, 15000);
    }
  };
  setTimeout(poll, 4000);
})();

(() => {
  const lightbox = document.getElementById('reader-lightbox');
  if (!lightbox) return;
  const image = lightbox.querySelector('img');
  const caption = lightbox.querySelector('.lightbox-caption');
  const previous = lightbox.querySelector('.lightbox-prev');
  const next = lightbox.querySelector('.lightbox-next');
  let images = [], position = 0, opener = null;
  const draw = () => {
    const source = images[position];
    image.src = source.currentSrc || source.src;
    image.alt = source.alt || 'Иллюстрация к посту';
    const description = source.closest('figure')?.querySelector('figcaption')?.textContent || source.alt || '';
    caption.textContent = description + (images.length > 1 ? ` · ${position + 1} / ${images.length}` : '');
    previous.hidden = next.hidden = images.length < 2;
  };
  const move = step => { position = (position + step + images.length) % images.length; draw(); };
  previous.addEventListener('click', () => move(-1));
  next.addEventListener('click', () => move(1));
  lightbox.querySelector('.lightbox-close').addEventListener('click', () => lightbox.close());
  lightbox.addEventListener('click', event => {
    if (event.target === lightbox || event.target.classList.contains('lightbox-stage')) lightbox.close();
  });
  lightbox.addEventListener('keydown', event => {
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault(); move(event.key === 'ArrowLeft' ? -1 : 1);
    }
  });
  lightbox.addEventListener('close', () => {
    document.body.classList.remove('lightbox-open'); image.removeAttribute('src');
    opener?.focus({preventScroll: true});
  });
  document.querySelectorAll('.story-image-link, .story .prose img').forEach(node => {
    if (node.tagName === 'IMG' && !node.closest('a')) {
      node.tabIndex = 0; node.setAttribute('role', 'button');
      node.setAttribute('aria-label', 'Открыть изображение: ' + (node.alt || 'иллюстрация'));
      node.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); node.click(); }
      });
    }
  });
  const copy = async url => {
    if (navigator.clipboard?.writeText) {
      try { await navigator.clipboard.writeText(url); return true; } catch (_) {}
    }
    const field = document.createElement('textarea');
    field.value = url; field.className = 'clipboard-buffer';
    field.setAttribute('readonly', '');
    const focused = document.activeElement;
    document.body.append(field); field.select();
    let copied = false;
    try { copied = document.execCommand('copy'); } catch (_) {}
    field.remove(); focused?.focus({preventScroll: true});
    return copied;
  };
  const feedbackTimers = new WeakMap();
  document.querySelectorAll('[data-share]').forEach(button => { button.hidden = false; });
  document.addEventListener('click', async event => {
    const action = event.target.closest('[data-share]');
    if (action) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      const box = action.closest('.story-actions');
      const copied = await copy(box.dataset.postUrl);
      clearTimeout(feedbackTimers.get(action));
      action.classList.toggle('is-copied', copied);
      action.title = copied ? 'Ссылка скопирована' : 'Не удалось скопировать ссылку';
      action.setAttribute('aria-label', action.title);
      document.getElementById('reader-action-status').textContent = action.title;
      feedbackTimers.set(action, setTimeout(() => {
        action.classList.remove('is-copied');
        action.title = 'Скопировать ссылку';
        action.setAttribute('aria-label', 'Скопировать ссылку на запись «' + box.dataset.postTitle + '»');
        document.getElementById('reader-action-status').textContent = '';
      }, 2000));
      return;
    }
    const source = event.target.closest('.story img');
    if (!source || !lightbox.showModal || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    const link = source.closest('a');
    if (link && !link.classList.contains('story-image-link') && link.href !== source.src) return;
    event.preventDefault();
    images = [...source.closest('.story').querySelectorAll('img')];
    position = images.indexOf(source); opener = link || source;
    draw(); lightbox.showModal(); document.body.classList.add('lightbox-open');
    lightbox.querySelector('.lightbox-close').focus();
  });
})();

(() => {
  const lightbox = document.getElementById('reader-lightbox');
  const shareDialog = document.getElementById('reader-share');
  if (!lightbox || !shareDialog) return;
  const image = lightbox.querySelector('img');
  const caption = lightbox.querySelector('.lightbox-caption');
  const previous = lightbox.querySelector('.lightbox-prev');
  const next = lightbox.querySelector('.lightbox-next');
  const shareInput = shareDialog.querySelector('input');
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
    return false;
  };
  const showLink = box => {
    shareDialog.querySelector('.share-post-title').textContent = box.dataset.postTitle;
    shareDialog.querySelector('.share-dialog-status').textContent = '';
    shareInput.value = box.dataset.postUrl;
    shareDialog.showModal(); shareInput.focus(); shareInput.select();
  };
  shareDialog.querySelector('[data-close-share]').addEventListener('click', () => shareDialog.close());
  shareDialog.querySelector('[data-copy-dialog]').addEventListener('click', async () => {
    let copied = await copy(shareInput.value);
    if (!copied) {
      shareInput.focus(); shareInput.select();
      try { copied = document.execCommand('copy'); } catch (_) {}
    }
    shareDialog.querySelector('.share-dialog-status').textContent = copied ? 'Ссылка скопирована' : 'Ссылка выделена. Нажми Ctrl+C или выбери «Копировать».';
  });
  document.querySelectorAll('[data-share]').forEach(button => { button.hidden = false; });
  document.addEventListener('click', async event => {
    const action = event.target.closest('[data-share]');
    if (action) {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      const box = action.closest('.story-actions');
      if (navigator.share) {
        try { await navigator.share({title: box.dataset.postTitle, url: box.dataset.postUrl}); }
        catch (error) { if (error.name !== 'AbortError') showLink(box); }
      } else showLink(box);
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

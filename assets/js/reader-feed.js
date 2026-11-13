(() => {
  const layout = document.querySelector('.reading-layout');
  if (!layout || !window.fetch || !window.AbortController) return;
  let pending = null;
  let rendered = location.pathname + location.search;
  const load = async (url, restore = null) => {
    pending?.abort();
    const request = new AbortController();
    pending = request;
    const feed = layout.querySelector('.reading-feed');
    feed.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {signal: request.signal, credentials: 'same-origin'});
      if (!response.ok) throw new Error('Navigation failed');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const incoming = page.querySelector('.reading-layout');
      if (!incoming || request.signal.aborted) throw new Error('Missing feed');
      if (restore === null) {
        history.replaceState({...history.state, readerScroll: window.scrollY}, '', location.href);
        history.pushState({readerScroll: 0}, '', url);
      }
      feed.replaceChildren(...incoming.querySelector('.reading-feed').childNodes);
      const sidebar = layout.querySelector('.reading-sidebar');
      const tabs = incoming.querySelectorAll('.category-tabs a');
      sidebar.querySelectorAll('.category-tabs a').forEach(link => {
        const match = [...tabs].find(item => item.getAttribute('href') === link.getAttribute('href'));
        link.classList.toggle('active', !!match?.classList.contains('active'));
      });
      sidebar.querySelector('.reading-toc')?.remove();
      const toc = incoming.querySelector('.reading-toc');
      if (toc) sidebar.append(toc);
      rendered = new URL(url).pathname + new URL(url).search;
      document.title = page.title;
      for (const selector of ['meta[name="description"]', 'link[rel="canonical"]', 'meta[property^="og:"]', 'meta[name^="twitter:"]']) {
        document.head.querySelectorAll(selector).forEach(node => node.remove());
        page.head.querySelectorAll(selector).forEach(node => document.head.append(node));
      }
      const structured = document.head.querySelector('script[type="application/ld+json"]');
      if (structured) structured.textContent = page.head.querySelector('script[type="application/ld+json"]')?.textContent || '';
      document.dispatchEvent(new Event('reader:feed-updated'));
      window.scrollTo({top: restore === null ? 0 : restore, behavior: 'instant'});
    } catch (error) {
      if (error.name !== 'AbortError' && !request.signal.aborted) location.assign(url);
    } finally {
      if (pending === request) { feed.removeAttribute('aria-busy'); pending = null; }
    }
  };
  document.addEventListener('click', event => {
    const link = event.target.closest('.category-tabs a, .story-meta a, .pagination a, .reading-feed .back');
    if (!link || !layout.contains(link) || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || link.target || link.hasAttribute('download')) return;
    const url = new URL(link.href);
    if (url.origin !== location.origin || url.pathname !== '/' || url.searchParams.has('p')) return;
    event.preventDefault();
    load(url.href);
  });
  window.addEventListener('popstate', event => {
    if (rendered !== location.pathname + location.search) load(location.href, event.state?.readerScroll || 0);
    else pending?.abort();
  });
})();

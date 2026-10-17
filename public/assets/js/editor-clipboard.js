/* Clipboard interchange: text and basic Markdown only; imported HTML never executes. */
(() => {
  'use strict';
  const escape = text => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  const inline = text => escape(text)
    .replace(/\*\*([^\n]+?)\*\*/g, '<strong>$1</strong>')
    .replace(/(?<!\*)\*([^*\n]+?)\*(?!\*)/g, '<em>$1</em>')
    .replace(/~~([^\n]+?)~~/g, '<s>$1</s>')
    .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2">$1</a>');
  function fromText(raw, withTitle = false) {
    let text = String(raw || '').replace(/\r\n?/g, '\n').replace(/\u00a0/g, ' ').replace(/\\\n/g, '\n');
    let title = '';
    if (withTitle) { const lines = text.split('\n'); title = lines.shift().replace(/^#{1,6}\s+/, '').replace(/^\*\*(.*?)\*\*$/, '$1').trim(); if (lines[0]?.trim() === '') lines.shift(); text = lines.join('\n'); }
    if (!text.trim()) return {title, html: ''};
    const blocks = []; let paragraph = [];
    const flush = () => { if (paragraph.length) { blocks.push('<p>' + paragraph.map(inline).join('<br>') + '</p>'); paragraph = []; } };
    for (const line of text.replace(/\n+$/, '').split('\n')) {
      if (!line.trim()) { if (paragraph.length) flush(); else blocks.push('<p>&nbsp;</p>'); }
      else if (/^#{1,3}\s+/.test(line)) { flush(); const level = /^###/.test(line) ? 'h3' : 'h2'; blocks.push('<' + level + '>' + inline(line.replace(/^#{1,3}\s+/, '')) + '</' + level + '>'); }
      else paragraph.push(line.replace(/ {2,}$/, ''));
    }
    flush(); return {title, html: blocks.join('')};
  }
  function toText(html, title = '') {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const walk = node => {
      if (node.nodeType === 3 && node.parentNode === doc.body && !node.nodeValue.trim()) return '';
      if (node.nodeType === 3) return node.nodeValue.replace(/\u00a0/g, ' ');
      if (node.nodeType !== 1) return '';
      const tag = node.tagName;
      if (['SCRIPT','STYLE','IMG'].includes(tag)) return '';
      if (tag === 'BR') return '\n';
      const text = Array.from(node.childNodes).map(walk).join('');
      if (['STRONG','B'].includes(tag)) return '**' + text + '**';
      if (['EM','I'].includes(tag)) return '*' + text + '*';
      if (['S','DEL'].includes(tag)) return '~~' + text + '~~';
      if (tag === 'A' && /^https?:\/\//.test(node.getAttribute('href') || '')) return '[' + text + '](' + node.getAttribute('href') + ')';
      if (tag === 'LI') return '• ' + text + '\n';
      if (['P','H1','H2','H3','H4','BLOCKQUOTE','PRE'].includes(tag)) return text.trim() ? text + '\n\n' : '\n';
      return text;
    };
    const body = Array.from(doc.body.childNodes).map(walk).join('').replace(/\n+$/, '');
    return (title.trim() ? title.trim() + '\n\n' : '') + body;
  }
  window.DaoClipboard = {fromText, toText};
})();

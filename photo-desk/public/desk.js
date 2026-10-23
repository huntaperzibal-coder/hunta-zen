'use strict';
const dirty = new Set();
document.querySelectorAll('form[data-upload]').forEach(form => {
  form.addEventListener('input', () => dirty.add(form));
  form.addEventListener('submit', () => { dirty.delete(form); form.querySelectorAll('button').forEach(b => {b.disabled=true;}); });
  const input=form.querySelector('input[type=file]'), box=form.querySelector('.local-previews');
  let urls=[];
  input?.addEventListener('change',()=>{
    urls.forEach(URL.revokeObjectURL);urls=[];box.replaceChildren();
    for(const file of input.files){const card=document.createElement('div');card.className='local-file';if(['image/jpeg','image/png','image/webp','image/gif'].includes(file.type)){const img=document.createElement('img');const url=URL.createObjectURL(file);urls.push(url);img.src=url;img.alt=file.name;card.append(img);}const name=document.createElement('span');name.textContent=file.name;card.append(name);box.append(card);}
  });
});
window.addEventListener('beforeunload',e=>{if(dirty.size){e.preventDefault();e.returnValue='';}});
document.querySelectorAll('[data-flag]').forEach(input=>input.addEventListener('change',()=>{
  if(dirty.size && !confirm('Есть несохранённый текст. Переключить галочку и покинуть форму?')){input.checked=!input.checked;return;}
  dirty.clear();input.form.requestSubmit();
}));
document.querySelectorAll('[data-confirm]').forEach(button=>button.addEventListener('click',e=>{if(!confirm(button.dataset.confirm))e.preventDefault();}));
document.querySelectorAll('.fold').forEach(button=>button.addEventListener('click',()=>{const body=document.getElementById(button.getAttribute('aria-controls'));body.hidden=!body.hidden;button.setAttribute('aria-expanded',String(!body.hidden));}));
document.querySelector('[data-new]')?.addEventListener('click',()=>{const panel=document.getElementById('new-case');panel.open=true;panel.scrollIntoView({behavior:'smooth',block:'start'});panel.querySelector('input[name=title]').focus();});
const viewer=document.getElementById('image-view');
document.querySelectorAll('[data-image]').forEach(a=>a.addEventListener('click',e=>{if(!viewer?.showModal)return;e.preventDefault();viewer.querySelector('img').src=a.href;viewer.querySelector('a').href=a.href+'&download=1';viewer.showModal();}));
viewer?.querySelector('button').addEventListener('click',()=>viewer.close());
viewer?.addEventListener('click',e=>{if(e.target===viewer)viewer.close();});
document.documentElement.classList.add('js');
// Paste images and drop files into either side without hunting for a file picker.
document.querySelectorAll('.case-body > .panel, .new').forEach(panel=>{
  const form=panel.querySelector('form[data-upload]');
  if(!form)return;
  panel.tabIndex=0;
  const input=form.querySelector('input[type=file]');
  const accept=files=>{
    if(!files.length)return;
    const transfer=new DataTransfer();
    for(const file of input.files)transfer.items.add(file);
    for(const file of files)transfer.items.add(file);
    if(transfer.files.length>12){alert('За один раз можно загрузить до 12 файлов.');return;}
    const details=form.closest('details');if(details)details.open=true;
    input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));dirty.add(form);
  };
  panel.addEventListener('paste',event=>{
    const files=Array.from(event.clipboardData?.files||[]).filter(file=>file.type.startsWith('image/'));
    if(files.length){event.preventDefault();accept(files);}
  });
  panel.addEventListener('dragover',event=>{if(Array.from(event.dataTransfer?.types||[]).includes('Files')){event.preventDefault();panel.classList.add('dragging');}});
  panel.addEventListener('dragleave',event=>{if(!panel.contains(event.relatedTarget))panel.classList.remove('dragging');});
  panel.addEventListener('drop',event=>{const files=Array.from(event.dataTransfer?.files||[]);panel.classList.remove('dragging');if(files.length){event.preventDefault();accept(files);}});
});

'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const input=new EventTarget();input.value='';input.setCustomValidity=value=>{input.validation=value;};input.reportValidity=()=>{input.reported=true;};
const form=new EventTarget();form.elements={delete_word:input};form.dataset={caseId:'12'};
const document={querySelectorAll:s=>s==='form[data-delete-case]'?[form]:(s==='[name=delete_word]'?[input]:[]),querySelector:()=>null,getElementById:()=>null,documentElement:{classList:{add(){}}}};
let confirmations=0,accepted=false;
const context={document,window:{addEventListener(){}},confirm:()=>{confirmations++;return accepted;},Set,URL,AbortController};
vm.runInNewContext(fs.readFileSync(require.resolve('../photo-desk/public/desk.js'),'utf8'),context);
function submit(){const event=new Event('submit',{cancelable:true});form.dispatchEvent(event);return event;}
input.value='нет';assert(submit().defaultPrevented);assert.equal(confirmations,0);assert(input.reported);
input.value='удалить';input.dispatchEvent(new Event('input'));assert.equal(input.validation,'');assert(submit().defaultPrevented);assert.equal(confirmations,1,'cancel sends nothing');
accepted=true;input.value='УДАЛИТЬ';assert(!submit().defaultPrevented);assert.equal(confirmations,2,'one final confirmation per valid submission');
console.log('OK: typed word required, cancel blocks deletion, accept permits one submission');

const opener=new EventTarget(),panel=new EventTarget();let focused=0;
panel.open=false;panel.scrollIntoView=()=>{};panel.querySelector=selector=>{assert.equal(selector,'input[name=source_url]');return {focus(){focused++;}};};
const sourceDocument={...document,querySelector:s=>s==='[data-new]'?opener:null,querySelectorAll:()=>[],getElementById:id=>id==='new-case'?panel:null};
vm.runInNewContext(fs.readFileSync(require.resolve('../photo-desk/public/desk.js'),'utf8'),{...context,document:sourceDocument});
opener.dispatchEvent(new Event('click'));assert(panel.open);assert.equal(focused,1);
panel.dispatchEvent(new Event('toggle'));assert.equal(focused,2);
console.log('OK: both ways of opening a new request focus its source URL');

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

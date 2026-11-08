const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const events = {}, windowEvents = {}, tasks = [];
function element(attrs = {}) {
 return {attrs, getAttribute(key){return this.attrs[key] ?? null;}, setAttribute(key,value){this.attrs[key]=value;}, removeAttribute(key){delete this.attrs[key];}};
}
const button = element({name:'action',value:'save'});
const form = element(); form.method='post'; form.querySelectorAll=()=>[button];
const header = element(), region = element();
region.querySelector=()=>({querySelector:()=>null,querySelectorAll:()=>[header]});
region.closest=()=>({querySelector:()=>({textContent:'Vendas recentes'})});
let focused=false;
const errors={focus(){focused=true;}};
vm.runInNewContext(fs.readFileSync('public/assets/ux.js','utf8'), {
 document:{addEventListener:(key,fn)=>events[key]=fn,querySelectorAll:()=>[region],querySelector:()=>errors},
 window:{addEventListener:(key,fn)=>windowEvents[key]=fn},queueMicrotask:fn=>tasks.push(fn)
});
function submit(prevented=false) {
 const event={target:form,defaultPrevented:prevented,preventDefault(){this.defaultPrevented=true;}};
 events.submit(event); while(tasks.length)tasks.shift()(); return event;
}
submit(true); assert.equal(form.getAttribute('aria-busy'),null);
submit(); assert.equal(form.getAttribute('aria-busy'),'true');
assert.equal(button.getAttribute('aria-disabled'),'true');
assert.equal(button.disabled,undefined); assert.equal(button.getAttribute('value'),'save');
assert.equal(submit().defaultPrevented,true);
windowEvents.pageshow(); assert.equal(form.getAttribute('aria-busy'),null);
assert.equal(button.getAttribute('aria-disabled'),null);
assert.equal(submit().defaultPrevented,false);
windowEvents.pageshow(); form.method='get'; submit();assert.equal(form.getAttribute('aria-busy'),null);
form.method='post';form.target='_blank';submit();assert.equal(form.getAttribute('aria-busy'),null);
assert.equal(region.tabIndex,0);assert.equal(region.getAttribute('aria-label'),'Vendas recentes');
assert.equal(header.getAttribute('scope'),'col');assert.equal(focused,true);
console.log('UX: envio repetido, cancelamento, payload, retorno pelo navegador, GET, nova aba e tabelas acessíveis OK.');

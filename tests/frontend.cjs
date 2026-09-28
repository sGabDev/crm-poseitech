const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
 constructor(){this.events={};this.children=[];this.value='';this.dataset={};}
 addEventListener(name, fn){this.events[name]=fn;}
 append(...children){this.children.push(...children);}
 replaceChildren(){this.children=[];}
 setCustomValidity(message){this.validationMessage=message;}
 setSelectionRange(start){this.selectionStart=start;}
 dispatchEvent(event){this.events[event.type]?.(event);}
}
const phone=new Element();
const input=new Element();
const list=new Element();
const box=new Element();box.querySelector=s=>s==='.search-input'?input:list;
const select=new Element();select.closest=()=>({querySelector:()=>box});
select.options=[{value:'1',textContent:'João Silva',dataset:{search:'João Silva +55 (11) 99999-8888 123.456.789-09'}},{value:'2',textContent:'Maria',dataset:{search:'Maria'}}];select.selectedOptions=[];
const document={querySelector:()=>null,querySelectorAll:selector=>selector==='[data-phone]'?[phone]:selector==='[data-search-select]'?[select]:[],createElement:()=>new Element()};
vm.runInNewContext(fs.readFileSync('public/assets/app.js','utf8'),{document,Intl,Event:class{constructor(type){this.type=type;}},setTimeout});
for(const digit of '11999998888'){phone.value+=digit;phone.selectionStart=phone.value.length;phone.events.input();}
assert.equal(phone.value,'+55 (11) 99999-8888');
phone.value='(21) 3333-4444';phone.selectionStart=phone.value.length;phone.events.input();
assert.equal(phone.value,'+55 (21) 3333-4444');
for(const term of ['joao','11999998888','12345678909']){
 input.value=term;input.events.input();assert.equal(list.children.length,1);list.children[0].events.click();assert.equal(select.value,'1');assert.equal(input.value,'João Silva');assert.equal(list.hidden,true);
}
input.value='inexistente';input.events.input();assert.equal(select.value,'');assert.ok(input.validationMessage);assert.equal(list.children.length,0);
console.log('Busca por nome, telefone e CPF; seleção e máscara durante digitação: OK');

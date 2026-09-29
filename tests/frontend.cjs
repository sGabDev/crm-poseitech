const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
 constructor(){this.events={};this.children=[];this.value='';this.dataset={};}
 addEventListener(name, fn){const previous=this.events[name];this.events[name]=event=>{previous?.(event);fn(event);};}
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

// Exercise the real sale handlers: automatic values must stop overwriting an edit.
const nodes={};
for(const id of ['auto-payment','discount','extra','subtotal','sale-total','sale-pending','pending-row','wallet-row','wallet-remaining','wallet-current','wallet-current-row','barcode','barcode-quantity','barcode-message','allow-negative-stock','reset-payment','add-item','add-payment','use-balance'])nodes['#'+id]=new Element();
nodes['#auto-payment'].value='1';nodes['#discount'].value='0';nodes['#extra'].value='0';nodes['#use-balance'].checked=true;
const product={value:'1',selectedOptions:[{value:'1',textContent:'Produto A',dataset:{price:'10000',code:'789123',stock:'10',type:'product'}}]};product.options=product.selectedOptions;nodes['#barcode-quantity'].value='3';nodes['#barcode'].focus=()=>{};nodes['#barcode'].select=()=>{};
const quantity={value:'2'};
const customToggle={checked:false};const customPrice={value:'12.50'};const searchBox=new Element();const addon=new Element();addon.options=[];addon.selectedOptions=[];addon.closest=()=>({});const saleLine={querySelector:s=>s==='.product-select'?product:s==='.quantity'?quantity:s==='.custom-toggle'?customToggle:s==='.custom-price'?customPrice:s==='.catalog-item-label'?{}:s==='.search-input'?searchBox:s==='.addon-select'?addon:null,querySelectorAll:()=>[]};
const received=new Element();received.value='0';received.matches=s=>s==='.payment-amount';
const paymentMethod={value:'pix'};
const paymentLine={querySelector:s=>s==='select'?paymentMethod:received};
const saleCustomer={selectedOptions:[{dataset:{wallet:'0'}}]};
const form=new Element();form.dataset={currency:'BRL',stock:'1'};
form.querySelectorAll=s=>s==='.sale-line'?[saleLine]:s==='.payment-line'?[paymentLine]:[];
form.querySelector=s=>s==='[name=customer_id]'?saleCustomer:s==='.product-select'?product:null;
nodes['#sale-form']=form;
const saleDocument={querySelector:s=>nodes[s]||null,querySelectorAll:s=>s==='.sale-line'?[saleLine]:s==='.payment-line'?[paymentLine]:[]};
vm.runInNewContext(fs.readFileSync('public/assets/app.js','utf8'),{document:saleDocument,Intl,setTimeout,confirm:()=>false});
assert.equal(received.value,'200.00');assert.equal(received.readOnly,false);
received.value='12.34';form.events.input({target:received});assert.equal(received.value,'12.34');assert.equal(nodes['#auto-payment'].value,'0');
nodes['#discount'].value='10%';nodes['#extra'].value='5%';form.events.input({target:{matches:()=>false}});assert.equal(received.value,'12.34');
nodes['#reset-payment'].events.click();assert.equal(received.value,'190.00');assert.equal(nodes['#pending-row'].hidden,true);
saleCustomer.selectedOptions[0].dataset.wallet='25000';form.events.input({target:{matches:()=>false}});assert.equal(received.value,'0.00');assert.equal(nodes['#wallet-row'].hidden,false);
paymentMethod.value='fiado';saleCustomer.selectedOptions[0].dataset.wallet='0';form.events.input({target:{matches:()=>false}});assert.equal(nodes['#pending-row'].hidden,false);assert.equal(received.value,'190.00');
console.log('Valor recebido editável, cálculo percentual e saldos condicionais: OK');

assert.equal(nodes['#wallet-current-row'].hidden,true);
saleCustomer.selectedOptions[0].dataset.wallet='5000';form.events.input({target:{matches:()=>false}});assert.equal(nodes['#wallet-current-row'].hidden,false);
customToggle.checked=true;nodes['#discount'].value='0';nodes['#extra'].value='0';form.events.input({target:{matches:()=>false}});assert.match(nodes['#subtotal'].textContent,/25,00/);
customToggle.checked=false;nodes['#barcode'].value='789123';nodes['#barcode'].events.keydown({key:'Enter',preventDefault(){},target:nodes['#barcode']});assert.equal(quantity.value,5);assert.equal(nodes['#barcode'].value,'');
nodes['#barcode'].value='unknown';nodes['#barcode'].events.keydown({key:'Enter',preventDefault(){},target:nodes['#barcode']});assert.equal(quantity.value,5);
quantity.value='12';let prevented=false;form.events.submit({preventDefault(){prevented=true;}});assert.equal(prevented,true);assert.equal(nodes['#allow-negative-stock'].value,'0');
console.log('Item avulso, saldo atual, leitor com quantidade e recusa de estoque negativo: OK');

(async()=>{
 const previewNodes={...nodes};
 for(const id of ['coupon-feedback','coupon-summary','coupon-discount'])previewNodes['#'+id]=new Element();
 const previewForm=new Element();previewForm.dataset={currency:'BRL',couponUrl:'/coupons/preview'};
 const couponInput=new Element();couponInput.value='TESTE';couponInput.matches=()=>false;
 previewForm.querySelectorAll=form.querySelectorAll;
 previewForm.querySelector=selector=>selector==='[name=coupon]'?couponInput:form.querySelector(selector);
 previewNodes['#sale-form']=previewForm;
 quantity.value='2';customToggle.checked=false;saleCustomer.selectedOptions[0].dataset.wallet='0';paymentMethod.value='pix';
 previewNodes['#discount'].value='0';previewNodes['#extra'].value='0';previewNodes['#auto-payment'].value='1';
 let scheduled;let result={ok:true,json:async()=>({discount:2000,label:'Desconto validado'})};
 const previewDocument={querySelector:selector=>previewNodes[selector]||null,querySelectorAll:saleDocument.querySelectorAll};
 vm.runInNewContext(fs.readFileSync('public/assets/app.js','utf8'),{document:previewDocument,Intl,setTimeout:fn=>(scheduled=fn,1),clearTimeout(){},fetch:async()=>result,FormData:class{},confirm:()=>false});
 let blocked=false;previewForm.events.submit({preventDefault(){blocked=true;}});assert.equal(blocked,true);
 await scheduled();assert.match(previewNodes['#sale-total'].textContent,/180,00/);assert.equal(received.value,'180.00');assert.equal(previewNodes['#coupon-summary'].hidden,false);
 result={ok:false,json:async()=>({errors:{coupon:['Cupom vencido']}})};
 previewForm.events.input({target:couponInput});await scheduled();assert.equal(previewNodes['#coupon-feedback'].textContent,'Cupom vencido');assert.match(previewNodes['#sale-total'].textContent,/200,00/);
 blocked=false;previewForm.events.submit({preventDefault(){blocked=true;}});assert.equal(blocked,true);
 console.log('Cupom em tempo real: desconto, pagamento recalculado e bloqueio durante validação/falha: OK');
})().catch(error=>{console.error(error);process.exitCode=1;});

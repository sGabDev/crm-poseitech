function enhanceSearchSelect(select) {
 if(select.dataset.enhanced)return;
 select.dataset.enhanced='1';select.hidden=true;
 const box=document.createElement('div');box.className='search-picker';
 const input=document.createElement('input');input.type='search';input.placeholder=select.dataset.searchSelect;input.autocomplete='off';input.setAttribute('aria-label',select.dataset.searchSelect);input.required=select.required;
 const list=document.createElement('div');list.className='search-results';list.hidden=true;
 box.append(input,list);select.after(box);
 input.value=select.value?select.selectedOptions[0].textContent.trim():'';
 const normalize=value=>value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
 const render=()=>{list.replaceChildren();const term=normalize(input.value.trim());const digits=term.replace(/\D/g,'');const matches=[...select.options].filter(option=>{const text=normalize(option.dataset.search||option.textContent);return text.includes(term)||(digits.length>0&&/^[\d\s()+.\/-]+$/.test(term)&&text.replace(/\D/g,'').includes(digits));}).slice(0,40);
 matches.forEach(option=>{const button=document.createElement('button');button.type='button';button.textContent=option.textContent;button.addEventListener('click',()=>{select.value=option.value;input.value=option.value?option.textContent.trim():'';input.setCustomValidity('');list.hidden=true;select.dispatchEvent(new Event('change',{bubbles:true}));});list.append(button);});
 if(!matches.length)list.textContent='Nenhum resultado encontrado.';list.hidden=false;};
 input.addEventListener('input',()=>{select.value='';input.setCustomValidity(input.value?'Selecione um resultado da lista.':'');select.dispatchEvent(new Event('change',{bubbles:true}));render();});input.addEventListener('focus',render);
 input.addEventListener('keydown',event=>{if(event.key==='Escape')list.hidden=true;if(event.key==='ArrowDown'){event.preventDefault();list.querySelector('button')?.focus();}if(event.key==='Enter'&&!list.hidden){event.preventDefault();list.querySelector('button')?.click();}});
 list.addEventListener('keydown',event=>{if(event.key==='ArrowDown'){event.preventDefault();event.target.nextElementSibling?.focus();}if(event.key==='ArrowUp'){event.preventDefault();(event.target.previousElementSibling||input).focus();}if(event.key==='Escape'){list.hidden=true;input.focus();}});
 box.addEventListener('focusout',()=>setTimeout(()=>{if(!box.contains(document.activeElement))list.hidden=true;},0));
}
document.querySelectorAll('[data-search-select]').forEach(enhanceSearchSelect);
document.querySelectorAll('[data-phone]').forEach(input=>{
 const format=()=>{const raw=input.value;let digits=raw.replace(/\D/g,'');if(!digits)return;if(!raw.startsWith('+'))digits='55'+digits;if(!digits.startsWith('55')){input.value='+'+digits.slice(0,15);return;}const local=digits.slice(2,13);let value='+55';if(local.length)value+=' ('+local.slice(0,2);if(local.length>=2)value+=') ';const number=local.slice(2);value+=number.length>4?number.slice(0,number.length>8?5:4)+'-'+number.slice(number.length>8?5:4):number;input.value=value;};
 input.addEventListener('input',()=>{const before=input.value;const caret=input.selectionStart;const count=before.slice(0,caret).replace(/\D/g,'').length;format();if(caret<before.length){let seen=0;let position=0;for(;position<input.value.length;position++){if(/\d/.test(input.value[position]))seen++;if(seen>=count){position++;break;}}input.setSelectionRange(position,position);}});format();
});
document.querySelector('.menu-toggle')?.addEventListener('click', e => { const open=document.querySelector('#sidebar').classList.toggle('open');e.currentTarget.setAttribute('aria-expanded',String(open)); });
document.querySelectorAll('[data-print]').forEach(button=>button.addEventListener('click',()=>window.print()));
document.querySelectorAll('form[data-confirm]').forEach(form=>form.addEventListener('submit',e=>{if(!confirm(form.dataset.confirm))e.preventDefault();}));
const saleForm=document.querySelector('#sale-form');
if(saleForm){
 const currency=value=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:saleForm.dataset.currency||'BRL'}).format(value/100);
 const cents=value=>Math.round((Number(value)||0)*100);
 const loadAddons=line=>{const product=line.querySelector('.product-select');const select=line.querySelector('.addon-select');if(!select)return;const selected=JSON.parse(select.dataset.selected||'[]').map(String);select.replaceChildren();JSON.parse(product.selectedOptions[0]?.dataset.addons||'[]').forEach((addon,index)=>{const option=new Option(addon.name+' · '+currency(addon.price),String(index));option.dataset.price=addon.price;option.selected=selected.includes(String(index));select.add(option);});select.closest('label').hidden=select.options.length===0;};
 const recalc=()=>{let subtotal=0;document.querySelectorAll('.sale-line').forEach(line=>{let price=Number(line.querySelector('.product-select').selectedOptions[0]?.dataset.price||0);line.querySelectorAll('.addon-select option:checked').forEach(option=>price+=Number(option.dataset.price));subtotal+=price*Number(line.querySelector('.quantity').value||0)});let total=subtotal-cents(document.querySelector('#discount').value)+cents(document.querySelector('#extra').value);if(saleForm.querySelector('[name=delivery]')?.checked && saleForm.querySelector('[name=order]')?.checked)total+=cents(document.querySelector('#delivery-fee')?.value);const rows=[...document.querySelectorAll('.payment-line')];const single=rows.length===1;document.querySelector('#auto-payment').value=single?'1':'0';let paid=0;rows.forEach(row=>{const input=row.querySelector('.payment-amount');const fiado=row.querySelector('select').value==='fiado';input.readOnly=single||fiado;if(single)input.value=(Math.max(0,total)/100).toFixed(2);if(!fiado)paid+=cents(input.value);});let remainder=Math.max(0,total-paid);rows.filter(row=>row.querySelector('select').value==='fiado').forEach((row,index)=>{row.querySelector('.payment-amount').value=(index===0?remainder/100:0).toFixed(2);});document.querySelector('#subtotal').textContent=currency(subtotal);document.querySelector('#sale-total').textContent=currency(total);document.querySelector('#sale-pending').textContent=currency(Math.max(0,total-paid));};
 let itemIndex=document.querySelectorAll('.sale-line').length,paymentIndex=document.querySelectorAll('.payment-line').length;
 const add=(container,selector,prefix,index)=>{const parent=document.querySelector(container);const row=parent.querySelector(selector).cloneNode(true);row.querySelectorAll('[name]').forEach(input=>{input.name=input.name.replace(new RegExp(prefix+'\\[\\d+\\]'),prefix+'['+index+']');if(input.tagName==='SELECT')input.selectedIndex=0;else input.value=prefix==='items'?'1':'0';});row.querySelectorAll('.search-picker').forEach(el=>el.remove());row.querySelectorAll('[data-search-select]').forEach(el=>{el.hidden=false;delete el.dataset.enhanced;});parent.append(row);row.querySelectorAll('[data-search-select]').forEach(enhanceSearchSelect);if(prefix==='items')loadAddons(row);recalc();};
 document.querySelector('#add-item').addEventListener('click',()=>add('#sale-items','.sale-line','items',itemIndex++));
 document.querySelector('#add-payment').addEventListener('click',()=>add('#sale-payments','.payment-line','payments',paymentIndex++));
 saleForm.addEventListener('click',e=>{if(e.target.closest('.remove-line')){const row=e.target.closest('.sale-line,.payment-line');if(row.parentElement.children.length>1)row.remove();recalc();}});
 document.querySelectorAll('.sale-line').forEach(loadAddons);
 saleForm.addEventListener('input',recalc);saleForm.addEventListener('change',e=>{if(e.target.matches('.product-select'))loadAddons(e.target.closest('.sale-line'));recalc();});saleForm.addEventListener('submit',()=>{const button=saleForm.querySelector('[type=submit],button.full-width');if(button){button.disabled=true;button.textContent='Registrando...';}});recalc();
}


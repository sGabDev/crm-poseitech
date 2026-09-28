function enhanceSearchSelect(select) {
 if(select.dataset.enhanced)return;
 select.dataset.enhanced='1';select.hidden=true;
 const box=select.closest('label').querySelector('.search-picker');
 const input=box.querySelector('.search-input');
 const list=box.querySelector('.search-results');list.hidden=true;
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
const depositForm=document.querySelector('#deposit-form');
const operationType=document.querySelector('#operation-type');
if(operationType){const toggle=()=>{saleForm.hidden=operationType.value!=='sale';depositForm.hidden=operationType.value!=='deposit';};operationType.addEventListener('change',toggle);toggle();}
if(depositForm){const preview=()=>{const option=depositForm.querySelector('[name=customer_id]').selectedOptions[0];const amount=Math.round(Number(depositForm.querySelector('[name=amount]').value||0)*100);const debt=Number(option?.dataset.debt||0);const wallet=Number(option?.dataset.wallet||0);const money=value=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:depositForm.dataset.currency}).format(value/100);const texts=[];if(debt>amount)texts.push('Valor em fiado: '+money(debt-amount));if(wallet+Math.max(0,amount-debt)>0)texts.push('Valor restante na conta: '+money(wallet+Math.max(0,amount-debt)));depositForm.querySelector('#deposit-preview').textContent=texts.join(' · ');};depositForm.addEventListener('input',preview);depositForm.addEventListener('change',preview);preview();}
if(saleForm){
 const currency=value=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:saleForm.dataset.currency||'BRL'}).format(value/100);
 const cents=value=>Math.round((Number(value)||0)*100);
 const loadAddons=line=>{const product=line.querySelector('.product-select');const select=line.querySelector('.addon-select');if(!select)return;const selected=JSON.parse(select.dataset.selected||'[]').map(String);select.replaceChildren();JSON.parse(product.selectedOptions[0]?.dataset.addons||'[]').forEach((addon,index)=>{const option=new Option(addon.name+' · '+currency(addon.price),String(index));option.dataset.price=addon.price;option.selected=selected.includes(String(index));select.add(option);});select.closest('label').hidden=select.options.length===0;};
 const adjustment=(value,subtotal)=>{const text=String(value||0).trim().replace(',','.');return text.endsWith('%')?Math.round(subtotal*(parseFloat(text)||0)/100):cents(text);};
 let manualPayment=document.querySelector('#auto-payment').value==='0';
 const recalc=()=>{
  let subtotal=0;
  saleForm.querySelectorAll('.sale-line').forEach(line=>{let price=Number(line.querySelector('.product-select').selectedOptions[0]?.dataset.price||0);line.querySelectorAll('.addon-select option:checked').forEach(option=>price+=Number(option.dataset.price));subtotal+=price*Number(line.querySelector('.quantity').value||0);});
  let total=subtotal-adjustment(document.querySelector('#discount').value,subtotal)+adjustment(document.querySelector('#extra').value,subtotal);
  if(saleForm.querySelector('[name=delivery]')?.checked&&saleForm.querySelector('[name=order]')?.checked)total+=cents(document.querySelector('#delivery-fee')?.value);
  const balance=Number(saleForm.querySelector('[name=customer_id]').selectedOptions[0]?.dataset.wallet||0);
  const used=document.querySelector('#use-balance').checked?Math.min(Math.max(0,total),balance):0;
  const rows=[...saleForm.querySelectorAll('.payment-line')];const automatic=rows.length===1&&!manualPayment;
  document.querySelector('#auto-payment').value=automatic?'1':'0';let paid=used;
  rows.forEach(row=>{const input=row.querySelector('.payment-amount');const fiado=row.querySelector('select').value==='fiado';input.readOnly=fiado;if(automatic&&!fiado)input.value=(Math.max(0,total-used)/100).toFixed(2);if(!fiado)paid+=cents(input.value);});
  const remaining=Math.max(0,total-paid);rows.filter(row=>row.querySelector('select').value==='fiado').forEach((row,index)=>row.querySelector('.payment-amount').value=(index===0?remaining/100:0).toFixed(2));
  document.querySelector('#subtotal').textContent=currency(subtotal);document.querySelector('#sale-total').textContent=currency(total);document.querySelector('#sale-pending').textContent=currency(remaining);document.querySelector('#pending-row').hidden=remaining===0;
  document.querySelector('#wallet-row').hidden=balance-used<=0;document.querySelector('#wallet-remaining').textContent=currency(balance-used);
 };
 document.querySelector('#reset-payment').addEventListener('click',()=>{manualPayment=false;recalc();});
 let itemIndex=document.querySelectorAll('.sale-line').length,paymentIndex=document.querySelectorAll('.payment-line').length;
 const add=(container,selector,prefix,index)=>{const parent=document.querySelector(container);const row=parent.querySelector(selector).cloneNode(true);row.querySelectorAll('[name]').forEach(input=>{input.name=input.name.replace(new RegExp(prefix+'\\[\\d+\\]'),prefix+'['+index+']');if(input.tagName==='SELECT')input.selectedIndex=0;else input.value=prefix==='items'?'1':'0';});row.querySelectorAll('.search-input').forEach(el=>{el.value='';el.setCustomValidity('');});row.querySelectorAll('.search-results').forEach(el=>{el.replaceChildren();el.hidden=true;});row.querySelectorAll('[data-search-select]').forEach(el=>{el.hidden=true;delete el.dataset.enhanced;});parent.append(row);row.querySelectorAll('[data-search-select]').forEach(enhanceSearchSelect);if(prefix==='items')loadAddons(row);recalc();};
 document.querySelector('#add-item').addEventListener('click',()=>add('#sale-items','.sale-line','items',itemIndex++));
 document.querySelector('#add-payment').addEventListener('click',()=>add('#sale-payments','.payment-line','payments',paymentIndex++));
 saleForm.addEventListener('click',e=>{if(e.target.closest('.remove-line')){const row=e.target.closest('.sale-line,.payment-line');if(row.parentElement.children.length>1)row.remove();recalc();}});
 document.querySelectorAll('.sale-line').forEach(loadAddons);
 saleForm.addEventListener('input',e=>{if(e.target.matches('.payment-amount'))manualPayment=true;recalc();});saleForm.addEventListener('change',e=>{if(e.target.matches('.product-select'))loadAddons(e.target.closest('.sale-line'));recalc();});saleForm.addEventListener('submit',()=>{const button=saleForm.querySelector('[type=submit],button.full-width');if(button){button.disabled=true;button.textContent='Registrando...';}});recalc();
}

const queueRunner=document.querySelector('[data-mail-process]');
if(queueRunner){(async()=>{let processed=0;try{while(true){const response=await fetch(queueRunner.dataset.mailProcess,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':queueRunner.dataset.token},body:JSON.stringify({action:'process'})});if(!response.ok)throw new Error();const result=await response.json();if(!result.processed)break;processed++;queueRunner.textContent='Tentativas realizadas: '+processed;}queueRunner.textContent='Processamento concluído: '+processed+' tentativa(s). Atualize a página para consultar os resultados.';}catch{queueRunner.textContent='Processamento interrompido. Consulte os pendentes e tente novamente; a fila também é processada pelo cron.';}})();}


const librarySearch=document.querySelector('[data-template-search]');
librarySearch?.addEventListener('input',()=>{
 const normalize=text=>text.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
 const term=normalize(librarySearch.value);let visible=0;
 document.querySelectorAll('[data-template-choice]').forEach(choice=>{choice.hidden=!normalize(choice.textContent).includes(term);if(!choice.hidden)visible++;});
 document.querySelector('[data-library-empty]').hidden=visible>0;
});
const sendForm=document.querySelector('[data-template-send]');
if(sendForm){const toggle=()=>{const purpose=sendForm.elements.template_id.selectedOptions[0]?.dataset.purpose;for(const [field,kind] of [['sale','receipt'],['account','billing']]){const label=sendForm.querySelector('[data-send-'+field+']'),input=label.querySelector('input');label.hidden=purpose!==kind;input.disabled=purpose!==kind;input.required=purpose===kind;}};sendForm.elements.template_id.addEventListener('change',toggle);toggle();}
const editor=document.querySelector('[data-template-editor]');
if(editor){
 const body=editor.elements.body,subject=editor.elements.subject,html=editor.elements.html_body;
 const variables={general:['nome','empresa'],receipt:['nome','empresa','venda','total','comprovante'],billing:['nome','empresa','descricao','saldo','vencimento']};
 const labels={nome:'Nome do cliente',empresa:'Empresa',venda:'Nº da venda',total:'Total',comprovante:'Link do comprovante',descricao:'Descrição da conta',saldo:'Saldo pendente',vencimento:'Vencimento',data:'Data da compra',endereco:'Endereço',itens_html:'Lista de itens',resumo_html:'Resumo dos valores',pagamentos_html:'Pagamentos',portal_html:'Link do portal',mensagem_html:'Mensagem adicional'};
 const sample={nome:'Ana Oliveira',empresa:'Sua empresa',venda:'1042',total:'R$ 189,00',comprovante:'https://exemplo.com/comprovante',descricao:'Compra parcelada',saldo:'R$ 89,00',vencimento:'20/10/2026',data:'12/10/2026 14:30',endereco:'Rua Exemplo, 100'};
 const htmlVariables=['data','endereco','itens_html','resumo_html','pagamentos_html','portal_html','mensagem_html'];
 const texts=[...editor.querySelectorAll('[data-design-text]')],colors=[...editor.querySelectorAll('[data-design-color]')];
 let target=body,timer,dirty=false;
 const isReceipt=()=>editor.elements.channel.value==='email'&&editor.elements.purpose.value==='receipt';
 const escape=text=>String(text).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
 const markDirty=()=>{dirty=true;const state=document.querySelector('[data-save-state]');state.textContent='Alterações não salvas';state.classList.add('dirty');};
 const syncDesigner=()=>{
  if(!isReceipt())return;
  const design=window.ReceiptDesigner.read(html.value);let missing=false;
  texts.forEach(input=>{const value=design.text[input.dataset.designText];input.disabled=value===null;input.value=value??'';if(value===null)missing=true;});
  colors.forEach(input=>{const value=design.colors[input.dataset.designColor];input.disabled=value===null;input.value=value??'#ffffff';if(value===null)missing=true;});
  editor.querySelector('[data-design-warning]').hidden=!missing;
 };
 const preview=()=>{
  const render=text=>text.replace(/\{([^{}]+)\}/g,(match,key)=>sample[key]??match);
  const receipt=isReceipt(),whatsapp=editor.elements.channel.value==='whatsapp';
  const paragraph=document.querySelector('[data-template-preview]');paragraph.replaceChildren();
  if(whatsapp&&window.WhatsAppFormat)window.WhatsAppFormat.append(paragraph,render(body.value)||'Sua mensagem aparecerá aqui.');else paragraph.textContent=render(body.value)||'Sua mensagem aparecerá aqui.';
  document.querySelector('[data-preview-subject]').textContent=whatsapp?'':render(subject.value)||'Assunto do e-mail';
  document.querySelector('[data-preview-channel]').textContent=whatsapp?'WhatsApp · Ana Oliveira':'Para: Ana Oliveira';
  document.querySelector('[data-preview-time]').textContent=whatsapp?'14:30':'';
  document.querySelector('.template-preview-panel').classList.toggle('is-whatsapp',whatsapp);
  document.querySelector('[data-body-counter]').textContent=body.value.length.toLocaleString('pt-BR')+' / 7.000';
  const frame=document.querySelector('[data-html-preview]');frame.hidden=!receipt;paragraph.closest('.template-text-preview').hidden=receipt;
  if(receipt){
   const blocks={itens_html:'<table width="100%" style="font-size:14px"><tr><th align="left">Item</th><th align="right">Valor</th></tr><tr><td style="padding:14px 0">1 × Consultoria</td><td align="right">R$ 199,00</td></tr></table>',resumo_html:'<p style="font-size:13px">Subtotal: R$ 199,00<br>Desconto: R$ 10,00</p>',pagamentos_html:'<p style="font-size:14px">Pix: <strong>R$ 189,00</strong></p>',portal_html:'<p style="font-size:13px;color:#087e8b">Acompanhar minhas compras no portal</p>',mensagem_html:escape(render(body.value)).replace(/\n/g,'<br>')};
   const standard='Olá, {nome}. Sua compra #{venda} foi registrada. Total: {total}.';if(body.value===standard)blocks.mensagem_html='';
   const content=html.value.replace(/\{([a-z_]+)\}/g,(match,key)=>blocks[key]??(sample[key]!==undefined?escape(sample[key]):match));
   frame.srcdoc='<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;">'+content;
  }
 };
 const insert=(value)=>{if(target.disabled||target===html&&!isReceipt()||target===subject&&editor.elements.channel.value!=='email')target=body;target.setRangeText(value,target.selectionStart,target.selectionEnd,'end');target.focus();target.dispatchEvent(new Event('input',{bubbles:true}));};
 const renderVariables=()=>{
  const box=editor.querySelector('[data-variables]');box.replaceChildren();
  [...variables[editor.elements.purpose.value],...(isReceipt()&&target===html?htmlVariables:[])].forEach(key=>{const button=document.createElement('button');button.type='button';button.textContent='+ '+labels[key];button.title='{'+key+'}';button.addEventListener('click',()=>insert('{'+key+'}'));box.append(button);});
 };
 [body,subject,html,...texts].forEach(input=>input.addEventListener('focus',()=>{target=input;renderVariables();}));
 editor.querySelector('[data-receipt-advanced]').addEventListener('toggle',event=>{if(!event.currentTarget.open&&target===html){target=body;renderVariables();}});
 const update=()=>{
  const email=editor.elements.channel.value==='email',receipt=isReceipt();editor.querySelector('[data-subject]').hidden=!email;subject.required=email;
  editor.querySelector('[data-receipt-html]').hidden=!receipt;editor.querySelector('[data-receipt-advanced]').hidden=!receipt;html.disabled=!receipt;
  const general=editor.elements.purpose.value==='general';editor.elements.is_default.disabled=general;if(general)editor.elements.is_default.checked=false;
  editor.querySelector('[data-format-tools]').hidden=email;
  editor.querySelector('[data-body-heading]').textContent=receipt?'Mensagem adicional':'Mensagem';
  editor.querySelector('[data-body-help]').textContent=receipt?'Aparece no comprovante e na versão em texto do e-mail.':'Escreva como você fala com seus clientes.';
  target=body;syncDesigner();renderVariables();preview();
 };
 editor.querySelectorAll('[data-wrap]').forEach(button=>button.addEventListener('click',()=>{target=body;const start=body.selectionStart,end=body.selectionEnd,selected=body.value.slice(start,end)||'texto';insert(button.dataset.wrap+selected+button.dataset.wrap);body.setSelectionRange(start+button.dataset.wrap.length,start+button.dataset.wrap.length+selected.length);}));
 editor.querySelector('[data-restore-receipt]').addEventListener('click',()=>{if(confirm('Restaurar a aparência padrão do CRM? A mudança só será aplicada ao salvar.')){html.value=editor.querySelector('[data-default-receipt]').content.textContent;markDirty();syncDesigner();preview();}});
 editor.addEventListener('input',event=>{
  if(event.target.dataset.designText){html.value=window.ReceiptDesigner.apply(html.value,event.target.dataset.designText,event.target.value,'text');}
  if(event.target.dataset.designColor){html.value=window.ReceiptDesigner.apply(html.value,event.target.dataset.designColor,event.target.value,'color');}
  if(event.target===html)syncDesigner();markDirty();clearTimeout(timer);timer=setTimeout(preview,150);
 });
 editor.elements.channel.addEventListener('change',()=>{markDirty();update();});editor.elements.purpose.addEventListener('change',()=>{markDirty();update();});editor.elements.is_default.addEventListener('change',markDirty);
 editor.addEventListener('submit',()=>{dirty=false;});
 window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
 update();
}

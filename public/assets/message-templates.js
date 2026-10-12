const editor=document.querySelector('[data-template-editor]');
if(editor){
 const body=editor.elements.body,subject=editor.elements.subject,html=editor.elements.html_body;
 const variables={general:['nome','empresa'],receipt:['nome','empresa','venda','total','comprovante'],billing:['nome','empresa','descricao','saldo','vencimento']};
 const sample={nome:'Ana Oliveira',empresa:'Sua empresa',venda:'1042',total:'R$ 189,00',comprovante:'https://exemplo.com/comprovante',descricao:'Compra parcelada',saldo:'R$ 89,00',vencimento:'20/10/2026'};
 const htmlVariables=['data','endereco','itens_html','resumo_html','pagamentos_html','portal_html','mensagem_html'];
 Object.assign(sample,{data:'12/10/2026 14:30',endereco:'Rua Exemplo, 100'});
 let target=body,timer;
 [body,subject,html].forEach(input=>input.addEventListener('focus',()=>target=input));
 const isReceipt=()=>editor.elements.channel.value==='email'&&editor.elements.purpose.value==='receipt';
 const escape=text=>String(text).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
 const preview=()=>{
  const render=text=>text.replace(/\{([^{}]+)\}/g,(match,key)=>sample[key]??match);
  document.querySelector('[data-template-preview]').textContent=render(body.value)||'Sua mensagem aparecerá aqui.';
  document.querySelector('[data-preview-subject]').textContent=editor.elements.channel.value==='email'?render(subject.value):'';
  const receipt=isReceipt(),frame=document.querySelector('[data-html-preview]');frame.hidden=!receipt;document.querySelector('[data-template-preview]').hidden=receipt;
  if(receipt){
   const blocks={itens_html:'<table width="100%"><tr><th align="left">Item</th><th align="right">Valor</th></tr><tr><td>1 × Consultoria</td><td align="right">R$ 199,00</td></tr></table>',resumo_html:'<p>Subtotal: R$ 199,00<br>Desconto: R$ 10,00</p>',pagamentos_html:'<p>Pix: <strong>R$ 189,00</strong></p>',portal_html:'<p>Acompanhar minhas compras no portal</p>',mensagem_html:escape(render(body.value)).replace(/\n/g,'<br>')};
   const content=html.value.replace(/\{([a-z_]+)\}/g,(match,key)=>blocks[key]??(sample[key]!==undefined?escape(sample[key]):match));
   frame.srcdoc='<meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;">'+content;
  }
 };
 const update=()=>{
  const email=editor.elements.channel.value==='email';editor.querySelector('[data-subject]').hidden=!email;subject.required=email;
  const receipt=isReceipt();editor.querySelector('[data-receipt-html]').hidden=!receipt;html.disabled=!receipt;
  const general=editor.elements.purpose.value==='general';editor.elements.is_default.disabled=general;if(general)editor.elements.is_default.checked=false;
  const box=editor.querySelector('[data-variables]');box.replaceChildren();
  [...variables[editor.elements.purpose.value],...(receipt?htmlVariables:[])].forEach(key=>{
   const button=document.createElement('button');button.type='button';button.className='secondary';button.textContent='{'+key+'}';
   button.addEventListener('click',()=>{if(htmlVariables.includes(key))target=html;else if(target===html&&!receipt||target===subject&&!email)target=body;target.setRangeText('{'+key+'}',target.selectionStart,target.selectionEnd,'end');target.focus();preview();});box.append(button);
  });preview();
 };
 editor.querySelector('[data-restore-receipt]').addEventListener('click',()=>{if(confirm('Restaurar o HTML padrão do CRM? As alterações serão aplicadas ao salvar o modelo.')){html.value=editor.querySelector('[data-default-receipt]').content.textContent;preview();}});
 editor.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(preview,200);});editor.elements.channel.addEventListener('change',update);editor.elements.purpose.addEventListener('change',update);update();
}

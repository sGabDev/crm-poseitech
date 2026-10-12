const editor=document.querySelector('[data-template-editor]');
if(editor){
 const body=editor.elements.body,subject=editor.elements.subject;
 const variables={general:['nome','empresa'],receipt:['nome','empresa','venda','total','comprovante'],billing:['nome','empresa','descricao','saldo','vencimento']};
 const sample={nome:'Ana Oliveira',empresa:'Sua empresa',venda:'1042',total:'R$ 189,00',comprovante:'https://exemplo.com/comprovante',descricao:'Compra parcelada',saldo:'R$ 89,00',vencimento:'20/10/2026'};
 let target=body;
 [body,subject].forEach(input=>input.addEventListener('focus',()=>target=input));
 const preview=()=>{const render=text=>text.replace(/\{([^{}]+)\}/g,(match,key)=>sample[key]??match);document.querySelector('[data-template-preview]').textContent=render(body.value)||'Sua mensagem aparecerá aqui.';document.querySelector('[data-preview-subject]').textContent=editor.elements.channel.value==='email'?render(subject.value):'';};
 const update=()=>{const email=editor.elements.channel.value==='email';editor.querySelector('[data-subject]').hidden=!email;subject.required=email;const general=editor.elements.purpose.value==='general';editor.elements.is_default.disabled=general;if(general)editor.elements.is_default.checked=false;const box=editor.querySelector('[data-variables]');box.replaceChildren();variables[editor.elements.purpose.value].forEach(key=>{const button=document.createElement('button');button.type='button';button.className='secondary';button.textContent='{'+key+'}';button.addEventListener('click',()=>{if(!email)target=body;target.setRangeText('{'+key+'}',target.selectionStart,target.selectionEnd,'end');target.focus();preview();});box.append(button);});preview();};
 editor.addEventListener('input',preview);editor.elements.channel.addEventListener('change',update);editor.elements.purpose.addEventListener('change',update);update();
}

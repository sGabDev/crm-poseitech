(() => {
 const labels={idle:'Desconectado',connecting:'Conectando…',qr:'Escaneie o QR code abaixo',connected:'Conectado',reconnecting:'Reconectando…',logged_out:'Sessão encerrada. Clique em Conectar.',unavailable:'Serviço WhatsApp indisponível'};
 async function refresh(){
  if(document.hidden) return;
  await Promise.all([...document.querySelectorAll('[data-whatsapp-status]')].map(async card=>{
   const img=card.querySelector('[data-qr]');
   try {const response=await fetch(card.dataset.whatsappStatus,{headers:{Accept:'application/json'},cache:'no-store'});const data=await response.json();card.querySelector('[data-state]').textContent=(labels[data.status]||'Indisponível')+(data.phone?' · '+data.phone:'');img.hidden=!data.qr;if(data.qr && /^data:image\/png;base64,/.test(data.qr)) img.src=data.qr;else img.removeAttribute('src');}
   catch{card.querySelector('[data-state]').textContent=labels.unavailable;img.hidden=true;img.removeAttribute('src');}
  }));
 }
 refresh();setInterval(refresh,4000);
})();

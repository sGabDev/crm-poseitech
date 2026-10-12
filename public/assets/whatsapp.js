(() => {
  const cards = [...document.querySelectorAll('[data-whatsapp-status]')];
  const labels = { idle: 'Desconectado', connecting: 'Conectando…', qr: 'Aguardando leitura do QR code', connected: 'Conectado', reconnecting: 'Reconectando…', logged_out: 'Sessão encerrada', unavailable: 'Conexão indisponível' };
  const active = new Set();
  const expires = new Map();
  let timer, current;
  let running = false;
  let stopped = false;

  function schedule() {
    clearTimeout(timer);
    if (!stopped && !document.hidden && active.size) timer = setTimeout(tick, 12000);
  }

  async function check(card) {
    const image = card.querySelector('[data-qr]');
    const state = card.querySelector('[data-state]');
    const hint = card.querySelector('[data-status-hint]');
    const controller = new AbortController();
    current = controller;
    const timeout = setTimeout(() => controller.abort(), 7000);
    try {
      const response = await fetch(card.dataset.whatsappStatus, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store', signal: controller.signal,
      });
      if (response.status === 401 || response.status === 419 || response.redirected) {
        stopped = true;
        throw new Error('Sua sessão expirou. Atualize a página para entrar novamente.');
      }
      const data = await response.json();
      if (data.retry_after || response.status === 429) { stopped = true; active.clear(); }
      card.dataset.connectionState = data.status || 'unavailable';
      state.textContent = (labels[data.status] || labels.unavailable) + (data.phone ? ' · ' + data.phone : '');
      hint.textContent = data.message || (data.status === 'connected' ? 'Pronto para enviar. As consultas automáticas foram encerradas.' : 'O QR code é atualizado por até 2 minutos. Você pode verificar novamente quando precisar.');
      const validQR = typeof data.qr === 'string' && /^data:image\/png;base64,/.test(data.qr);
      image.hidden = !validQR;
      if (validQR) image.src = data.qr; else image.removeAttribute('src');
      card.querySelector('[data-qr-placeholder]').hidden = validQR;
      if (response.ok && ['connecting', 'qr', 'reconnecting'].includes(data.status) && Date.now() < expires.get(card)) active.add(card);
      else active.delete(card);
    } catch (error) {
      active.delete(card);
      card.dataset.connectionState = 'unavailable';
      state.textContent = labels.unavailable;
      hint.textContent = error.name === 'AbortError' ? 'Consulta pausada. Clique em Verificar conexão para tentar novamente.' : (error.name === 'Error' ? error.message : 'Não foi possível consultar a conexão. Tente novamente mais tarde.');
      image.hidden = true; image.removeAttribute('src');
      card.querySelector('[data-qr-placeholder]').hidden = false;
    } finally { clearTimeout(timeout); current = null; }
  }

  async function tick() {
    if (running || stopped || document.hidden) return;
    running = true;
    try {
      for (const card of [...active]) {
        if (document.hidden || stopped) break;
        if (Date.now() >= expires.get(card)) {
          active.delete(card);
          card.querySelector('[data-qr]').hidden = true;
          card.querySelector('[data-qr]').removeAttribute('src');
          card.querySelector('[data-qr-placeholder]').hidden = false;
          card.querySelector('[data-status-hint]').textContent = 'Atualização pausada. Clique em Verificar conexão ou gere um novo QR code.';
          continue;
        }
        await check(card);
      }
    } finally { running = false; schedule(); }
  }

  for (const card of cards) {
    expires.set(card, Date.now() + 120000); active.add(card);
    card.querySelector('[data-refresh-status]').addEventListener('click', () => {
      if (running) return;
      stopped = false; expires.set(card, Date.now() + 120000); active.add(card); tick();
    });
  }
  document.addEventListener('visibilitychange', () => {
    clearTimeout(timer);
    if (document.hidden) current?.abort(); else tick();
  });
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); current?.abort(); });
  tick();
})();

(() => {
  'use strict';
  const root = document.getElementById('inbox');
  if (!root) return;
  const $ = id => document.getElementById(id);
  const base = root.dataset.base;
  const writable = root.dataset.write === '1';
  let selected = Number(new URL(location.href).searchParams.get('chat')) || null;
  let state = { conversations: [], replies: [], staff: [], selected: null };
  let messages = [], filter = 'all', busy = false, queued = false, stopped = false, timer;
  let sending = false, detailsDirty = false, searching = false, hasOlder = false, readBusy = false;
  let renderedSignature = '';
  const drafts = new Map();
  const statuses = { open: 'Em aberto', pending: 'Aguardando cliente', resolved: 'Resolvido' };
  const delivery = { pending: 'Na fila', sending: 'Enviando', sent: '✓', delivered: '✓✓', read: '✓✓', failed: 'Falhou', unknown: 'Sem confirmação', received: '', internal: 'Equipe' };
  const el = (tag, cls, text) => { const node = document.createElement(tag); if (cls) node.className = cls; if (text != null) node.textContent = text; return node; };
  const initials = name => String(name || '?').trim().split(/\s+/).slice(0, 2).map(s => s[0]).join('').toUpperCase();
  const parseTags = tags => { try { return Array.isArray(tags) ? tags : JSON.parse(tags || '[]'); } catch { return []; } };
  const date = value => { const d = new Date(String(value).replace(' ', 'T') + (String(value).includes('T') ? '' : 'Z')); return Number.isNaN(d.getTime()) ? new Date() : d; };
  const time = value => date(value).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  const day = value => date(value).toLocaleDateString('pt-BR', { day: '2-digit', month: 'long' });
  function alert(message) { $('inbox-alert').textContent = message || ''; $('inbox-alert').hidden = !message; }
  async function api(path, data = {}, method = 'POST') {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 35000);
    try {
      const response = await fetch(base + path, { method, credentials: 'same-origin', signal: controller.signal,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.token, ...(data instanceof FormData || method === 'GET' ? {} : { 'Content-Type': 'application/json' }) },
        ...(method === 'GET' ? {} : { body: data instanceof FormData ? data : JSON.stringify(data) }) });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || (response.status === 419 ? 'Sua sessão expirou. Atualize a página.' : 'Não foi possível concluir. Tente novamente.'));
      return result;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('O servidor demorou a responder. Confira o histórico antes de tentar enviar novamente.');
      throw error;
    } finally { clearTimeout(timeout); }
  }
  function schedule(delay = 30000) { clearTimeout(timer); if (!stopped && !document.hidden) timer = setTimeout(() => refresh(true), delay); }
  async function refresh(sync = false) {
    if (busy) { queued = true; return; }
    if (document.hidden || stopped) return;
    busy = true;
    const requested = selected;
    try {
      const result = await api('/state', { selected, q: $('inbox-search').value, filter, tag: $('inbox-tag-filter').value, sync: sync && root.dataset.bot === '1' });
      if (requested !== selected) { queued = true; return; }
      state = result;
      renderList();
      if (sync) {
        const connection = result.connection || {};
        const connected = connection.status === 'connected';
        $('inbox-connection').textContent = '● ' + (connected ? 'WhatsApp conectado' : ({ unavailable: 'Serviço indisponível', syncing: 'Sincronizando', qr: 'Aguardando QR code', connecting: 'Conectando', reconnecting: 'Reconectando' }[connection.status] || 'WhatsApp desconectado'));
        $('inbox-connection').classList.toggle('connected', connected);
        if (connection.message) alert(connection.message); else if (!sending) alert('');
        $('inbox-sync-time').textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
      }
      if (state.selected) {
        if (!searching) {
          const old = new Map(messages.map(m => [m.id, m]));
          state.messages.forEach(m => old.set(m.id, m));
          messages = [...old.values()].sort((a, b) => date(a.message_at) - date(b.message_at) || a.id - b.id);
          hasOlder = result.hasOlder;
          renderMessages();
        }
        renderContact();
        markRead();
      }
      schedule(result.connection?.more ? 3000 : 30000);
    } catch (error) { alert(error.message); schedule(45000); }
    finally { busy = false; if (queued) { queued = false; refresh(false); } }
  }
  function renderList() {
    $('inbox-total').textContent = state.conversations.length === 100 ? '100+' : state.conversations.length;
    const list = $('inbox-conversations'); list.replaceChildren();
    if (!state.conversations.length) {
      list.append(el('div', 'inbox-list-empty', root.dataset.bot === '1' ? 'Nenhuma conversa neste filtro. As mensagens recebidas aparecem aqui quando o bot estiver conectado. Você também pode iniciar uma nova conversa.' : 'Conecte o WhatsApp da empresa para começar o atendimento.'));
      return;
    }
    state.conversations.forEach(chat => {
      const button = el('button', 'inbox-conversation' + (chat.id === selected ? ' active' : ''));
      button.type = 'button'; button.setAttribute('aria-label', 'Abrir conversa com ' + (chat.name || chat.phone));
      const avatar = el('span', 'inbox-avatar tone-' + (chat.id % 4), initials(chat.name || chat.phone));
      const content = el('div', 'inbox-conversation-content');
      const top = el('div', 'inbox-conversation-top'); top.append(el('strong', '', chat.name || chat.phone || 'Contato'), el('time', '', chat.last_message_at ? time(chat.last_message_at) : ''));
      const bottom = el('div', 'inbox-conversation-bottom');
      parseTags(chat.tags).slice(0, 2).forEach(tag => bottom.append(el('span', 'inbox-tag', tag)));
      if (!parseTags(chat.tags).length) bottom.append(el('span', 'inbox-tag', statuses[chat.status]));
      if (chat.unread) bottom.append(el('span', 'inbox-unread', chat.unread > 99 ? '99+' : chat.unread));
      content.append(top, el('p', '', chat.last_body || 'Conversa iniciada'), bottom); button.append(avatar, content);
      button.addEventListener('click', () => openChat(chat.id)); list.append(button);
    });
  }
  function storeDraft() {
    if (selected) drafts.set(selected, { body: $('inbox-body').value, note: $('inbox-note').checked, key: $('inbox-composer').dataset.key || uuid() });
  }
  function uuid() {
    if (globalThis.crypto?.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join(''); return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }
  function openChat(id) {
    if (sending) { alert('Aguarde a conclusão do envio antes de trocar de conversa.'); return; }
    if (selected !== id) {
      storeDraft(); selected = id; messages = []; searching = false; detailsDirty = false;
      $('inbox-history-search').hidden = true; $('inbox-history-query').value = '';
      const draft = drafts.get(id) || { body: '', note: false, key: uuid() };
      $('inbox-body').value = draft.body; $('inbox-note').checked = draft.note; $('inbox-composer').dataset.key = draft.key;
      $('inbox-file').value = ''; updateFile(); noteMode();
      $('inbox-messages').replaceChildren(el('div', 'inbox-day', 'Carregando histórico…'));
      const url = new URL(location.href); url.searchParams.set('chat', id); history.replaceState({}, '', url);
    }
    root.classList.add('mobile-chat'); $('inbox-welcome').hidden = true; $('inbox-active-chat').hidden = false;
    if (window.matchMedia('(min-width: 1251px)').matches) details(true);
    refresh(false);
  }
  function renderContact() {
    const chat = state.selected;
    if (!chat) return;
    $('inbox-chat-avatar').textContent = initials(chat.name);
    $('inbox-chat-name').textContent = chat.name || chat.phone || 'Contato';
    const staff = state.staff.find(user => user.id === chat.assigned_to);
    $('inbox-chat-subtitle').textContent = [chat.phone, statuses[chat.status], staff?.name].filter(Boolean).join(' · ');
    $('inbox-detail-avatar').textContent = initials(chat.name); $('inbox-detail-name').textContent = chat.name; $('inbox-detail-phone').textContent = chat.phone || 'Identificador privado do WhatsApp'; $('inbox-detail-status').textContent = statuses[chat.status];
    $('inbox-detail-tags').replaceChildren(...parseTags(chat.tags).map(tag => el('span', 'inbox-tag', tag)));
    if (!detailsDirty) {
      const form = $('inbox-contact-form'); form.elements.name.value = chat.name || ''; form.elements.status.value = chat.status;
      form.elements.tags.value = parseTags(chat.tags).join(', ');
      form.elements.assigned_to.replaceChildren(new Option('Sem responsável', ''), ...state.staff.map(user => new Option(user.name, user.id)));
      form.elements.assigned_to.value = chat.assigned_to || '';
    }
    const customer = $('inbox-customer'); customer.replaceChildren();
    if (state.customer) {
      customer.append(el('p', '', state.customer.name + (state.customer.email ? '\n' + state.customer.email : '')));
      const link = el('a', '', 'Abrir ficha, compras e cadastro ↗'); link.href = base.replace(/\/whatsapp\/atendimento$/, '') + '/customers/' + state.customer.id; customer.append(link);
    } else {
      customer.append(el('p', '', 'Este contato ainda não está vinculado a um cliente do CRM.'));
      if (writable) {
        const create = el('button', '', 'Cadastrar como cliente'); create.type = 'button'; create.disabled = !chat.phone;
        create.onclick = () => action(`/conversations/${selected}/customer`, {}, () => refresh(false)); customer.append(create);
        const link = el('form'); const input = el('input'); input.placeholder = 'Código de cliente existente'; input.type = 'number'; input.min = '1'; input.required = true; input.setAttribute('aria-label', 'Código do cliente');
        const submit = el('button', '', 'Vincular cliente'); submit.type = 'submit'; link.append(input, submit); link.style.marginTop = '12px';
        link.onsubmit = event => { event.preventDefault(); action(`/conversations/${selected}/update`, { customer_id: Number(input.value) }, () => refresh(false)); }; customer.append(link);
      }
    }
  }
  function renderMessages(forceBottom = false) {
    const log = $('inbox-messages');
    const signature = JSON.stringify([selected, searching, hasOlder, messages.map(m => [m.id, m.status, m.body, m.error])]);
    if (signature === renderedSignature && !forceBottom) return;
    renderedSignature = signature;
    const atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 100;
    const oldHeight = log.scrollHeight, oldTop = log.scrollTop;
    log.replaceChildren();
    if (hasOlder && !searching) { const older = el('button', 'inbox-older', 'Carregar mensagens anteriores'); older.type = 'button'; older.onclick = loadOlder; log.append(older); }
    if (!messages.length) log.append(el('span', 'inbox-day', searching ? 'Nenhuma mensagem encontrada.' : 'Inicie a conversa. As próximas mensagens ficam salvas aqui.'));
    let previousDay;
    for (const message of messages) {
      const label = day(message.message_at);
      if (label !== previousDay) { log.append(el('div', 'inbox-day', label)); previousDay = label; }
      const bubble = el('article', 'inbox-message ' + message.direction);
      if (message.direction === 'note') bubble.append(el('small', '', 'NOTA INTERNA · ' + (state.staff.find(u => u.id === message.user_id)?.name || 'Equipe')));
      if (message.body) { const body = el('div', 'inbox-message-body'); if (window.WhatsAppFormat) window.WhatsAppFormat.append(body, message.body); else body.textContent = message.body; bubble.append(body); }
      if (message.kind !== 'text') {
        const link = el('a', '', ({ image: '▧ Imagem', video: '▷ Vídeo', audio: '♫ Áudio', sticker: '▧ Figurinha', document: '▤ Arquivo' }[message.kind] || 'Arquivo') + (message.filename ? ' · ' + message.filename : '') + ' ↓');
        link.href = base + '/media/' + message.id; link.target = '_blank'; link.rel = 'noopener'; bubble.append(link);
        if (['image', 'sticker', 'audio', 'video'].includes(message.kind)) {
          const preview = el('button', '', message.kind === 'audio' ? 'Ouvir áudio' : message.kind === 'video' ? 'Reproduzir vídeo' : 'Ver imagem'); preview.type = 'button';
          preview.onclick = () => { const node = el(message.kind === 'audio' ? 'audio' : message.kind === 'video' ? 'video' : 'img', 'inbox-media-preview'); node.src = base + '/media/' + message.id + '?inline=1';
            if (node.tagName === 'IMG') node.alt = 'Imagem da conversa'; else { node.controls = true; node.preload = 'metadata'; }
            bubble.insertBefore(node, link); preview.remove(); }; bubble.append(preview);
        }
      }
      if (message.error) bubble.append(el('div', 'inbox-message-error', message.error));
      if (message.status === 'failed' && writable) { const retry = el('button', '', 'Tentar novamente'); retry.type = 'button'; retry.onclick = () => retryMessage(message); bubble.append(retry); }
      const footer = el('footer'); const status = el('span', 'inbox-receipt ' + (message.status === 'read' ? 'read' : ''), delivery[message.status] ?? message.status); status.title = message.direction === 'in' && message.status === 'read' ? 'Leitura enviada ao WhatsApp' : ({ sent: 'Enviada', delivered: 'Entregue', read: 'Lida pelo cliente', pending: 'Aguardando envio', sending: 'Enviando', received: 'Recebida' })[message.status] || message.status;
      status.setAttribute('aria-label', status.title);
      footer.append(el('time', '', time(message.message_at)), status); bubble.append(footer); log.append(bubble);
    }
    if (atBottom || forceBottom || oldHeight === 0) log.scrollTop = log.scrollHeight;
    else log.scrollTop = oldTop;
  }
  async function loadOlder() {
    const chatId = selected;
    const log = $('inbox-messages'); const top = log.scrollTop, height = log.scrollHeight;
    try {
      const result = await api(`/conversations/${chatId}/history?before=${messages[0]?.id || 1}`, {}, 'GET');
      if (chatId !== selected) return;
      messages = [...result.messages, ...messages]; hasOlder = result.messages.length === 60; renderMessages(); log.scrollTop = top + log.scrollHeight - height;
    } catch (error) { alert(error.message); }
  }
  async function markRead() {
    if (!writable || !state.selected?.unread || readBusy || document.hidden) return;
    readBusy = true;
    const chatId = state.selected.id;
    try { const result = await api(`/conversations/${chatId}/read`); if (state.selected?.id === chatId) { state.selected.unread = 0; const ids = new Set(result.ids || []); messages.forEach(m => { if (m.direction === 'in' && ids.has(m.remote_id)) m.status = 'read'; }); renderMessages(); } const chat = state.conversations.find(c => c.id === chatId); if (chat) chat.unread = 0; renderList(); }
    catch { /* Local unread status will be retried on the next refresh. */ }
    finally { readBusy = false; }
  }
  async function action(path, data, done) {
    try { await api(path, data); alert(''); if (done) done(); }
    catch (error) { alert(error.message); }
  }
  function updateFile() { const file = $('inbox-file').files[0]; $('inbox-file-name').textContent = file ? `${file.name} · ${(file.size / 1048576).toFixed(1)} MB · clique em + para trocar` : 'Enter envia · Shift + Enter quebra a linha'; }
  function noteMode() { const note = $('inbox-note').checked; document.querySelector('.inbox-composer-wrap').classList.toggle('is-note', note); $('inbox-body').placeholder = note ? 'Nota visível apenas para a equipe…' : 'Escreva sua mensagem…'; $('inbox-file').disabled = note || !writable; if (note) { $('inbox-file').value = ''; updateFile(); } }
  async function send(formData, chatId, preserveDraft = false) {
    if (sending) return;
    sending = true; $('inbox-send').disabled = true; $('inbox-body').disabled = true; $('inbox-note').disabled = true; $('inbox-file').disabled = true;
    try {
      const result = await api(`/conversations/${chatId}/send`, formData);
      const message = result.message;
      const index = messages.findIndex(m => m.id === message.id); if (index >= 0) messages[index] = message; else messages.push(message);
      if (!preserveDraft) { $('inbox-body').value = ''; $('inbox-file').value = ''; updateFile(); $('inbox-composer').dataset.key = uuid(); drafts.delete(chatId); }
      renderMessages(true); if (message.status === 'unknown') alert(message.error || 'Envio sem confirmação. Confira no celular antes de enviar novamente.'); else if (message.status === 'failed') alert(message.error); else alert('');
      refresh(false);
    } catch (error) { alert(error.message); storeDraft(); }
    finally { sending = false; $('inbox-send').disabled = !writable; $('inbox-body').disabled = !writable; $('inbox-note').disabled = !writable; noteMode(); $('inbox-body').focus(); }
  }
  function retryMessage(message) {
    if (!selected) return;
    const form = new FormData(); form.set('body', message.body || ''); form.set('request_key', message.request_key); form.set('note', '0'); send(form, selected, true);
  }
  $('inbox-composer').onsubmit = event => {
    event.preventDefault(); if (!selected || !writable) return;
    const body = $('inbox-body').value; const file = $('inbox-file').files[0];
    if (!body.trim() && !file) return;
    if (file && file.size > 8 * 1048576) { alert('O arquivo precisa ter até 8 MB.'); return; }
    const data = new FormData(); data.set('body', body); data.set('request_key', $('inbox-composer').dataset.key || uuid()); data.set('note', $('inbox-note').checked ? '1' : '0'); if (file) data.set('file', file);
    $('inbox-composer').dataset.key = data.get('request_key'); send(data, selected);
  };
  $('inbox-body').onkeydown = event => { if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); $('inbox-composer').requestSubmit(); } };
  $('inbox-body').oninput = () => { $('inbox-composer').dataset.key = uuid(); storeDraft(); $('inbox-body').style.height = 'auto'; $('inbox-body').style.height = Math.min(120, $('inbox-body').scrollHeight) + 'px'; };
  $('inbox-file').onchange = () => { $('inbox-composer').dataset.key = uuid(); updateFile(); };
  $('inbox-note').onchange = () => { $('inbox-composer').dataset.key = uuid(); noteMode(); storeDraft(); };
  $('inbox-contact-form').oninput = () => { detailsDirty = true; };
  $('inbox-contact-form').onsubmit = event => { event.preventDefault(); const form = event.currentTarget.elements;
    action(`/conversations/${selected}/update`, { name: form.name.value, status: form.status.value, assigned_to: form.assigned_to.value ? Number(form.assigned_to.value) : null, tags: form.tags.value.split(',').map(s => s.trim()).filter(Boolean) }, () => { detailsDirty = false; refresh(false); }); };
  function details(open) { $('inbox-details').hidden = !open; root.classList.toggle('has-details', open); }
  $('inbox-details-toggle').onclick = () => details($('inbox-details').hidden); $('inbox-details-close').onclick = () => details(false);
  $('inbox-back').onclick = () => { root.classList.remove('mobile-chat'); details(false); };
  $('inbox-new').onclick = () => $('inbox-new-dialog').showModal();
  document.querySelectorAll('[data-close-dialog]').forEach(button => { button.onclick = () => button.closest('dialog').close(); });
  $('inbox-new-form').onsubmit = async event => { event.preventDefault(); const form = event.currentTarget;
    try { const result = await api('/conversations', { name: form.elements.name.value, phone: form.elements.phone.value }); $('inbox-new-dialog').close(); form.reset(); alert(''); openChat(result.id); } catch (error) { alert(error.message); } };
  function renderReplies() {
    const list = $('inbox-replies-list'); list.replaceChildren();
    const q = $('inbox-replies-search').value.trim().toLocaleLowerCase('pt-BR');
    const replies = state.replies.filter(reply => (reply.title + ' ' + reply.body).toLocaleLowerCase('pt-BR').includes(q));
    $('inbox-replies-count').textContent = state.replies.length;
    if (!replies.length) list.append(el('div', 'inbox-replies-empty', q ? 'Nenhum modelo encontrado. Tente outra palavra.' : 'Suas melhores respostas, sempre à mão. Crie seu primeiro modelo abaixo.'));
    replies.forEach(reply => { const row = el('article', 'inbox-reply-card'); const header = el('div', 'inbox-reply-card-header'); header.append(el('span', 'inbox-reply-symbol', 'ϟ'), el('strong', '', reply.title));
      const preview = el('div', 'inbox-reply-preview'); if (window.WhatsAppFormat) window.WhatsAppFormat.append(preview, reply.body); else preview.textContent = reply.body;
      const actions = el('div', 'inbox-reply-actions'); const use = el('button', '', 'Inserir mensagem ↗'); use.type = 'button'; use.onclick = () => { $('inbox-body').value = reply.body; $('inbox-composer').dataset.key = uuid(); storeDraft(); $('inbox-replies-dialog').close(); $('inbox-body').focus(); };
      const remove = el('button', 'inbox-reply-delete', 'Excluir'); remove.type = 'button'; remove.setAttribute('aria-label', 'Excluir resposta ' + reply.title); remove.onclick = () => action('/replies/' + reply.id + '/delete', {}, () => { state.replies = state.replies.filter(r => r.id !== reply.id); renderReplies(); }); actions.append(remove, use); row.append(header, preview, actions); list.append(row); });
  }
  $('inbox-replies-open').onclick = () => { if (!selected) return; $('inbox-replies-search').value = ''; renderReplies(); $('inbox-replies-dialog').showModal(); $('inbox-replies-search').focus(); };
  $('inbox-replies-search').oninput = renderReplies;
  document.querySelectorAll('[data-format]').forEach(button => { button.disabled = !writable; button.onclick = () => {
    if (!writable || sending) return;
    const input = $('inbox-body'), start = input.selectionStart, end = input.selectionEnd;
    const text = input.value.slice(start, end) || 'texto', type = button.dataset.format;
    const marker = { bold: '*', italic: '_', strike: '~', code: '`' }[type];
    const replacement = marker ? marker + text + marker : text.split('\n').map((line, i) => (type === 'bullet' ? '- ' : type === 'number' ? `${i + 1}. ` : '> ') + line).join('\n');
    const prefix = !marker && start > 0 && input.value[start - 1] !== '\n' ? '\n' : '';
    if (input.value.length - (end - start) + prefix.length + replacement.length > 7800) return;
    input.setRangeText(prefix + replacement, start, end, 'select'); input.oninput(); input.focus();
  }; });
  $('inbox-reply-form').onsubmit = async event => { event.preventDefault(); const form = event.currentTarget; const data = { title: form.elements.title.value, body: form.elements.body.value };
    try { const result = await api('/replies', data); state.replies.push({ id: result.id, ...data }); form.reset(); renderReplies(); } catch (error) { alert(error.message); } };
  $('inbox-chat-search-toggle').onclick = () => { $('inbox-history-search').hidden = !$('inbox-history-search').hidden; if (!$('inbox-history-search').hidden) $('inbox-history-query').focus(); };
  $('inbox-history-search').onsubmit = async event => { event.preventDefault(); const chatId = selected;
    try { const result = await api(`/conversations/${chatId}/history?q=${encodeURIComponent($('inbox-history-query').value)}`, {}, 'GET'); if (chatId === selected) { searching = true; messages = result.messages; renderMessages(true); } } catch (error) { alert(error.message); } };
  $('inbox-history-clear').onclick = () => { searching = false; messages = []; $('inbox-history-query').value = ''; refresh(false); };
  let debounce;
  const search = () => { clearTimeout(debounce); debounce = setTimeout(() => refresh(false), 450); };
  $('inbox-search').oninput = search; $('inbox-tag-filter').oninput = search;
  $('inbox-status-filter').onchange = () => { filter = $('inbox-status-filter').value; document.querySelectorAll('[data-filter]').forEach(button => button.classList.remove('active')); refresh(false); };
  document.querySelectorAll('[data-filter]').forEach(button => { button.onclick = () => { filter = button.dataset.filter; $('inbox-status-filter').value = 'all'; document.querySelectorAll('[data-filter]').forEach(b => b.classList.toggle('active', b === button)); refresh(false); }; });
  $('inbox-refresh').onclick = () => refresh(true);
  document.addEventListener('visibilitychange', () => { clearTimeout(timer); if (!document.hidden) refresh(true); });
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
  window.addEventListener('pageshow', event => { if (event.persisted) { stopped = false; refresh(true); } });
  if (selected) { $('inbox-welcome').hidden = true; $('inbox-active-chat').hidden = false; root.classList.add('mobile-chat'); }
  if (selected && window.matchMedia('(min-width: 1251px)').matches) details(true);
  refresh(true);
})();

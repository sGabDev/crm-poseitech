const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const { webcrypto } = require('node:crypto');

function harness({ failSend = false } = {}) {
  class Element {
    constructor(tag = 'div') { this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.style = {}; this.hidden = false; this.value = ''; this.files = []; this.checked = false; this.scrollHeight = 500; this.scrollTop = 500; this.clientHeight = 300;
      const names = new Set(); this.classList = { add: name => names.add(name), remove: name => names.delete(name), contains: name => names.has(name), toggle: (name, value) => { if (value ?? !names.has(name)) names.add(name); else names.delete(name); } };
    }
    set innerHTML(_) { throw new Error('Untrusted HTML must never be inserted'); }
    setAttribute(name, value) { this[name] = value; }
    append(...nodes) { this.children.push(...nodes); }
    replaceChildren(...nodes) { this.children = nodes; }
    insertBefore(node) { this.children.unshift(node); }
    remove() {}
    focus() {}
    addEventListener(name, fn) { this['on' + name] = fn; }
    showModal() { this.open = true; }
    close() { this.open = false; }
    reset() {}
    requestSubmit() { return this.onsubmit?.({ preventDefault() {}, currentTarget: this }); }
  }
  const elements = new Map(); const get = id => { if (!elements.has(id)) elements.set(id, new Element()); return elements.get(id); };
  get('inbox').dataset = { base: '/whatsapp/atendimento', token: 'csrf', write: '1', user: '1', bot: '1' };
  for (const id of ['inbox-contact-form', 'inbox-new-form', 'inbox-reply-form']) get(id).elements = Object.fromEntries(['name', 'status', 'assigned_to', 'tags', 'phone', 'title', 'body'].map(name => [name, new Element()]));
  const filters = ['all', 'mine', 'unread'].map(filter => { const node = new Element(); node.dataset.filter = filter; return node; });
  const listeners = {}, timers = new Map(), requests = []; let sequence = 0, sendAttempts = 0;
  const chat = { id: 1, name: '<img src=x onerror=alert(1)>', phone: '5511999999999', status: 'open', unread: 0, tags: '["VIP"]', last_body: 'Olá', last_message_at: '2026-10-11 15:00:00' };
  const message = { id: 1, direction: 'in', body: '<script>alert(1)</script>', kind: 'text', status: 'received', message_at: '2026-10-11 15:00:00' };
  const document = { hidden: false, getElementById: get, createElement: tag => new Element(tag), querySelectorAll: selector => selector === '[data-filter]' ? filters : [], querySelector: () => get('composer-wrap'), addEventListener: (name, fn) => { listeners[name] = fn; } };
  vm.runInNewContext(fs.readFileSync('public/assets/whatsapp-inbox.js', 'utf8'), {
    document, window: { matchMedia: () => ({ matches: true }), addEventListener: (name, fn) => { listeners[name] = fn; } },
    globalThis: { crypto: webcrypto }, crypto: webcrypto, URL, location: { href: 'https://crm.test/whatsapp/atendimento?chat=1' }, history: { replaceState() {} },
    Option: function(text, value) { const node = new Element('option'); node.textContent = text; node.value = value; return node; },
    Date, AbortController, FormData,
    setTimeout: (fn, delay) => { const id = ++sequence; timers.set(id, { fn, delay }); return id; }, clearTimeout: id => timers.delete(id),
    fetch: async (url, options) => {
      requests.push({ url, options });
      if (url.endsWith('/send')) {
        sendAttempts++;
        if (failSend && sendAttempts === 1) throw new Error('Network lost');
        return { ok: true, json: async () => ({ message: { ...message, id: 2, direction: options.body.get('note') === '1' ? 'note' : 'out', body: options.body.get('body'), status: 'sent' } }) };
      }
      return { ok: true, json: async () => ({ connection: { status: 'connected' }, conversations: [chat], selected: chat, messages: [message], customer: null, staff: [], replies: [], hasOlder: false }) };
    },
  });
  return { get, document, listeners, timers, requests, text(node) { return [node.textContent || '', ...node.children.map(child => this.text(child))].join(' '); } };
}
const tick = async () => { for (let i = 0; i < 5; i++) await new Promise(setImmediate); };

test('attendance renders untrusted names and messages as text and polls only while visible', async () => {
  const app = harness(); await tick();
  assert.match(app.text(app.get('inbox-messages')), /<script>alert\(1\)<\/script>/);
  assert.match(app.text(app.get('inbox-conversations')), /<img src=x onerror=alert\(1\)>/);
  assert.equal(app.requests.length, 1);
  assert.ok([...app.timers.values()].some(timer => timer.delay === 30000));
  app.document.hidden = true; app.listeners.visibilitychange();
  assert.equal(app.timers.size, 0);
  app.document.hidden = false; app.listeners.visibilitychange(); await tick(); assert.equal(app.requests.length, 2);
  app.listeners.pagehide(); assert.equal(app.timers.size, 0);
});
test('a network failure preserves the draft and reuses its request key on retry', async () => {
  const app = harness({ failSend: true }); await tick();
  app.get('inbox-body').value = 'Minha resposta'; app.get('inbox-body').oninput();
  const form = app.get('inbox-composer'); await form.requestSubmit(); await tick();
  assert.equal(app.get('inbox-body').value, 'Minha resposta');
  assert.equal(app.get('inbox-send').disabled, false);
  await form.requestSubmit(); await tick();
  const sent = app.requests.filter(request => request.url.endsWith('/send'));
  assert.equal(sent.length, 2);
  assert.equal(sent[0].options.body.get('request_key'), sent[1].options.body.get('request_key'));
  assert.equal(app.get('inbox-body').value, '');
});
test('internal-note mode sends a note flag and prevents file attachments', async () => {
  const app = harness(); await tick();
  app.get('inbox-note').checked = true; app.get('inbox-note').onchange();
  assert.equal(app.get('inbox-file').disabled, true);
  app.get('inbox-body').value = 'Contexto para a equipe'; app.get('inbox-body').oninput();
  await app.get('inbox-composer').requestSubmit(); await tick();
  const sent = app.requests.find(request => request.url.endsWith('/send'));
  assert.equal(sent.options.body.get('note'), '1');
  assert.equal(sent.options.body.get('body'), 'Contexto para a equipe');
});

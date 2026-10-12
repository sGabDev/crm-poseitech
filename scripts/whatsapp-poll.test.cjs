const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function harness(status) {
  const timers = new Map();
  const elements = new Map();
  const listeners = {};
  let now = 0, calls = 0, sequence = 0;
  const element = selector => {
    if (!elements.has(selector)) elements.set(selector, { hidden: false, textContent: '', addEventListener: (event, fn) => { listeners[selector + event] = fn; }, removeAttribute: () => {} });
    return elements.get(selector);
  };
  const card = { dataset: { whatsappStatus: '/status' }, querySelector: element };
  const document = { hidden: false, querySelectorAll: () => [card], addEventListener: (name, fn) => { listeners[name] = fn; } };
  vm.runInNewContext(fs.readFileSync('public/assets/whatsapp.js', 'utf8'), {
    document, window: { addEventListener: () => {} }, AbortController,
    Date: { now: () => now },
    setTimeout: (fn, delay) => { const id = ++sequence; timers.set(id, { fn, delay }); return id; },
    clearTimeout: id => timers.delete(id),
    fetch: async () => { calls++; return { ok: true, status: 200, json: async () => ({ status, qr: null }) }; },
  });
  return {
    timers, elements, document, listeners,
    calls: () => calls,
    advance: value => { now += value; },
    next: async () => { const entry = [...timers].find(([, value]) => value.delay === 12000); if (entry) { timers.delete(entry[0]); entry[1].fn(); } await new Promise(setImmediate); },
  };
}

test('connected bot stops automatic requests', async () => {
  const h = harness('connected'); await new Promise(setImmediate);
  assert.equal(h.calls(), 1); assert.equal(h.timers.size, 0);
});
test('QR polling stops after two minutes and allows explicit refresh', async () => {
  const h = harness('qr'); await new Promise(setImmediate);
  h.advance(120001); await h.next();
  assert.equal(h.calls(), 1); assert.equal(h.timers.size, 0);
  assert.match(h.elements.get('[data-status-hint]').textContent, /pausada/);
  h.listeners['[data-refresh-status]click'](); await new Promise(setImmediate);
  assert.equal(h.calls(), 2);
});

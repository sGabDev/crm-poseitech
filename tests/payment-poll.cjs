const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function harness() {
    const timers = new Map(), listeners = {}, elements = new Map();
    const root = { dataset: { company: 'one', status: 'pending', paymentStatus: '/catalog/one/orders/1/status' } };
    const document = { hidden: false, querySelector: () => root, getElementById: id => {
        if (!elements.has(id)) elements.set(id, { textContent: '', hidden: false, addEventListener() {} });
        return elements.get(id);
    } };
    let sequence = 0, calls = 0, response = { ok: true, json: async () => ({ status: 'pending' }) };
    vm.runInNewContext(fs.readFileSync('public/assets/catalog-payment.js', 'utf8'), {
        document, localStorage: { removeItem() {} }, AbortController, Date,
        window: { addEventListener: (name, fn) => { listeners[name] = fn; } },
        setTimeout(fn, delay) { const id = ++sequence; timers.set(id, { fn, delay }); return id; },
        clearTimeout: id => timers.delete(id),
        fetch: async () => { calls++; return response; },
    });
    return { document, listeners, elements, timers, calls: () => calls, respond: value => { response = value; }, async next() {
        assert.equal(timers.size, 1);
        const [id, timer] = timers.entries().next().value;
        timers.delete(id); await timer.fn();
    } };
}

test('unchanged Pix slows to one request per minute and confirmation stops polling', async () => {
    const app = harness();
    for (const expected of [30000, 45000, 60000, 60000]) {
        await app.next(); assert.equal([...app.timers.values()][0].delay, expected);
    }
    app.document.hidden = true; await app.next(); assert.equal(app.calls(), 4);
    app.document.hidden = false;
    app.respond({ ok: true, json: async () => ({ status: 'approved' }) });
    await app.next(); assert.equal(app.timers.size, 0);
    assert.match(app.elements.get('pix-status').textContent, /confirmado/);
});

test('Pix respects Retry-After and stops on lost access or page exit', async () => {
    const app = harness();
    app.respond({ ok: false, status: 503, headers: { get: () => '300' } });
    await app.next(); assert.equal([...app.timers.values()][0].delay, 300000);
    app.respond({ ok: false, status: 404 });
    await app.next(); assert.equal(app.timers.size, 0);
    const leaving = harness(); leaving.listeners.pagehide(); assert.equal(leaving.timers.size, 0);
});

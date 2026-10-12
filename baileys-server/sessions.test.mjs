import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { SessionManager } from './sessions.mjs';

function harness() {
  const records = new Map();
  const sockets = [];
  let credentialId = 0;
  const manager = new SessionManager({
    registry: {},
    store: {
      read: async (key, fallback) => structuredClone(records.get(key) ?? fallback),
      write: async (key, value) => { records.set(key, structuredClone(value)); },
    },
    freshCredentials: () => ({ identity: ++credentialId, registered: false }),
    appStateKey: value => value,
    disconnectReasons: { loggedOut: 401, badSession: 500, connectionReplaced: 440, restartRequired: 515 },
    qrImage: async value => 'data:image/png;base64,' + value,
    logger: { error: () => {} },
    makeSocket: options => {
      const socket = {
        ev: new EventEmitter(), user: { id: '5511900000000:1@s.whatsapp.net' }, auth: options.auth,
        ended: false, end() { this.ended = true; },
        async logout() {
          this.ev.emit('creds.update', { registered: true, stale: true });
          this.ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { output: { statusCode: 401 } } } });
        },
      };
      sockets.push(socket); return socket;
    },
  });
  return { manager, records, sockets };
}

test('disconnect invalidates old events and credentials, then permits a fresh QR and connection', async () => {
  const { manager, records, sockets } = harness();
  try {
    await manager.start('1-1');
    const old = sockets[0];
    old.ev.emit('connection.update', { connection: 'open' });
    await manager.entry('1-1').lifecycle;
    assert.equal(manager.state(manager.entry('1-1')).phone, '5511900000000');
    await manager.disconnect('1-1');
    assert.equal(old.ended, true);
    assert.equal(records.get('1-1.enc').creds.registered, false);
    await manager.start('1-1');
    const fresh = sockets[1];
    old.ev.emit('creds.update', { registered: true, stale: true });
    old.ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { output: { statusCode: 401 } } } });
    await old.auth.keys.set({ session: { outdated: 'invalid' } });
    fresh.ev.emit('connection.update', { qr: 'fresh-code' });
    await manager.entry('1-1').lifecycle;
    assert.equal(manager.state(manager.entry('1-1')).qr, 'data:image/png;base64,fresh-code');
    assert.equal(records.get('1-1.enc').creds.stale, undefined);
    assert.equal(records.get('1-1.enc').keys.session, undefined);
    fresh.ev.emit('connection.update', { connection: 'open' });
    await manager.entry('1-1').lifecycle;
    assert.equal(manager.state(manager.entry('1-1')).status, 'connected');
  } finally { await manager.shutdown(); }
});

test('remote logout clears credentials and manual start restores pairing', async () => {
  const { manager, sockets } = harness();
  try {
    await manager.start('1-1');
    sockets[0].ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { output: { statusCode: 401 } } } });
    await manager.entry('1-1').lifecycle;
    assert.equal(manager.entry('1-1').status, 'logged_out');
    await manager.start('1-1', true);
    assert.equal(sockets.length, 1);
    await manager.start('1-1');
    assert.equal(sockets.length, 2);
  } finally { await manager.shutdown(); }
});

test('pairing sockets and reconnect timers are closed even when logout fails', async () => {
  const { manager, sockets } = harness();
  try {
    await manager.start('1-1');
    sockets[0].logout = async () => { throw new Error('No remote session'); };
    await manager.disconnect('1-1');
    await manager.start('1-1');
    assert.equal(sockets[0].ended, true);
    assert.equal(sockets.length, 2);
  } finally { await manager.shutdown(); }
});

test('expired QR is restarted and disconnect cancels a scheduled reconnect', async () => {
  const { manager, sockets } = harness();
  try {
    await manager.start('1-1');
    manager.entry('1-1').status = 'qr';
    manager.entry('1-1').qr = null;
    await manager.start('1-1');
    assert.equal(sockets.length, 2);
    assert.equal(sockets[0].ended, true);
    sockets[1].ev.emit('connection.update', { connection: 'close', lastDisconnect: { error: { output: { statusCode: 428 } } } });
    await manager.entry('1-1').lifecycle;
    assert.ok(manager.entry('1-1').timer);
    await manager.disconnect('1-1');
    assert.equal(manager.entry('1-1').timer, null);
    await manager.start('1-1', true);
    assert.equal(sockets.length, 2);
  } finally { await manager.shutdown(); }
});

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { Inbox } from './inbox.mjs';

function fixture() {
  const files = new Map();
  const store = { read: async (key, fallback) => structuredClone(files.get(key) || fallback), write: async (key, data) => files.set(key, structuredClone(data)) };
  return new Inbox({ store, normalize: content => content, logger: { error() {} } });
}
function raw(id, options = {}) {
  return { key: { id, remoteJid: '5511999999999@s.whatsapp.net', fromMe: false, ...options.key }, message: { conversation: 'Olá ' + id }, messageTimestamp: 1700000000, pushName: 'Cliente', ...options };
}
test('inbox keeps all messages, deduplicates history, survives restart, and isolates companies', async () => {
  const inbox = fixture();
  await inbox.capture('1-1', [raw('a'), raw('b')]);
  await inbox.capture('1-1', [raw('a')], true);
  await inbox.capture('2-2', [raw('private')]);
  assert.equal((await inbox.feed('1-1', 0)).events.length, 2);
  const next = await inbox.feed('1-1', 1);
  assert.equal(next.events[0].id, 'b'); assert.equal(next.cursor, 2);
  const restarted = new Inbox({ store: inbox.store });
  assert.equal((await restarted.feed('1-1', 0)).events.length, 2);
  assert.equal((await restarted.feed('2-2', 0)).events[0].id, 'private');
  assert.equal((await inbox.getMessage('1-1', raw('a').key)).conversation, 'Olá a');
});
test('inbox ignores groups, view-once and retired socket events and captures historical media', async () => {
  const inbox = fixture(); const socket = { ev: new EventEmitter() }; let current = true;
  inbox.bind('1-1', socket, () => current);
  socket.ev.emit('messages.upsert', { type: 'notify', messages: [raw('a'), raw('group', { key: { id: 'group', remoteJid: '123@g.us' } }), raw('once', { message: { viewOnceMessage: { message: { imageMessage: {} } } } })] });
  await inbox.queues.get('1-1');
  current = false; socket.ev.emit('messages.upsert', { type: 'notify', messages: [raw('stale')] });
  await inbox.capture('1-1', [raw('photo', { message: { imageMessage: { caption: 'Foto', mimetype: 'image/jpeg' } } })], true);
  const feed = await inbox.feed('1-1', 0); assert.equal(feed.events.length, 2); assert.equal(feed.events[1].kind, 'image'); assert.equal(feed.events[1].historical, true);
});
test('chat sending is durable and idempotent even for ambiguous network failures', async () => {
  const inbox = fixture(); let sends = 0;
  const socket = { sendMessage: async (jid, content) => { sends++; return raw('sent-id', { key: { id: 'sent-id', remoteJid: jid, fromMe: true }, message: { conversation: content.text } }); } };
  const input = { jid: '5511999999999@s.whatsapp.net', requestKey: 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa', text: 'Resposta' };
  assert.equal((await inbox.send('1-1', socket, input)).status, 'sent');
  await inbox.send('1-1', socket, input); assert.equal(sends, 1);
  await inbox.queues.get('1-1'); assert.equal((await inbox.feed('1-1', 0)).events[0].fromMe, true);
  assert.equal((await inbox.feed('1-1', 0)).events[0].requestKey, input.requestKey);
  const failed = { ...input, requestKey: 'bbbbbbbb-bbbb-4bbb-abbb-bbbbbbbbbbbb' };
  const broken = { sendMessage: async () => { sends++; throw new Error('Timeout'); } };
  assert.equal((await inbox.send('1-1', broken, failed)).status, 'unknown');
  assert.equal((await inbox.send('1-1', socket, failed)).status, 'unknown'); assert.equal(sends, 2);
  await assert.rejects(inbox.send('1-1', null, { ...input, requestKey: 'cccccccc-cccc-4ccc-accc-cccccccccccc' }), { status: 409 });
});
test('delivery receipts advance monotonically and feed pages preserve events', async () => {
  const inbox = fixture();
  const outgoing = raw('sent', { key: { id: 'sent', remoteJid: '5511999999999@s.whatsapp.net', fromMe: true } });
  await inbox.capture('1-1', [outgoing]);
  await inbox.updates('1-1', [{ key: outgoing.key, update: { status: 4 } }, { key: outgoing.key, update: { status: 3 } }]);
  assert.equal((await inbox.feed('1-1', 0)).events.at(-1).status, 'read');
  assert.equal((await inbox.feed('1-1', 0)).events.length, 2);
  await inbox.capture('1-1', Array.from({ length: 210 }, (_, id) => raw(String(id))));
  const page = await inbox.feed('1-1', 0); assert.equal(page.events.length, 200); assert.equal(page.more, true);
  assert.equal((await inbox.feed('1-1', page.cursor)).events.length, 12);
});
test('LID-addressed messages use the phone alias for chat identity and receive edit/delete events', async () => {
  const inbox = fixture(); const socket = { ev: new EventEmitter() };
  inbox.bind('1-1', socket, () => true);
  const message = raw('lid', { key: { id: 'lid', fromMe: true, remoteJid: '12345@lid', remoteJidAlt: '5511999999999@s.whatsapp.net' } });
  await inbox.capture('1-1', [message]);
  await inbox.updates('1-1', [{ key: message.key, update: { status: 4, message: { conversation: 'Corrigida' } } }]);
  let feed = await inbox.feed('1-1', 0);
  assert.equal(feed.events[0].jid, '5511999999999@s.whatsapp.net');
  assert.equal(feed.events[1].type, 'edited'); assert.equal(feed.events[2].status, 'read');
  socket.ev.emit('messages.delete', { keys: [message.key] }); await inbox.queues.get('1-1');
  feed = await inbox.feed('1-1', 0); assert.equal(feed.events.at(-1).type, 'deleted');
});
test('recipient receipts advance to delivered/read and incoming read requests use chronological keys', async () => {
  const inbox = fixture(); const socket = { ev: new EventEmitter(), readMessages: async keys => { socket.readKeys = keys; } };
  inbox.bind('1-1', socket, () => true);
  const outgoing = raw('out', { key: { id: 'out', remoteJid: '5511999999999@s.whatsapp.net', fromMe: true } });
  await inbox.capture('1-1', [outgoing, raw('latest', { messageTimestamp: 200 }), raw('old', { messageTimestamp: 100 })]);
  socket.ev.emit('message-receipt.update', [{ key: outgoing.key, receipt: { receiptTimestamp: 300 } }]); await inbox.queues.get('1-1');
  assert.equal((await inbox.feed('1-1', 0)).events.at(-1).status, 'delivered');
  socket.ev.emit('message-receipt.update', [{ key: outgoing.key, receipt: { readTimestamp: 400 } }]); await inbox.queues.get('1-1');
  assert.equal((await inbox.feed('1-1', 0)).events.at(-1).status, 'read');
  const result = await inbox.read('1-1', socket, '5511999999999@s.whatsapp.net');
  assert.deepEqual(result.ids, ['old', 'latest']);
  assert.deepEqual(socket.readKeys.map(k => k.id), ['old', 'latest']);
});

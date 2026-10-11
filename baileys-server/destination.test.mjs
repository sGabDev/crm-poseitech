import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolveDestination } from './destination.mjs';

test('uses the WhatsApp canonical recipient instead of rebuilding it from the submitted phone', async () => {
  const socket = { onWhatsApp: async () => [{ exists: true, jid: '551188888888@s.whatsapp.net' }] };
  assert.equal(await resolveDestination(socket, '5511988888888'), '551188888888@s.whatsapp.net');
  await assert.rejects(resolveDestination({ onWhatsApp: async () => [] }, '5511988888888'), { status: 422 });
});

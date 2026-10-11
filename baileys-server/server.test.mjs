import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import net from 'node:net';
import { once } from 'node:events';

test('standalone service starts outside CRM and authenticates its API', { timeout: 20000 }, async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'baileys-api-'));
  const listener = net.createServer();
  listener.listen(0, '127.0.0.1');
  await once(listener, 'listening');
  const port = listener.address().port;
  await new Promise(resolve => listener.close(resolve));
  const token = 'test-token-with-at-least-32-characters';
  const child = spawn(process.execPath, [fileURLToPath(new URL('server.mjs', import.meta.url))], {
    cwd: directory,
    windowsHide: true,
    env: {
      ...process.env,
      WHATSAPP_SERVICE_TOKEN: token,
      WHATSAPP_SESSION_KEY: 'test-encryption-key-at-least-32-characters',
      WHATSAPP_SERVICE_HOST: '127.0.0.1',
      WHATSAPP_SERVICE_PORT: String(port),
      WHATSAPP_DATA_DIR: path.join(directory, 'sessions'),
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  const closed = new Promise(resolve => child.once('close', resolve));
  let diagnostics = '';
  child.stderr.on('data', data => { diagnostics += data; });
  try {
    await new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error('Service startup timeout: ' + diagnostics)), 12000);
      child.once('error', error => { clearTimeout(timeout); reject(error); });
      child.once('exit', code => { clearTimeout(timeout); reject(new Error(`Service exited ${code}: ${diagnostics}`)); });
      child.stdout.on('data', data => {
        if (data.toString().includes('WhatsApp service listening')) { clearTimeout(timeout); resolve(); }
      });
    });
    const base = `http://127.0.0.1:${port}`;
    const headers = { Authorization: 'Bearer ' + token, 'Content-Type': 'application/json' };
    assert.equal((await fetch(base + '/health')).status, 401);
    assert.equal((await fetch(base + '/health', { headers })).status, 200);
    for (const session of ['1/1', '2/1']) {
      const response = await fetch(base + '/sessions/' + session, { headers });
      assert.deepEqual(await response.json(), { status: 'idle', qr: null, phone: null });
    }
    assert.equal((await fetch(base + '/sessions/1/1/messages', {
      method: 'POST', headers, body: JSON.stringify({ id: '1', phone: '5511999999999', text: 'Test' }),
    })).status, 409);
    assert.equal((await fetch(base + '/sessions/1/1/messages', {
      method: 'POST', headers, body: JSON.stringify({ id: '../invalid' }),
    })).status, 422);
  } finally {
    child.kill('SIGTERM');
    await closed;
    await rm(directory, { recursive: true, force: true });
  }
});

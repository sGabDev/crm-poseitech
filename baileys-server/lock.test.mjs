import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, mkdir, utimes, rm, access } from 'node:fs/promises';
import path from 'node:path';
import { tmpdir } from 'node:os';
import { acquireServiceLock } from './lock.mjs';

test('recovers abandoned legacy lock and rejects a concurrent instance', async () => {
  const directory = await mkdtemp(path.join(tmpdir(), 'baileys-lock-'));
  const lock = path.join(directory, '.service-lock');
  let release;
  try {
    await mkdir(lock);
    const old = new Date(Date.now() - 60000);
    await utimes(lock, old, old);
    release = await acquireServiceLock(directory, 0);
    await assert.rejects(acquireServiceLock(directory, 0), /Outra instância/);
    await release();
    release = null;
    await assert.rejects(access(lock), { code: 'ENOENT' });
    release = await acquireServiceLock(directory, 0);
  } finally {
    if (release) await release();
    await rm(directory, { recursive: true, force: true });
  }
});

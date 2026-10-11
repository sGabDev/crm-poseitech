import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { Store } from './store.mjs';
test('encrypted snapshots survive concurrent updates and reject wrong key',async()=>{
 const dir=await mkdtemp(path.join(tmpdir(),'crm-whatsapp-'));
 try{
  const store=new Store(dir,'secret');
  await Promise.all([store.write('session.enc',{credential:'private',version:1}),store.write('session.enc',{credential:'private',version:2})]);
  assert.equal((await store.read('session.enc')).version,2);
  assert.equal((await readFile(path.join(dir,'session.enc'))).includes(Buffer.from('private')),false);
  await assert.rejects(new Store(dir,'wrong').read('session.enc'));
  assert.deepEqual(await store.read('missing.enc',{}),{});
 }finally{await rm(dir,{recursive:true,force:true});}
});

import { createCipheriv, createDecipheriv, randomBytes, createHash } from 'node:crypto';
import { mkdir, readFile, writeFile, rename } from 'node:fs/promises';
import path from 'node:path';
// Atomic, encrypted snapshots. A single service process owns this directory.
export class Store {
 constructor(directory, secret, replacer, reviver) {this.directory=directory;this.key=createHash('sha256').update(secret).digest();this.replacer=replacer;this.reviver=reviver;this.tail=Promise.resolve();}
 async read(name, fallback) {
  try {const raw=await readFile(path.join(this.directory,name));const decipher=createDecipheriv('aes-256-gcm',this.key,raw.subarray(0,12));decipher.setAuthTag(raw.subarray(12,28));return JSON.parse(Buffer.concat([decipher.update(raw.subarray(28)),decipher.final()]).toString(),this.reviver);}
  catch(error){if(error.code==='ENOENT')return fallback;throw error;}
 }
 write(name, value) {
  const json=JSON.stringify(value,this.replacer);
  const operation=this.tail.then(async()=>{await mkdir(this.directory,{recursive:true,mode:0o700});const iv=randomBytes(12);const cipher=createCipheriv('aes-256-gcm',this.key,iv);const body=Buffer.concat([cipher.update(json),cipher.final()]);const target=path.join(this.directory,name);await writeFile(target+'.tmp',Buffer.concat([iv,cipher.getAuthTag(),body]),{mode:0o600});await rename(target+'.tmp',target);});
  this.tail=operation.catch(()=>{});return operation;
 }
}

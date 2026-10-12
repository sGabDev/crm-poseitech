import dotenv from 'dotenv';
import { fileURLToPath } from 'node:url';
const serviceRoot = fileURLToPath(new URL('.', import.meta.url));
dotenv.config({ path: new URL('.env', import.meta.url) });
import http from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import makeWASocket, { BufferJSON, DisconnectReason, initAuthCreds, proto, normalizeMessageContent, downloadMediaMessage } from 'baileys';
import QRCode from 'qrcode';
import pino from 'pino';
import { Store } from './store.mjs';
import { acquireServiceLock } from './lock.mjs';
import { resolveDestination } from './destination.mjs';
import { SessionManager } from './sessions.mjs';
import { Inbox } from './inbox.mjs';

const token=process.env.WHATSAPP_SERVICE_TOKEN;
const encryptionKey=process.env.WHATSAPP_SESSION_KEY;
if(!token || token.length<32 || !encryptionKey || encryptionKey.length<32) throw new Error('Configure WHATSAPP_SERVICE_TOKEN e WHATSAPP_SESSION_KEY (mínimo 32 caracteres distintos).');
const directory=path.resolve(serviceRoot, process.env.WHATSAPP_DATA_DIR || 'data');
await mkdir(directory,{recursive:true,mode:0o700});
const releaseLock = await acquireServiceLock(directory);
const store=new Store(directory,encryptionKey,BufferJSON.replacer,BufferJSON.reviver);
const logger=pino({level:process.env.WHATSAPP_LOG_LEVEL||'warn'});
const inbox = new Inbox({ store, normalize: normalizeMessageContent, download: downloadMediaMessage, logger });
let registry=await store.read('registry.enc',{});
let journal=await store.read('messages.enc',{});
for(const value of Object.values(journal)) if(value.status==='sending') value.status='unknown';
await store.write('messages.enc',journal);
let stopping=false;
const manager = new SessionManager({
 store, registry, freshCredentials: initAuthCreds,
 appStateKey: value => proto.Message.AppStateSyncKeyData.fromObject(value),
 disconnectReasons: DisconnectReason,
 qrImage: value => QRCode.toDataURL(value, { width: 260, margin: 2 }),
 makeSocket: options => makeWASocket({ ...options, logger, markOnlineOnConnect: false, syncFullHistory: true, shouldSyncHistoryMessage: () => true, browser: ['PoseiTech CRM', 'Chrome', '1.0.0'] }),
 onSocket: (key, socket, current) => inbox.bind(key, socket, current),
 getMessage: (key, messageKey) => inbox.getMessage(key, messageKey),
 logger, isStopping: () => stopping,
});
const sessions = manager.sessions;
const publicState = session => manager.state(session);
const entry = key => manager.entry(key);
const start = key => manager.start(key);
const disconnect = key => manager.disconnect(key);
const error=(status,message)=>Object.assign(new Error(message),{status});
async function send(key,data){
 if(!/^[1-9][0-9]{0,18}$/.test(data.id||'') || !/^[1-9][0-9]{10,14}$/.test(data.phone||'') || typeof data.text!=='string' || !data.text.length || data.text.length>8000)throw error(422,'Invalid message');
 // Message identity belongs to the company, even if its selected bot changes.
 const id=key.split('-')[0]+'-'+data.id;
 const existing=journal[id];if(existing)return {status:existing.status==='sending'?'unknown':existing.status};
 const s=entry(key);if(s.status!=='connected' || !s.socket)throw error(409,'Not connected');
 // Durable reservation before network I/O prevents duplicate sends after retries/restarts.
 journal[id]={status:'sending',at:Date.now()};
 await store.write('messages.enc',journal);
 let destination;
 try { destination=await resolveDestination(s.socket,data.phone); }
 catch(e){delete journal[id];await store.write('messages.enc',journal);if(e.status===422)throw e;throw error(503,'Number lookup failed');}
 try{const raw=await s.socket.sendMessage(destination,{text:data.text});journal[id].status='sent';await inbox.capture(key,[raw]);}
 catch{journal[id].status='unknown';}
 await store.write('messages.enc',journal);return {status:journal[id].status};
}
async function body(req){let text='';for await(const chunk of req){text+=chunk;if(Buffer.byteLength(text)>11500000)throw error(413,'Body too large');}try{return JSON.parse(text||'{}');}catch{throw error(400,'Invalid JSON');}}
const server=http.createServer(async(req,res)=>{
 res.setHeader('Content-Type','application/json');res.setHeader('Cache-Control','no-store');
 try{
  const provided=Buffer.from(req.headers.authorization||'');const expected=Buffer.from('Bearer '+token);
  if(provided.length!==expected.length || !timingSafeEqual(provided,expected))throw error(401,'Unauthorized');
  if(req.url==='/health' && req.method==='GET'){res.end(JSON.stringify({status:'ok'}));return;}
  const url = new URL(req.url, 'http://localhost');
  const match=/^\/sessions\/([1-9][0-9]{0,18})\/([1-9][0-9]{0,18})(?:\/(connect|reconnect|disconnect|messages|events|changes|chat-send|chat-read|chat-media|chat-resolve))?$/.exec(url.pathname);
  if(!match)throw error(404,'Not found');
  const key=match[1]+'-'+match[2];const action=match[3];const s=entry(key);
  if(req.method==='GET' && (action==='events' || action==='changes')) {
   const after = Number(url.searchParams.get('after') || 0);
   if(!Number.isSafeInteger(after) || after<0)throw error(422,'Invalid cursor');
   if(action==='changes') {
    const controller = new AbortController(); const cancel = () => controller.abort(); res.once('close', cancel);
    try { const result = await inbox.changes(key,after,controller.signal); if(!res.destroyed)res.end(JSON.stringify(result)); }
    finally { res.off('close',cancel); } return;
   }
   res.end(JSON.stringify({...await inbox.feed(key,after), status:s.status}));return;
  }
  if(req.method==='GET' && !action){res.end(JSON.stringify(publicState(s)));return;}
  if(req.method!=='POST' || !action)throw error(405,'Method not allowed');
  const data=await body(req);
  const job=s.tail.then(async()=>{
   if(action==='connect')return start(key);
   if(action==='reconnect'){await disconnect(key);return start(key);}
   if(action==='disconnect')return disconnect(key);
   if(action==='chat-send')return inbox.send(key,s.status==='connected'?s.socket:null,data);
   if(action==='chat-read')return inbox.read(key,s.status==='connected'?s.socket:null,data.jid);
   if(action==='chat-media')return inbox.media(key,s.socket,data.jid,data.id);
   if(action==='chat-resolve') {
    if(s.status!=='connected' || !s.socket)throw error(409,'Not connected');
    if(!/^[1-9][0-9]{10,14}$/.test(data.phone||''))throw error(422,'Invalid phone');
    return {jid:await resolveDestination(s.socket,data.phone)};
   }
   if(action==='events')throw error(405,'Method not allowed');
   return send(key,data);
  });
  s.tail=job.catch(()=>{});res.end(JSON.stringify(await job));
 }catch(e){res.statusCode=e.status||500;res.end(JSON.stringify({error:e.status?e.message:'Service error'}));}
});
server.requestTimeout=30000;server.headersTimeout=10000;
server.listen(Number(process.env.WHATSAPP_SERVICE_PORT||3100),process.env.WHATSAPP_SERVICE_HOST||'127.0.0.1',()=>console.log('WhatsApp service listening'));
for(const [key,enabled] of Object.entries(registry))if(enabled)start(key).catch(error=>logger.error({error:error.message},'Session restore failed'));
async function shutdown(){if(stopping)return;stopping=true;server.close();await manager.shutdown();await Promise.all([...inbox.queues.values()]);await store.tail;await releaseLock();process.exit(0);}
process.on('SIGINT',shutdown);process.on('SIGTERM',shutdown);

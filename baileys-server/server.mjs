import dotenv from 'dotenv';
import { fileURLToPath } from 'node:url';
const serviceRoot = fileURLToPath(new URL('.', import.meta.url));
dotenv.config({ path: new URL('.env', import.meta.url) });
import http from 'node:http';
import { timingSafeEqual } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import path from 'node:path';
import makeWASocket, { BufferJSON, DisconnectReason, initAuthCreds, proto } from 'baileys';
import QRCode from 'qrcode';
import pino from 'pino';
import { Store } from './store.mjs';
import { acquireServiceLock } from './lock.mjs';

const token=process.env.WHATSAPP_SERVICE_TOKEN;
const encryptionKey=process.env.WHATSAPP_SESSION_KEY;
if(!token || token.length<32 || !encryptionKey || encryptionKey.length<32) throw new Error('Configure WHATSAPP_SERVICE_TOKEN e WHATSAPP_SESSION_KEY (mínimo 32 caracteres distintos).');
const directory=path.resolve(serviceRoot, process.env.WHATSAPP_DATA_DIR || 'data');
await mkdir(directory,{recursive:true,mode:0o700});
const releaseLock = await acquireServiceLock(directory);
const store=new Store(directory,encryptionKey,BufferJSON.replacer,BufferJSON.reviver);
const logger=pino({level:process.env.WHATSAPP_LOG_LEVEL||'warn'});
const sessions=new Map();
let registry=await store.read('registry.enc',{});
let journal=await store.read('messages.enc',{});
for(const value of Object.values(journal)) if(value.status==='sending') value.status='unknown';
await store.write('messages.enc',journal);
let stopping=false;
const publicState=s=>({status:s?.status||'idle',qr:s?.qr||null,phone:s?.phone||null});
function entry(key) {
 if(!sessions.has(key)) sessions.set(key,{status:'idle',qr:null,phone:null,socket:null,timer:null,starting:null,enabled:false,retries:0,tail:Promise.resolve()});
 return sessions.get(key);
}
async function start(key) {
 const s=entry(key);
 if(s.starting)return s.starting;
 if(s.socket || stopping)return publicState(s);
 s.enabled=true;
 s.starting=(async()=>{
  registry[key]=true;await store.write('registry.enc',registry);
  const auth=await store.read(key+'.enc',{creds:initAuthCreds(),keys:{}});
  const save=()=>store.write(key+'.enc',auth);
  const keys={get:async(type,ids)=>Object.fromEntries(ids.filter(id=>auth.keys[type]?.[id]!=null).map(id=>[id,type==='app-state-sync-key'?proto.Message.AppStateSyncKeyData.fromObject(auth.keys[type][id]):auth.keys[type][id]])),
   set:async(data)=>{for(const [type,values] of Object.entries(data)){auth.keys[type]??={};for(const [id,value] of Object.entries(values)){if(value==null)delete auth.keys[type][id];else auth.keys[type][id]=value;}}await save();}};
  s.status='connecting';s.qr=null;
  const socket=makeWASocket({auth:{creds:auth.creds,keys},logger,markOnlineOnConnect:false,syncFullHistory:false,shouldSyncHistoryMessage:()=>false,browser:['PoseiTech CRM','Chrome','1.0.0']});
  s.socket=socket;
  socket.ev.on('creds.update',updates=>{Object.assign(auth.creds,updates);save().catch(error=>logger.error({error:error.message},'Credential persistence failed'));});
  socket.ev.on('connection.update',update=>{
   (async()=>{
    if(s.socket!==socket)return;
    if(update.qr){const qr=await QRCode.toDataURL(update.qr,{width:260,margin:2});if(s.socket!==socket)return;s.qr=qr;s.status='qr';clearTimeout(s.qrTimer);s.qrTimer=setTimeout(()=>{s.qr=null;},60000);}
    if(update.connection==='open'){s.status='connected';s.qr=null;s.phone=socket.userá.id?.split(':')[0]?.split('@')[0]||null;s.retries=0;clearTimeout(s.qrTimer);}
    if(update.connection==='close'){
     s.socket=null;s.qr=null;clearTimeout(s.qrTimer);
     const code=update.lastDisconnect?.error?.output?.statusCode;
     if(code===DisconnectReason.loggedOut){s.status='logged_out';s.phone=null;s.enabled=false;registry[key]=false;await store.write('registry.enc',registry);await store.write(key+'.enc',{creds:initAuthCreds(),keys:{}});}
     else if(s.enabled && !stopping){s.status='reconnecting';s.timer=setTimeout(()=>start(key).catch(error=>logger.error({error:error.message},'Reconnect failed')),Math.min(60000,1000*2**Math.min(s.retries++,6)));}
     else s.status='idle';
    }
   })().catch(error=>logger.error({error:error.message},'Connection update failed'));
  });
  return publicState(s);
 })();
 try{return await s.starting;}finally{s.starting=null;}
}
async function disconnect(key){
 const s=entry(key);s.enabled=false;clearTimeout(s.timer);clearTimeout(s.qrTimer);
 if(s.starting)await s.starting;
 const socket=s.socket;s.socket=null;s.qr=null;s.phone=null;s.status='idle';
 registry[key]=false;await store.write('registry.enc',registry);
 if(socket){try{await socket.logout();}catch{socket.end(new Error('Disconnected by administrator'));}}
 await store.write(key+'.enc',{creds:initAuthCreds(),keys:{}});
 return publicState(s);
}
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
 try {
  const [result]=await s.socket.onWhatsApp(data.phone);
  if(!result?.exists){delete journal[id];await store.write('messages.enc',journal);throw error(422,'Number is not registered');}
 }catch(e){if(e.status===422)throw e;delete journal[id];await store.write('messages.enc',journal);throw error(503,'Number lookup failed');}
 try{await s.socket.sendMessage(data.phone+'@s.whatsapp.net',{text:data.text});journal[id].status='sent';}
 catch{journal[id].status='unknown';}
 await store.write('messages.enc',journal);return {status:journal[id].status};
}
async function body(req){let text='';for await(const chunk of req){text+=chunk;if(Buffer.byteLength(text)>16000)throw error(413,'Body too large');}try{return JSON.parse(text||'{}');}catch{throw error(400,'Invalid JSON');}}
const server=http.createServer(async(req,res)=>{
 res.setHeader('Content-Type','application/json');res.setHeader('Cache-Control','no-store');
 try{
  const provided=Buffer.from(req.headers.authorization||'');const expected=Buffer.from('Bearer '+token);
  if(provided.length!==expected.length || !timingSafeEqual(provided,expected))throw error(401,'Unauthorized');
  if(req.url==='/health' && req.method==='GET'){res.end(JSON.stringify({status:'ok'}));return;}
  const match=/^\/sessions\/([1-9][0-9]{0,18})\/([1-9][0-9]{0,18})(?:\/(connect|disconnect|messages))?$/.exec(req.url);
  if(!match)throw error(404,'Not found');
  const key=match[1]+'-'+match[2];const action=match[3];const s=entry(key);
  if(req.method==='GET' && !action){res.end(JSON.stringify(publicState(s)));return;}
  if(req.method!=='POST' || !action)throw error(405,'Method not allowed');
  const data=await body(req);
  const job=s.tail.then(()=>action==='connect'?start(key):action==='disconnect'?disconnect(key):send(key,data));
  s.tail=job.catch(()=>{});res.end(JSON.stringify(await job));
 }catch(e){res.statusCode=e.status||500;res.end(JSON.stringify({error:e.status?e.message:'Service error'}));}
});
server.requestTimeout=30000;server.headersTimeout=10000;
server.listen(Number(process.env.WHATSAPP_SERVICE_PORT||3100),process.env.WHATSAPP_SERVICE_HOST||'127.0.0.1',()=>console.log('WhatsApp service listening'));
for(const [key,enabled] of Object.entries(registry))if(enabled)start(key).catch(error=>logger.error({error:error.message},'Session restore failed'));
async function shutdown(){if(stopping)return;stopping=true;server.close();for(const s of sessions.values()){s.enabled=false;clearTimeout(s.timer);clearTimeout(s.qrTimer);s.socket?.end(new Error('Service shutdown'));}await store.tail;await releaseLock();process.exit(0);}
process.on('SIGINT',shutdown);process.on('SIGTERM',shutdown);

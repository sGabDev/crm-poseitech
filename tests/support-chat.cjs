const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/support-chat.js', 'utf8');
function element() {
 return {events:{},hidden:false,addEventListener(key, fn){this.events[key]=fn;}};
}
(async () => {
 const timers = new Map(); let timerId=0, attempts=0, unavailable=false;
 const older=element(), notice=element(), thread=element(), viewport=element();
 thread.children=Array.from({length:30});thread.querySelectorAll=()=>[{dataset:{messageId:'7'}}];
 thread.insertAdjacentHTML=()=>{};
 viewport.dataset={history:'/support/1/history'};
 viewport.scrollHeight=600;viewport.clientHeight=400;viewport.scrollTop=0;
 viewport.querySelector=selector=>selector==='.support-thread'?thread:older;
 const document={hidden:false,hasFocus:()=>true,addEventListener(){},querySelectorAll:()=>[],
  querySelector:selector=>selector==='[data-support-chat]'?viewport:selector==='[data-chat-notice]'?notice:null};
 const window={location:{href:'http://localhost/support/1'},addEventListener(){},
  setTimeout(fn,delay){const id=++timerId;timers.set(id,{fn,delay});return id;},clearTimeout(id){timers.delete(id);}};
 vm.runInNewContext(source,{document,window,URL,AbortController,
  fetch:async (url, options)=>{
   attempts++;
   if(attempts===1) return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>reject(Error('timeout'))));
   return {ok:!unavailable,status:unavailable?503:200,headers:{get:()=>unavailable?'120':null},json:async()=>({html:'',more:false})};
  }});
 const request=older.events.click();assert.equal(older.disabled,true);
 const timeout=[...timers.values()].find(timer=>timer.delay===20000);assert.ok(timeout);
 timeout.fn();await request;
 assert.equal(older.disabled,false);assert.equal(notice.hidden,false);
 assert.match(notice.textContent,/indisponível/);
 assert.equal([...timers.values()].filter(timer=>timer.delay===20000).length,0);
 await older.events.click();assert.equal(attempts,2);assert.equal(older.disabled,false);
 assert.equal(older.hidden,true);
 unavailable=true;
 const poll=[...timers.values()].find(timer=>timer.delay===15000);
 await poll.fn();assert.equal(attempts,3);
 await older.events.click();assert.equal(attempts,3);
 assert.ok([...timers.values()].some(timer=>timer.delay>=119000));
 console.log('Suporte: timeout libera o histórico, informa falha e permite nova tentativa; timers limpos OK.');
})().catch(error=>{console.error(error);process.exitCode=1;});

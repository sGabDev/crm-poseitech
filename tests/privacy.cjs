const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/assets/privacy.js', 'utf8');
function boot(value, unavailable = false) {
 const events = {}, storage = {}, document = {activeElement: null};
 function element() { return {hidden:true,isConnected:true,attrs:{},events:{},setAttribute(k,v){this.attrs[k]=v;},addEventListener(k,v){this.events[k]=v;},focus(){document.activeElement=this;}}; }
 const open=element(), close=element(), heading=element(), panel=element();
 panel.contains=e=>e===heading||e===close;
 panel.querySelector=()=>heading;
 panel.querySelectorAll=()=>[close];
 document.querySelector=()=>panel;
 document.querySelectorAll=()=>[open];
 vm.runInNewContext(source,{document,window:{addEventListener(k,v){events[k]=v;}},localStorage:{getItem(){if(unavailable)throw Error();return value;},setItem(k,v){if(unavailable)throw Error();storage[k]=v;}}});
 return {panel,open,close,heading,events,storage,document};
}
for(const value of [null,'invalid',String(Date.now()+86400000),String(Date.now()-181*86400000)]) assert.equal(boot(value).panel.hidden,false);
const read=boot(String(Date.now()));assert.equal(read.panel.hidden,true);
read.open.events.click({currentTarget:read.open});assert.equal(read.panel.hidden,false);assert.equal(read.document.activeElement,read.heading);assert.equal(read.open.attrs['aria-expanded'],'true');
read.panel.events.keydown({key:'Escape',preventDefault(){}});assert.equal(read.panel.hidden,true);assert.equal(read.document.activeElement,read.open);assert.ok(read.storage['poseitech-privacy-notice-v1']);
const blocked=boot(null,true);blocked.close.events.click();assert.equal(blocked.panel.hidden,true);
const sync=boot(null);sync.events.storage({key:'poseitech-privacy-notice-v1',newValue:String(Date.now())});assert.equal(sync.panel.hidden,true);
console.log('Cookies: validade, reabertura, foco, Escape, armazenamento indisponível e sincronização OK.');

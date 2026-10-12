/* Edits only the selected part of an inert document, preserving custom layout. */
window.ReceiptDesigner=(()=>{
 const parse=source=>new DOMParser().parseFromString(source,'text/html');
 const locate=doc=>{
  const header=doc.querySelector('h1')?.closest('td');
  const greeting=doc.querySelector('h2');
  const button=[...doc.querySelectorAll('a[href]')].find(link=>{try{return decodeURIComponent(link.getAttribute('href'))==='{comprovante}';}catch{return false;}});
  return {heading:header?.querySelector('p'),greeting,intro:greeting?.closest('td')?.querySelector('p'),button,header,background:doc.body};
 };
 const hex=color=>{
  if(/^#[\da-f]{6}$/i.test(color))return color;
  if(/^#[\da-f]{3}$/i.test(color))return '#'+[...color.slice(1)].map(char=>char+char).join('');
  const match=color.match(/^rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i);
  return match?'#'+match.slice(1,4).map(value=>Math.min(255,Number(value)).toString(16).padStart(2,'0')).join(''):null;
 };
 return {
  read(source){const nodes=locate(parse(source)),text={},colors={};for(const key of ['heading','greeting','intro','button'])text[key]=nodes[key]?.textContent??null;for(const key of ['header','button','background'])colors[key]=nodes[key]?hex(nodes[key].style.backgroundColor||nodes[key].style.background):null;return {text,colors};},
  apply(source,key,value,kind){const doc=parse(source),node=locate(doc)[key];if(!node)return source;if(kind==='color'){if(!/^#[\da-f]{6}$/i.test(value))return source;node.style.backgroundColor=value;}else node.textContent=value;return '<!doctype html>\n'+doc.documentElement.outerHTML;}
 };
})();

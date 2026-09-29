const store = document.querySelector('.catalog-store');
if (store) {
 const cards = [...store.querySelectorAll('.catalog-product')];
 const money = value => new Intl.NumberFormat('pt-BR', {style:'currency', currency:store.dataset.currency || 'BRL'}).format(value / 100);
 const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
 const filter = () => {
  const term = normalize(document.querySelector('#catalog-search').value);
  const category = document.querySelector('#catalog-category').value;
  let count = 0;
  cards.forEach(card => { card.hidden = !normalize(card.dataset.name).includes(term) || !!(category && card.dataset.category !== category); if (!card.hidden) count++; });
  document.querySelector('#catalog-count').textContent = count + ' item(ns)';
  document.querySelector('#catalog-empty').hidden = count > 0;
 };
 document.querySelector('#catalog-search').addEventListener('input', filter);
 document.querySelector('#catalog-category').addEventListener('change', filter);
 document.querySelector('#catalog-sort').addEventListener('change', event => {
  const sorted = [...cards].sort((a,b) => event.target.value === 'name' ? a.dataset.name.localeCompare(b.dataset.name,'pt-BR') : (Number(a.dataset.price)-Number(b.dataset.price)) * (event.target.value === 'price-high' ? -1 : 1));
  sorted.forEach(card => card.parentElement.append(card));
 });
 filter();
 const form = document.querySelector('#catalog-checkout');
 if (form) {
  const storageKey = 'poseitech-cart-' + store.dataset.company;
  const notice = document.querySelector('#catalog-cart-notice');
  const readSaved = () => { try { const saved = JSON.parse(localStorage.getItem(storageKey) || '[]'); return Array.isArray(saved) ? saved : []; } catch { return []; } };
  const restore = (items, merge = false) => {
   let adjusted = false;
   for (const item of items) {
    const card = cards.find(c => c.dataset.id === String(item.product_id));
    const input = card?.querySelector('.catalog-quantity');
    if (!input) { adjusted = true; continue; }
    const wanted = Math.max(0, Math.floor(Number(item.quantity) || 0)) + (merge ? Number(input.value) : 0);
    input.value = Math.min(Number(input.max), wanted);
    if (Number(input.value) !== wanted) adjusted = true;
    const addons = Array.isArray(item.addons) ? item.addons.map(String) : [];
    card.querySelectorAll('.catalog-addon').forEach(addon => addon.checked = addons.includes(addon.value));
   }
   if (adjusted) notice.textContent = 'Alguns itens foram limitados ou removidos conforme o estoque atual. Confira o carrinho.';
  };
  let oldItems = [];
  try { oldItems = Object.values(JSON.parse(form.dataset.oldItems || '[]')); } catch {}
  if (store.dataset.submitted === '1') { try { localStorage.removeItem(storageKey); } catch {} }
  restore(oldItems.length ? oldItems : readSaved());
  const button = (text, label, action) => {
   const element = document.createElement('button'); element.type = 'button'; element.className = 'secondary'; element.textContent = text; element.setAttribute('aria-label', label); element.addEventListener('click', action); return element;
  };
  const update = () => {
   const list = document.querySelector('#catalog-cart-items');
   const inputs = document.querySelector('#catalog-inputs');
   list.replaceChildren(); inputs.replaceChildren();
   let total = 0, units = 0; const saved = [];
   const hidden = (name,value) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; inputs.append(input); };
   cards.forEach(card => {
    const source = card.querySelector('.catalog-quantity');
    const qty = Number(source?.value || 0);
    if (!Number.isInteger(qty) || qty <= 0) return;
    let price = Number(card.dataset.price);
    const addons = [...card.querySelectorAll('.catalog-addon:checked')];
    const index = saved.length;
    hidden(`items[${index}][product_id]`, card.dataset.id); hidden(`items[${index}][quantity]`,qty);
    addons.forEach(addon => { price += Number(addon.dataset.price); hidden(`items[${index}][addons][]`,addon.value); });
    saved.push({product_id:card.dataset.id, quantity:qty, addons:addons.map(a=>a.value)});
    const row = document.createElement('div'); row.className = 'catalog-cart-line';
    const title = document.createElement('strong'); title.textContent = card.dataset.name;
    const detail = document.createElement('small'); detail.textContent = addons.map(a=>a.closest('label').textContent.trim()).join(' · ');
    const value = document.createElement('span'); value.textContent = money(price * qty);
    const controls = document.createElement('div'); controls.className = 'catalog-quantity-controls';
    const quantity = document.createElement('input'); quantity.type = 'number'; quantity.min = 0; quantity.max = source.max; quantity.value = qty; quantity.setAttribute('aria-label','Quantidade de '+card.dataset.name);
    quantity.addEventListener('change',()=>{if(!quantity.checkValidity()){quantity.reportValidity();return;} source.value = quantity.value;update();});
    controls.append(button('−','Diminuir '+card.dataset.name,()=>{source.value = Math.max(0,qty-1);update();}),quantity,button('+','Aumentar '+card.dataset.name,()=>{source.value = Math.min(Number(source.max),qty+1);update();}),button('Remover','Remover '+card.dataset.name,()=>{source.value=0;update();}));
    row.append(title,detail,value,controls); list.append(row); total += price * qty; units += qty;
   });
   if (!saved.length) list.textContent = 'Seu carrinho está vazio. Escolha seus favoritos na vitrine.';
   document.querySelector('#catalog-total').textContent = money(total);
   document.querySelector('#catalog-bar-total').textContent = units + ' item(ns) · ' + money(total);
   document.querySelector('#catalog-send').disabled = !saved.length;
   document.querySelector('#catalog-clear').disabled = !saved.length;
   try { localStorage.setItem(storageKey,JSON.stringify(saved)); } catch {}
  };
  cards.forEach(card => {
   card.querySelector('.catalog-add')?.addEventListener('click',()=>{
    const input = card.querySelector('.catalog-quantity');
    if (Number(input.value)>=Number(input.max)) {notice.textContent='Quantidade máxima disponível atingida para '+card.dataset.name+'.';return;}
    input.value = Math.min(Number(input.max),Math.max(0,Number(input.value))+1); notice.textContent=card.dataset.name+' adicionado ao carrinho.'; update();
   });
   card.querySelectorAll('input').forEach(input => input.addEventListener('input', update));
  });
  document.querySelector('#catalog-clear').addEventListener('click',()=>{cards.forEach(card=>{const input=card.querySelector('.catalog-quantity');if(input)input.value=0;card.querySelectorAll('.catalog-addon').forEach(addon=>addon.checked=false);});notice.textContent='Carrinho limpo.';update();});
  document.querySelectorAll('.catalog-reorder').forEach(element=>element.addEventListener('click',()=>{restore(JSON.parse(element.dataset.items),true);update();document.querySelector('#catalog-cart').scrollIntoView({behavior:'smooth'});}));
  form.addEventListener('submit', event => {
   const invalid = cards.map(card=>card.querySelector('.catalog-quantity')).find(input=>input&&!input.checkValidity());
   if (invalid) {event.preventDefault(); invalid.closest('.catalog-product').hidden=false; invalid.reportValidity();return;}
   document.querySelector('#catalog-send').disabled=true; document.querySelector('#catalog-send').textContent='Enviando pedido…';
  });
  update();
 }
}

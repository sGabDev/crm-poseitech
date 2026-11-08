/* Shared progressive enhancements; native forms retain their original payload. */
(() => {
 const pending = new Map();
 document.addEventListener('submit', event => {
  const form = event.target;
  if (form.method.toLowerCase() !== 'post' || form.target === '_blank') return;
  if (pending.has(form)) { event.preventDefault(); return; }
  // Run after local validation, confirmations and asynchronous form handlers.
  queueMicrotask(() => {
   if (event.defaultPrevented) return;
   const buttons = [...form.querySelectorAll('button:not([type]),button[type="submit"],input[type="submit"]')];
   const state = {busy:form.getAttribute('aria-busy'), buttons:buttons.map(button => [button, button.getAttribute('aria-disabled')])};
   pending.set(form, state);
   form.setAttribute('aria-busy', 'true');
   // Disabling a submitter would remove its name/value from the POST request.
   buttons.forEach(button => button.setAttribute('aria-disabled', 'true'));
  });
 });
 window.addEventListener('pageshow', () => {
  for (const [form, state] of pending) {
   if (state.busy === null) form.removeAttribute('aria-busy');
   else form.setAttribute('aria-busy', state.busy);
   state.buttons.forEach(([button, value]) => {
    if (value === null) button.removeAttribute('aria-disabled');
    else button.setAttribute('aria-disabled', value);
   });
  }
  pending.clear();
 });
 document.querySelectorAll('.table-wrap').forEach(wrapper => {
  const table = wrapper.querySelector('table');
  if (!table) return;
  wrapper.tabIndex = 0;
  wrapper.setAttribute('role', 'region');
  const section = wrapper.closest('section,.card');
  const title = table.querySelector('caption') || section?.querySelector('h2,h3');
  wrapper.setAttribute('aria-label', title?.textContent.trim() || 'Tabela de dados; use as setas para rolar');
  table.querySelectorAll('thead th:not([scope])').forEach(cell => cell.setAttribute('scope', 'col'));
 });
 const errors = document.querySelector('.notice.error[role="alert"]');
 if (errors) { errors.tabIndex = -1; errors.focus(); }
})();

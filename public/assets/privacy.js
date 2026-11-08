(() => {
 'use strict';
 const panel = document.querySelector('[data-privacy-panel]');
 const triggers = Array.from(document.querySelectorAll('[data-privacy-open]'));
 if (!panel || !triggers.length) return;
 const key = 'poseitech-privacy-notice-v1';
 const lifetime = 180 * 24 * 60 * 60 * 1000;
 let trigger = null;
 const valid = value => {
  const timestamp = Number(value);
  return Number.isFinite(timestamp) && timestamp > 0 && timestamp <= Date.now() && Date.now() - timestamp < lifetime;
 };
 const setVisible = visible => {
  panel.hidden = !visible;
  triggers.forEach(button => button.setAttribute('aria-expanded', String(visible)));
 };
 const show = event => {
  trigger = event.currentTarget;
  setVisible(true);
  panel.querySelector('#privacy-heading')?.focus();
 };
 const dismiss = () => {
  const restoreFocus = panel.contains(document.activeElement);
  setVisible(false);
  try { localStorage.setItem(key, String(Date.now())); } catch { /* Storage may be disabled. */ }
  if (restoreFocus) (trigger?.isConnected ? trigger : triggers[0]).focus();
  trigger = null;
 };
 triggers.forEach(button => {
  button.hidden = false;
  button.addEventListener('click', show);
 });
 panel.querySelectorAll('[data-privacy-close], [data-privacy-dismiss]').forEach(button => button.addEventListener('click', dismiss));
 panel.addEventListener('keydown', event => {
  if (event.key === 'Escape') { event.preventDefault(); dismiss(); }
 });
 window.addEventListener('storage', event => {
  if (event.key === key && valid(event.newValue)) {
   if (panel.contains(document.activeElement)) (trigger?.isConnected ? trigger : triggers[0]).focus();
   setVisible(false);
  }
 });
 try { setVisible(!valid(localStorage.getItem(key))); } catch { setVisible(true); }
})();

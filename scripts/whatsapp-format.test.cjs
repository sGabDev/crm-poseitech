const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

class Node {
  constructor(tag = '#text') { this.tag = tag; this.children = []; this.textContent = ''; }
  append(...nodes) { this.children.push(...nodes); }
  set innerHTML(_) { throw new Error('Unsafe HTML'); }
}
function format(text) {
  const window = {};
  vm.runInNewContext(fs.readFileSync('public/assets/whatsapp-format.js', 'utf8'), { window, document: {
    createElement: tag => new Node(tag), createTextNode: text => Object.assign(new Node(), { textContent: text }),
  } });
  const root = new Node('root'); window.WhatsAppFormat.append(root, text); return root;
}
const all = node => [node, ...node.children.flatMap(all)];
const text = node => node.textContent + node.children.map(text).join('');

test('WhatsApp inline formats support bold, italic, strike, code and nested emphasis', () => {
  const root = format('*Icaro:*\n\nOlá _cliente_ ~antes~ `código` *negrito _itálico_*');
  const nodes = all(root);
  assert.ok(nodes.some(n => n.tag === 'strong' && text(n) === 'Icaro:'));
  assert.ok(nodes.some(n => n.tag === 'em' && text(n) === 'cliente'));
  assert.ok(nodes.some(n => n.tag === 'del' && text(n) === 'antes'));
  assert.ok(nodes.some(n => n.tag === 'code' && text(n) === 'código'));
  assert.ok(nodes.some(n => n.tag === 'strong' && n.children.some(c => c.tag === 'em')));
});
test('WhatsApp lists, quotes and multiline monospace are rendered without interpreting HTML', () => {
  const root = format('- *item um*\n- item dois\n\n1. primeiro\n2. segundo\n> citação\n```\n<b>literal</b>\n```\n<img src=x onerror=alert(1)>');
  const nodes = all(root);
  assert.equal(nodes.filter(n => n.tag === 'li').length, 4);
  assert.ok(nodes.some(n => n.tag === 'blockquote'));
  assert.ok(nodes.some(n => n.tag === 'pre' && text(n) === '<b>literal</b>'));
  assert.ok(text(root).includes('<img src=x onerror=alert(1)>'));
  assert.equal(nodes.filter(n => n.tag === 'img' || n.tag === 'b').length, 0);
});

(() => {
  'use strict';
  // Build DOM nodes, never interpret client messages as HTML.
  function inline(parent, text, depth = 0) {
    if (depth > 5) { parent.append(document.createTextNode(text)); return; }
    const tokens = /(```[\s\S]*?```|`[^`\n]+`|\*[^*\n]+\*|_[^_\n]+_|~[^~\n]+~)/g;
    let position = 0;
    for (const match of text.matchAll(tokens)) {
      parent.append(document.createTextNode(text.slice(position, match.index)));
      const value = match[0]; const marker = value.startsWith('```') ? '```' : value[0];
      const content = value.slice(marker.length, -marker.length);
      const node = document.createElement(({ '*': 'strong', '_': 'em', '~': 'del', '`': 'code', '```': 'code' })[marker]);
      if (marker.startsWith('`')) node.textContent = content; else inline(node, content, depth + 1);
      parent.append(node); position = match.index + value.length;
    }
    parent.append(document.createTextNode(text.slice(position)));
  }
  function append(parent, input) {
    const lines = String(input || '').replace(/\r\n?/g, '\n').split('\n');
    let list = null, listType = null;
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      if (line.startsWith('```')) {
        let code = line.slice(3); const end = code.indexOf('```');
        if (end >= 0) { const node = document.createElement('div'); node.className = 'wa-text-line'; inline(node, line); parent.append(node); list = null; continue; }
        const rest = []; if (code) rest.push(code);
        let j = i + 1; for (; j < lines.length && !lines[j].includes('```'); j++) rest.push(lines[j]);
        if (j < lines.length) { if (lines[j].split('```')[0]) rest.push(lines[j].split('```')[0]); const pre = document.createElement('pre'); const node = document.createElement('code'); node.textContent = rest.join('\n'); pre.append(node); parent.append(pre); i = j; list = null; continue; }
      }
      const bullet = /^[-*] (.*)$/.exec(line), numbered = /^(\d+)\. (.*)$/.exec(line), quote = /^> (.*)$/.exec(line);
      if (bullet || numbered) {
        const type = bullet ? 'ul' : 'ol';
        if (!list || listType !== type) { list = document.createElement(type); listType = type; if (numbered) list.start = Number(numbered[1]); parent.append(list); }
        const item = document.createElement('li'); inline(item, bullet ? bullet[1] : numbered[2]); list.append(item); continue;
      }
      list = null;
      const node = document.createElement(quote ? 'blockquote' : 'div'); node.className = 'wa-text-line';
      if (!line) node.append(document.createElement('br')); else inline(node, quote ? quote[1] : line);
      parent.append(node);
    }
  }
  window.WhatsAppFormat = { append };
})();

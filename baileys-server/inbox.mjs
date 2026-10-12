import { createHash } from 'node:crypto';

const fail = (status, message) => Object.assign(new Error(message), { status });
export class Inbox {
  constructor({ store, normalize, download, logger }) {
    Object.assign(this, { store, normalize, download, logger });
    this.queues = new Map();
  }
  file(key) { return `inbox-${key}.enc`; }
  serial(key, fn) {
    const job = (this.queues.get(key) || Promise.resolve()).then(fn);
    this.queues.set(key, job.catch(() => {}));
    return job;
  }
  async data(key) { return this.store.read(this.file(key), { sequence: 0, events: [], messages: {}, requests: {} }); }
  identity(jid, id) { return createHash('sha256').update(jid + ':' + id).digest('hex'); }
  find(data, key) { return data.messages[this.identity(key.remoteJid, key.id)] || Object.values(data.messages).find(m => m.raw.key.id === key.id && m.raw.key.remoteJid === key.remoteJid); }
  emit(data, event) {
    data.events.push({ ...event, sequence: ++data.sequence });
  }
  async capture(key, messages, historical = false) {
    return this.serial(key, async () => {
      const data = await this.data(key);
      let changed = false;
      for (const raw of messages) {
        const jid = raw.key?.remoteJidAlt?.endsWith('@s.whatsapp.net') ? raw.key.remoteJidAlt : raw.key?.remoteJid;
        if (!jid || !/^[0-9]+@(s\.whatsapp\.net|lid)$/.test(jid) || !raw.key.id || !raw.message) continue;
        const hash = this.identity(jid, raw.key.id);
        const content = this.normalize(raw.message) || {};
        // View-once content remains private to the WhatsApp application.
        if (raw.message.viewOnceMessage || raw.message.viewOnceMessageV2 || raw.message.viewOnceMessageV2Extension) continue;
        const media = content.imageMessage || content.videoMessage || content.audioMessage || content.documentMessage || content.stickerMessage;
        const kind = content.imageMessage ? 'image' : content.videoMessage ? 'video' : content.audioMessage ? 'audio' : content.documentMessage ? 'document' : content.stickerMessage ? 'sticker' : 'text';
        const body = content.conversation || content.extendedTextMessage?.text || media?.caption || content.contactMessage?.displayName || (content.locationMessage ? `Localização: ${content.locationMessage.degreesLatitude}, ${content.locationMessage.degreesLongitude}` : '');
        if (!body && !media) continue;
        const event = { type: 'message', jid, id: raw.key.id, name: raw.key.fromMe ? null : raw.pushName || null,
          phone: (jid.endsWith('@s.whatsapp.net') ? jid.split('@')[0] : null),
          fromMe: !!raw.key.fromMe, kind, body: String(body).slice(0, 8000), historical,
          timestamp: Number(raw.messageTimestamp || Math.floor(Date.now() / 1000)),
          filename: media?.fileName || null, mime: media?.mimetype || null,
          status: raw.key.fromMe ? 'sent' : 'received' };
        if (raw.key.fromMe) event.requestKey = Object.entries(data.requests).find(([, result]) => result.id === raw.key.id)?.[0] || null;
        if (data.messages[hash]) continue;
        data.messages[hash] = { raw, event };
        this.emit(data, event);
        changed = true;
      }
      if (changed) await this.store.write(this.file(key), data);
    });
  }
  async updates(key, updates) {
    return this.serial(key, async () => {
      const data = await this.data(key);
      for (const { key: messageKey, update } of updates) {
        const message = this.find(data, messageKey);
        if (!message) continue;
        if (update.message) {
          const content = this.normalize(update.message) || {};
          const body = content.conversation || content.extendedTextMessage?.text;
          if (body != null) {
            message.raw.message = update.message; message.event.body = String(body).slice(0, 8000);
            this.emit(data, { type: 'edited', jid: message.event.jid, id: message.event.id, body: message.event.body });
          }
        }
        if (!message.event.fromMe || update.status == null) continue;
        const status = Number(update.status) >= 4 ? 'read' : Number(update.status) >= 3 ? 'delivered' : Number(update.status) >= 2 ? 'sent' : null;
        const rank = { sent: 1, delivered: 2, read: 3 };
        if (status && rank[status] > (rank[message.event.status] || 0)) {
          message.event.status = status;
          this.emit(data, { type: 'status', jid: message.event.jid, id: message.event.id, status });
        }
      }
      await this.store.write(this.file(key), data);
    });
  }
  bind(key, socket, current) {
    const guarded = fn => value => { if (current()) fn(value).catch(() => this.logger.error('Inbox persistence failed')); };
    socket.ev.on('messages.upsert', guarded(({ messages, type }) => this.capture(key, messages, type !== 'notify')));
    socket.ev.on('messaging-history.set', guarded(({ messages }) => this.capture(key, messages || [], true)));
    socket.ev.on('messages.update', guarded(updates => this.updates(key, updates)));
    socket.ev.on('messages.delete', guarded(deleted => this.serial(key, async () => {
      const data = await this.data(key);
      for (const item of Object.values(data.messages)) {
        if ((deleted.keys || []).some(k => k.id === item.raw.key.id && k.remoteJid === item.raw.key.remoteJid) || (deleted.all && deleted.jid === item.raw.key.remoteJid)) {
          item.event.body = 'Mensagem apagada no WhatsApp.'; item.event.kind = 'text'; item.raw.message = { conversation: item.event.body };
          this.emit(data, { type: 'deleted', jid: item.event.jid, id: item.event.id });
        }
      }
      await this.store.write(this.file(key), data);
    })));
  }
  async feed(key, after) {
    await this.queues.get(key);
    const data = await this.data(key);
    const events = data.events.filter(e => e.sequence > after).slice(0, 200);
    return { events, cursor: events.at(-1)?.sequence ?? after, more: events.length === 200 };
  }
  async getMessage(key, messageKey) {
    const data = await this.data(key);
    return Object.values(data.messages).find(m => m.raw.key.id === messageKey.id && (m.raw.key.remoteJid === messageKey.remoteJid || m.event.jid === messageKey.remoteJid))?.raw.message;
  }
  async send(key, socket, input) {
    if (!/^[a-f0-9-]{36}$/i.test(input.requestKey || '') || !/^[0-9]+@(s\.whatsapp\.net|lid)$/.test(input.jid || '') || typeof input.text !== 'string' || input.text.length > 8000 || (!input.text.trim() && !input.file)) throw fail(422, 'Invalid chat message');
    let attachment;
    if (input.file) {
      const { mime, base64, name } = input.file;
      if (!/^(image\/(jpeg|png|webp)|application\/pdf|text\/plain|audio\/(mpeg|ogg|mp4)|video\/mp4)$/.test(mime || '') || typeof base64 !== 'string' || base64.length > 11200000) throw fail(422, 'Invalid attachment');
      const bytes = Buffer.from(base64, 'base64');
      if (!bytes.length || bytes.length > 8 * 1024 * 1024) throw fail(422, 'Invalid attachment');
      attachment = mime.startsWith('image/') ? { image: bytes, caption: input.text, mimetype: mime } : { document: bytes, fileName: String(name || 'arquivo').slice(0, 150), mimetype: mime, caption: input.text };
    }
    // Serialize reservation and send; retries use the same durable request key.
    return this.serial(key, async () => {
      const data = await this.data(key);
      if (data.requests[input.requestKey]) return data.requests[input.requestKey];
      if (!socket) throw fail(409, 'Not connected');
      data.requests[input.requestKey] = { status: 'unknown' };
      await this.store.write(this.file(key), data);
      let raw;
      try { raw = await socket.sendMessage(input.jid, attachment || { text: input.text }); }
      catch { return { status: 'unknown' }; }
      const result = { status: 'sent', id: raw.key.id };
      data.requests[input.requestKey] = result;
      await this.store.write(this.file(key), data);
      // The echo event is captured by messages.upsert; capture explicitly after releasing this queue as a fallback.
      queueMicrotask(() => this.capture(key, [raw]).catch(() => this.logger.error('Outgoing capture failed')));
      return result;
    });
  }
  async media(key, socket, jid, id) {
    const data = await this.data(key);
    const message = data.messages[this.identity(jid, id)];
    if (!message || message.event.kind === 'text') throw fail(404, 'Attachment not found');
    const stream = await this.download(message.raw, 'stream', {}, { logger: this.logger, reuploadRequest: socket?.updateMediaMessage });
    let size = 0; const chunks = [];
    for await (const chunk of stream) {
      size += chunk.length;
      if (size > 8 * 1024 * 1024) { stream.destroy(); throw fail(413, 'Attachment exceeds 8 MB'); }
      chunks.push(chunk);
    }
    return { base64: Buffer.concat(chunks).toString('base64'), mime: message.event.mime, name: message.event.filename };
  }
  async read(key, socket, jid) {
    if (!socket) throw fail(409, 'Not connected');
    const data = await this.data(key);
    const keys = Object.values(data.messages).filter(m => m.event.jid === jid && !m.event.fromMe).slice(-100).map(m => m.raw.key);
    if (keys.length) await socket.readMessages(keys);
    return { status: 'ok' };
  }
}

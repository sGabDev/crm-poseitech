export class SessionManager {
  constructor({ store, registry, makeSocket, freshCredentials, appStateKey, disconnectReasons, qrImage, logger, isStopping = () => false }) {
    Object.assign(this, { store, registry, makeSocket, freshCredentials, appStateKey, disconnectReasons, qrImage, logger, isStopping });
    this.sessions = new Map();
  }

  entry(key) {
    if (!this.sessions.has(key)) this.sessions.set(key, {
      status: 'idle', qr: null, phone: null, socket: null, enabled: false,
      generation: 0, retries: 0, timer: null, qrTimer: null,
      lifecycle: Promise.resolve(), tail: Promise.resolve(), startedAt: 0,
    });
    return this.sessions.get(key);
  }

  state(session) {
    return { status: session?.status || 'idle', qr: session?.qr || null, phone: session?.phone || null };
  }

  serial(key, operation) {
    const session = this.entry(key);
    const job = session.lifecycle.then(operation);
    session.lifecycle = job.catch(() => {});
    return job;
  }

  freshAuth() { return { creds: this.freshCredentials(), keys: {} }; }

  dispose(session) {
    session.generation++;
    clearTimeout(session.timer);
    clearTimeout(session.qrTimer);
    session.timer = session.qrTimer = null;
    const socket = session.socket;
    session.socket = null;
    session.qr = null;
    session.phone = null;
    return socket;
  }

  start(key, automatic = false) {
    return this.serial(key, async () => {
      const session = this.entry(key);
      if (this.isStopping() || (automatic && !session.enabled)) return this.state(session);
      const expired = (session.status === 'qr' && !session.qr)
        || (session.status === 'connecting' && Date.now() - session.startedAt > 30000);
      if (session.socket && !expired) return this.state(session);
      if (session.socket) this.dispose(session)?.end(new Error('Restart expired pairing'));
      clearTimeout(session.timer);
      session.enabled = true;
      if (!automatic) session.retries = 0;
      this.registry[key] = true;
      await this.store.write('registry.enc', this.registry);
      const auth = await this.store.read(key + '.enc', this.freshAuth());
      const generation = ++session.generation;
      let socket;
      const current = () => session.generation === generation && session.socket === socket;
      const save = async () => { if (current()) await this.store.write(key + '.enc', auth); };
      const keys = {
        get: async (type, ids) => Object.fromEntries(ids.filter(id => auth.keys[type]?.[id] != null).map(id => [id,
          type === 'app-state-sync-key' ? this.appStateKey(auth.keys[type][id]) : auth.keys[type][id],
        ])),
        set: async data => {
          if (!current()) return;
          for (const [type, values] of Object.entries(data)) {
            auth.keys[type] ??= {};
            for (const [id, value] of Object.entries(values)) {
              if (value == null) delete auth.keys[type][id]; else auth.keys[type][id] = value;
            }
          }
          await save();
        },
      };
      session.status = 'connecting';
      session.qr = null;
      session.startedAt = Date.now();
      try { socket = this.makeSocket({ auth: { creds: auth.creds, keys } }); }
      catch (error) { session.status = 'idle'; throw error; }
      session.socket = socket;
      socket.ev.on('creds.update', updates => {
        this.serial(key, async () => {
          if (!current()) return;
          Object.assign(auth.creds, updates);
          await save();
        }).catch(() => this.logger.error('Credential persistence failed'));
      });
      socket.ev.on('connection.update', update => {
        this.serial(key, async () => {
          if (!current()) return;
          if (update.qr) {
            const qr = await this.qrImage(update.qr);
            if (!current()) return;
            session.qr = qr;
            session.status = 'qr';
            clearTimeout(session.qrTimer);
            session.qrTimer = setTimeout(() => { if (current()) session.qr = null; }, 60000);
          }
          if (update.connection === 'open') {
            session.status = 'connected';
            session.qr = null;
            session.phone = (socket.user?.id || auth.creds.me?.id)?.split(':')[0]?.split('@')[0] || null;
            session.retries = 0;
            clearTimeout(session.qrTimer);
          }
          if (update.connection === 'close') {
            this.dispose(session)?.end(new Error('Connection closed'));
            const code = update.lastDisconnect?.error?.output?.statusCode;
            if ([this.disconnectReasons.loggedOut, this.disconnectReasons.badSession].includes(code)) {
              session.status = 'logged_out';
              session.enabled = false;
              this.registry[key] = false;
              await this.store.write('registry.enc', this.registry);
              await this.store.write(key + '.enc', this.freshAuth());
            } else if (code === this.disconnectReasons.connectionReplaced) {
              session.status = 'logged_out'; session.enabled = false;
              this.registry[key] = false;
              await this.store.write('registry.enc', this.registry);
            } else if (session.enabled && !this.isStopping()) {
              session.status = 'reconnecting';
              const delay = code === this.disconnectReasons.restartRequired ? 0 : Math.min(60000, 1000 * 2 ** Math.min(session.retries++, 6));
              session.timer = setTimeout(() => this.start(key, true).catch(() => this.logger.error('Reconnect failed')), delay);
            } else session.status = 'idle';
          }
        }).catch(() => this.logger.error('Connection update failed'));
      });
      return this.state(session);
    });
  }

  disconnect(key) {
    return this.serial(key, async () => {
      const session = this.entry(key);
      session.enabled = false;
      const socket = this.dispose(session);
      session.status = 'idle';
      this.registry[key] = false;
      await this.store.write('registry.enc', this.registry);
      if (socket) {
        let timeout;
        try {
          await Promise.race([
            socket.logout(),
            new Promise((_, reject) => { timeout = setTimeout(() => reject(new Error('Logout timeout')), 5000); timeout.unref?.(); }),
          ]);
        } catch { /* Local credentials must be reset even if remote logout fails. */ }
        finally { clearTimeout(timeout); socket.end(new Error('Disconnected by administrator')); }
      }
      await this.store.write(key + '.enc', this.freshAuth());
      return this.state(session);
    });
  }

  async shutdown() {
    await Promise.all([...this.sessions.keys()].map(key => this.serial(key, async () => {
      const session = this.entry(key);
      session.enabled = false;
      this.dispose(session)?.end(new Error('Service shutdown'));
    })));
  }
}

import path from 'node:path';
try { process.loadEnvFile(); } catch (error) { if (error.code !== 'ENOENT') throw error; }
process.env.WHATSAPP_DATA_DIR ||= path.resolve('storage/app/private/whatsapp');
await import('../../baileys-server/server.mjs');

import { randomBytes } from 'node:crypto';
import { writeFile } from 'node:fs/promises';

const content = [
  `WHATSAPP_SERVICE_TOKEN=${randomBytes(32).toString('hex')}`,
  `WHATSAPP_SESSION_KEY=${randomBytes(32).toString('hex')}`,
  'WHATSAPP_SERVICE_HOST=127.0.0.1',
  'WHATSAPP_SERVICE_PORT=3100',
  'WHATSAPP_DATA_DIR=./data',
  'WHATSAPP_LOG_LEVEL=warn',
  '',
].join('\n');

try {
  await writeFile(new URL('.env', import.meta.url), content, { flag: 'wx', mode: 0o600 });
  console.log('.env criado com segredos exclusivos. Copie apenas WHATSAPP_SERVICE_TOKEN para o CRM.');
} catch (error) {
  if (error.code !== 'EEXIST') throw error;
  console.error('.env já existe e foi preservado.');
  process.exitCode = 1;
}

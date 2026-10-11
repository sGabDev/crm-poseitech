import lockfile from 'proper-lockfile';
import path from 'node:path';

export async function acquireServiceLock(directory, retries = { retries: 7, factor: 1, minTimeout: 5000, maxTimeout: 5000 }) {
  try {
    return await lockfile.lock(directory, {
      lockfilePath: path.join(directory, '.service-lock'),
      stale: 30000,
      update: 5000,
      retries,
    });
  } catch (error) {
    if (error.code === 'ELOCKED') {
      throw new Error('Outra instância está usando estas sessões. Pare o outro processo antes de iniciar este serviço.', { cause: error });
    }
    throw error;
  }
}

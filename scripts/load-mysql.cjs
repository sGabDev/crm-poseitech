// Four sessions to the configured real server; synthetic session-local tables only.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawn } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'crm-mysql-load-'));
const env = { ...process.env, LOCAL_LOAD_TEST: 'isolated', LOAD_STORAGE: directory,
  APP_CONFIG_CACHE: path.join(directory, 'config.php'), APP_ROUTES_CACHE: path.join(directory, 'routes.php'),
  APP_EVENTS_CACHE: path.join(directory, 'events.php'), APP_SERVICES_CACHE: path.join(directory, 'services.php'), APP_PACKAGES_CACHE: path.join(directory, 'packages.php'),
  SESSION_DRIVER: 'array', CACHE_STORE: 'array', MAIL_MAILER: 'array', LOG_CHANNEL: 'single' };
const started = Date.now();
async function main() {
  for (const folder of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) fs.mkdirSync(path.join(directory, folder), { recursive: true });
  const results = await Promise.allSettled(Array.from({ length: 4 }, (_, worker) => new Promise((resolve, reject) => {
    const child = spawn(php, ['tests/load/mysql-worker.php', String(worker)], { cwd: root, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let errors = '';
    let pending = '';
    child.stdout.on('data', data => {
      pending += data;
      const lines = pending.split('\n'); pending = lines.pop();
      for (const line of lines) {
        try { const progress = JSON.parse(line); if (progress.progress % 10 === 0) console.log(`Company ${progress.progress}/110 completed.`); } catch {}
      }
    });
    child.stderr.on('data', data => { errors = (errors + data).slice(0, 1000); });
    child.on('error', reject);
    child.on('exit', code => {
      const reportPath = path.join(directory, `worker-${worker}.json`);
      if (!fs.existsSync(reportPath)) return reject(new Error(`Worker ${worker} failed before reporting (exit ${code}).`));
      resolve(JSON.parse(fs.readFileSync(reportPath, 'utf8')));
    });
  })));
  const rejected = results.find(result => result.status === 'rejected');
  if (rejected) throw rejected.reason;
  const outcomes = results.map(result => result.value);
  const report = { timestamp: new Date().toISOString(), mode: 'real configured MySQL/MariaDB; session-local temporary table copies; business services, not HTTP', connections: 4, seconds: +((Date.now()-started)/1000).toFixed(2), companies: 0, clients: 0, sales: 0, idempotency_checks: 0, rollback_checks: 0, queries: 0, errors: [], stats: {} };
  const samples = {};
  for (const outcome of outcomes) {
    for (const key of ['companies', 'clients', 'sales', 'idempotency_checks', 'rollback_checks', 'queries']) report[key] += outcome[key];
    report.errors.push(...outcome.errors);
    for (const [key, values] of Object.entries(outcome.samples_ms)) (samples[key] ||= []).push(...values);
  }
  for (const [key, values] of Object.entries(samples)) {
    values.sort((a,b) => a-b);
    report.stats[key] = { count: values.length, p50_ms: Math.round(values[Math.ceil(values.length*.5)-1]), p95_ms: Math.round(values[Math.ceil(values.length*.95)-1]), p99_ms: Math.round(values[Math.ceil(values.length*.99)-1]) };
  }
  fs.mkdirSync(path.join(root, '.runtime'), { recursive: true });
  fs.writeFileSync(path.join(root, '.runtime', 'load-mysql-report.json'), JSON.stringify(report, null, 2)+'\n');
  console.log(JSON.stringify(report, null, 2));
  if (report.errors.length || report.companies !== 110 || report.clients !== 1100 || report.sales !== 110 || report.rollback_checks !== 110) process.exitCode = 1;
}
main().catch(error => { console.error(error.message); process.exitCode = 1; }).finally(() => {
  if (path.dirname(directory) !== path.resolve(os.tmpdir()) || !path.basename(directory).startsWith('crm-mysql-load-')) throw new Error('Unexpected temporary path');
  fs.rmSync(directory, { recursive: true, force: true });
});

// Real HTTP load against disposable local PHP workers; never uses the configured database.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawn, spawnSync } = require('node:child_process');
const net = require('node:net');
const { performance } = require('node:perf_hooks');
const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'crm-load-'));
const env = { ...process.env, LOCAL_LOAD_TEST: 'isolated', LOAD_STORAGE: directory,
  APP_ENV: 'loadtest', APP_DEBUG: 'false', APP_KEY: `base64:${require('node:crypto').randomBytes(32).toString('base64')}`,
  APP_CONFIG_CACHE: path.join(directory, 'config.php'), APP_ROUTES_CACHE: path.join(directory, 'routes.php'),
  APP_EVENTS_CACHE: path.join(directory, 'events.php'), APP_SERVICES_CACHE: path.join(directory, 'services.php'), APP_PACKAGES_CACHE: path.join(directory, 'packages.php'),
  DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(directory, 'load.sqlite'), DB_URL: '',
  SESSION_DRIVER: 'file', SESSION_COOKIE: 'load_session', SESSION_SECURE_COOKIE: 'false', SESSION_DOMAIN: '',
  CACHE_STORE: 'file', MAIL_MAILER: 'array', QUEUE_CONNECTION: 'sync', LOG_CHANNEL: 'single' };
const children = [];
const failures = [];
const measurements = {};
const concurrency = Number(process.env.LOAD_CONCURRENCY || 32);
if (!Number.isInteger(concurrency) || concurrency < 1 || concurrency > 1210) throw new Error('LOAD_CONCURRENCY must be between 1 and 1210.');
function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.on('error', reject);
    server.listen(0, '127.0.0.1', () => { const port = server.address().port; server.close(() => resolve(port)); });
  });
}
async function main() {
  for (const folder of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs', 'app/private']) fs.mkdirSync(path.join(directory, folder), { recursive: true });
  fs.writeFileSync(env.DB_DATABASE, '');
  const fixture = spawnSync(php, ['tests/load/local-fixture.php'], { cwd: root, env, encoding: 'utf8', windowsHide: true });
  if (fixture.status !== 0) throw new Error(fixture.error?.message || String(fixture.stdout || '') + String(fixture.stderr || ''));
  const ports = [];
  for (let i = 0; i < 8; i++) {
    const port = await freePort();
    ports.push(port);
    let workerLog = '';
    const child = spawn(php, ['-S', `127.0.0.1:${port}`, 'tests/load/local-router.php'], { cwd: root, env, windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
    child.stderr.on('data', data => { if (workerLog.length < 16000) workerLog += data; });
    child.on('error', error => failures.push({ worker: i, error: error.message }));
    children.push(child);
    let ready = false;
    for (let attempt = 0; attempt < 20; attempt++) {
      try { if ((await fetch(`http://127.0.0.1:${port}/up`, { signal: AbortSignal.timeout(2000) })).status === 200) { ready = true; break; } } catch {}
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    if (!ready) throw new Error(`Worker ${i} unavailable: ${workerLog}`);
  }
  const actors = JSON.parse(fs.readFileSync(path.join(directory, 'actors.json')));
  for (const [route, actor] of [['/dashboard', actors.companies[0]], ['/portal', actors.clients[0]], ['/catalog/carga-1', actors.clients[0]]]) {
    const probe = await fetch(`http://127.0.0.1:${ports[0]}${route}`, { headers: { Cookie: actor.cookie }, redirect: 'manual' });
    if (probe.status !== 200) throw new Error(`Preflight ${route}: ${probe.status}, location=${probe.headers.get('location')}, body=${(await probe.text()).slice(0, 300)}`);
  }
  const jobs = [];
  for (const actor of actors.companies) for (const route of ['/dashboard', '/records/customers', '/sales']) jobs.push({ actor, route, group: 'empresa', marker: actor.marker });
  for (const actor of actors.clients) {
    jobs.push({ actor, route: '/portal', group: 'portal', marker: actor.marker });
    jobs.push({ actor, route: `/catalog/${actor.slug}`, group: 'catalogo', marker: `Produto${actor.company}` });
  }
  // Interleave companies and customers throughout the run.
  jobs.sort((a, b) => require('node:crypto').createHash('sha256').update(a.route+a.marker).digest('hex').localeCompare(require('node:crypto').createHash('sha256').update(b.route+b.marker).digest('hex')));
  let cursor = 0;
  const started = performance.now();
  console.log(`Testing ${actors.companies.length} companies and ${actors.clients.length} clients; ${jobs.length} requests, concurrency ${concurrency}.`);
  let completed = 0;
  await Promise.all(Array.from({ length: concurrency }, async (_, worker) => {
    while (cursor < jobs.length) {
      const job = jobs[cursor++];
      const time = performance.now();
      try {
        const response = await fetch(`http://127.0.0.1:${ports[worker % ports.length]}${job.route}`, { headers: { Cookie: job.actor.cookie }, redirect: 'manual', signal: AbortSignal.timeout(60000) });
        const body = await response.text();
        const leaked = (job.group === 'portal' && [...body.matchAll(/ClienteCarga\d{4}/g)].some(match => match[0] !== job.marker)) || [...body.matchAll(/EmpresaCarga\d{4}/g)].some(match => match[0] !== (job.actor.company || job.actor.marker));
        if (response.status !== 200 || !body.includes(job.marker) || leaked) failures.push({ route: job.route, actor: job.marker, status: response.status, missingMarker: !body.includes(job.marker), leaked });
      } catch (error) { failures.push({ route: job.route, actor: job.marker, error: error.message }); }
      (measurements[job.group] ||= []).push(performance.now() - time);
      if (++completed % 500 === 0) console.log(`${completed}/${jobs.length} completed; ${failures.length} errors.`);
    }
  }));
  const seconds = (performance.now() - started) / 1000;
  const stats = {};
  for (const [group, values] of Object.entries(measurements)) {
    values.sort((a, b) => a - b);
    stats[group] = { requests: values.length, p50_ms: Math.round(values[Math.ceil(values.length * .5)-1]), p95_ms: Math.round(values[Math.ceil(values.length * .95)-1]), p99_ms: Math.round(values[Math.ceil(values.length * .99)-1]) };
  }
  const report = { timestamp: new Date().toISOString(), environment: 'local PHP built-in server / isolated SQLite WAL', companies: actors.companies.length, clients: actors.clients.length, workers: 8, concurrency, requests: jobs.length, seconds: +seconds.toFixed(2), requests_per_second: +(jobs.length / seconds).toFixed(2), errors: failures.length, stats, failures: failures.slice(0, 30) };
  const destination = path.join(root, '.runtime', 'load-local-report.json');
  fs.mkdirSync(path.dirname(destination), { recursive: true });
  fs.writeFileSync(destination, JSON.stringify(report, null, 2)+'\n');
  console.log(JSON.stringify(report, null, 2));
  if (failures.length) process.exitCode = 1;
}
main().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  await Promise.all(children.map(child => new Promise(resolve => {
    if (child.exitCode !== null) return resolve();
    child.once('exit', resolve);
    child.kill();
  })));
  // directory is the exact, freshly created temporary directory above.
  fs.rmSync(directory, { recursive: true, force: true });
});

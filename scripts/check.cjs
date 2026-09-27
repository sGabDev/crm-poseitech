const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const php = process.env.PHP_BINARY || (process.platform === 'win32' && fs.existsSync('C:/xampp/php/php.exe') ? 'C:/xampp/php/php.exe' : 'php');
function run(command, args) {
  const result = spawnSync(command, args, { encoding: 'utf8', windowsHide: true });
  if (result.status !== 0) { process.stderr.write(result.stderr || result.stdout || String(result.error)); process.exit(1); }
}
function visit(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (entry.isDirectory()) visit(file);
    else if (file.endsWith('.php') && !file.endsWith('.blade.php')) run(php, ['-l', file]);
  }
}
for (const dir of ['app', 'bootstrap', 'config', 'database/migrations', 'database/seeders', 'routes', 'lang', 'tests']) visit(dir);
run(process.execPath, ['--check', 'public/assets/app.js']);
console.log('Sintaxe PHP e JavaScript válida.');
if (process.argv.includes('--test')) {
  const result = spawnSync(php, ['artisan', 'test', '--compact', '--no-ansi'], { stdio: 'inherit', windowsHide: true });
  process.exit(result.status ?? 1);
}

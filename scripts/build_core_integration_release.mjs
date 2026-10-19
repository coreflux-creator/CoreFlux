// Full-Core staging package; preserve the standalone installer as a separate product surface.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';

const root = execFileSync('git', ['rev-parse', '--show-toplevel'], { encoding: 'utf8' }).trim();
const output = process.argv[2];
if (!output || !path.isAbsolute(output) || fs.existsSync(output)
    || path.resolve(output).startsWith(path.resolve(root) + path.sep)) {
  throw new Error('Provide a new absolute package directory outside the checkout.');
}
const commit = execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
const tracked = execFileSync('git', ['ls-files', '-z'], { encoding: 'utf8' }).split('\0').filter(Boolean);
const changed = new Set(execFileSync('git', ['diff', '--name-only', '--diff-filter=ACMRT', 'origin/main', 'HEAD'],
  { encoding: 'utf8' }).trim().split('\n'));
const runtimeRoots = /^(api|core|modules|lib|includes|helpers|config|auth|oauth|partials|views|sim|deploy|tests)\//;
const selected = new Set(tracked.filter(file => {
  if (/\.local\.|(^|\/)\.env|\/node_modules\//.test(file)) return false;
  if (runtimeRoots.test(file) && /\.(php|sql|json)$/.test(file) && !/\/ui\//.test(file)) return true;
  if (/^assets\//.test(file) && /\.(css|js|png|jpg|jpeg|svg|webp|woff2?|ico)$/.test(file)) return true;
  if (!file.includes('/') && changed.has(file) && /\.(php|html)$/.test(file)) return true;
  return ['.htaccess', '.deploy-version', 'composer.json', 'composer.lock',
    'spa-assets/index.html', 'spa-assets/sw.js', 'spa-assets/manifest.webmanifest',
    'scripts/opcache_flush_deploy.php.template'].includes(file);
}));
const dist = path.join(root, 'dashboard/dist');
selected.add('dashboard/dist/index.html');
for (const name of fs.readdirSync(path.join(dist, 'spa-assets'))) {
  if (/^index-[A-Za-z0-9_-]+\.(js|css)$/.test(name)) selected.add('spa-assets/' + name);
}
fs.mkdirSync(output, { recursive: true, mode: 0o700 });
for (const file of [...selected].sort()) {
  const target = path.join(output, file);
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.copyFileSync(path.join(root, file), target);
}
execFileSync('composer', ['install', '--working-dir=' + output, '--no-dev', '--no-interaction',
  '--prefer-dist', '--optimize-autoloader', '--no-scripts', '--no-plugins'], { stdio: 'inherit' });
const files = {};
function walk(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isSymbolicLink()) throw new Error('Package cannot contain links.');
    if (entry.isDirectory()) walk(absolute);
    else files[path.relative(output, absolute).replaceAll(path.sep, '/')] =
      crypto.createHash('sha256').update(fs.readFileSync(absolute)).digest('hex');
  }
}
walk(output);
fs.writeFileSync(path.join(output, 'integration-manifest.json'), JSON.stringify({
  commit, product: 'full-core-staging', files,
}, null, 2) + '\n');
console.log(`Packaged ${Object.keys(files).length} files for full Core at ${commit}.`);

#!/usr/bin/env node
/**
 * Cross-platform dashboard bundle sync.
 *
 * Vite writes dashboard/dist/index.html plus hashed assets under
 * dashboard/dist/spa-assets/. Production serves the top-level index.html,
 * spa.php, and /spa-assets/*. Keep all of those pointers in lockstep so a
 * deploy cannot serve an old or missing JS bundle.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const root = path.resolve(path.dirname(__filename), '..');
const distIndex = path.join(root, 'dashboard', 'dist', 'index.html');
const distAssets = path.join(root, 'dashboard', 'dist', 'spa-assets');
const topAssets = path.join(root, 'spa-assets');
const deployVersion = path.join(root, '.deploy-version');

function fail(message) {
  console.error(`ERROR: ${message}`);
  process.exit(1);
}

if (!fs.existsSync(distIndex)) fail(`${path.relative(root, distIndex)} not found. Run npm --prefix dashboard run build first.`);
if (!fs.existsSync(distAssets)) fail(`${path.relative(root, distAssets)} not found.`);
if (!fs.existsSync(deployVersion)) fail(`${path.relative(root, deployVersion)} missing.`);

const html = fs.readFileSync(distIndex, 'utf8');
const jsMatch = html.match(/index-[A-Za-z0-9_-]+\.js/);
const cssMatch = html.match(/index-[A-Za-z0-9_-]+\.css/);
if (!jsMatch || !cssMatch) fail(`could not find index-*.js / index-*.css references in ${path.relative(root, distIndex)}`);

const newJs = jsMatch[0];
const newCss = cssMatch[0];

console.log('Detected new bundle:');
console.log(`  JS : ${newJs}`);
console.log(`  CSS: ${newCss}`);

fs.mkdirSync(topAssets, { recursive: true });
for (const entry of fs.readdirSync(distAssets)) {
  const src = path.join(distAssets, entry);
  const stat = fs.statSync(src);
  if (stat.isFile()) {
    fs.copyFileSync(src, path.join(topAssets, entry));
  }
}

for (const file of [newJs, newCss]) {
  if (!fs.existsSync(path.join(topAssets, file))) {
    fail(`spa-assets/${file} missing after copy`);
  }
}

fs.copyFileSync(distIndex, path.join(root, 'index.html'));
fs.copyFileSync(distIndex, path.join(topAssets, 'index.html'));

let stamp = fs.readFileSync(deployVersion, 'utf8');
let jsDone = false;
let cssDone = false;
const lines = stamp.split(/\r?\n/);
let inBlock = false;
const rewritten = lines.map((line) => {
  if (line === 'expected_bundle:') {
    inBlock = true;
    return line;
  }
  if (inBlock && /^- spa-assets\/index-.+\.js$/.test(line) && !jsDone) {
    jsDone = true;
    return `- spa-assets/${newJs}`;
  }
  if (inBlock && /^- spa-assets\/index-.+\.css$/.test(line) && !cssDone) {
    cssDone = true;
    return `- spa-assets/${newCss}`;
  }
  if (inBlock && line !== '' && !line.startsWith('- ')) {
    inBlock = false;
  }
  return line;
});

if (!jsDone || !cssDone) fail('expected_bundle block not found or malformed in .deploy-version');
stamp = rewritten.join('\n');
fs.writeFileSync(deployVersion, stamp, 'utf8');

const swVersion = `coreflux-${newJs.replace(/^index-/, '').replace(/\.js$/, '')}`;
for (const sw of [path.join(topAssets, 'sw.js'), path.join(distAssets, 'sw.js')]) {
  if (!fs.existsSync(sw)) continue;
  const current = fs.readFileSync(sw, 'utf8');
  const next = current.replace(/^const CACHE_VERSION = '[^']*';/m, `const CACHE_VERSION = '${swVersion}';`);
  fs.writeFileSync(sw, next, 'utf8');
}

console.log('Synced spa-assets/');
console.log('Synced index.html');
console.log('Synced spa-assets/index.html');
console.log('Updated .deploy-version expected_bundle');
console.log(`Updated service-worker CACHE_VERSION -> ${swVersion}`);

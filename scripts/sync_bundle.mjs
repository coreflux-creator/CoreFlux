#!/usr/bin/env node
/**
 * Cross-platform equivalent of scripts/sync_bundle.sh.
 *
 * Runs after dashboard Vite builds to mirror hashed bundle files into the
 * top-level spa-assets directory and update .deploy-version.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const root = path.resolve(path.dirname(__filename), '..');

const distIndex = path.join(root, 'dashboard', 'dist', 'index.html');
const deployVersion = path.join(root, '.deploy-version');
const distAssets = path.join(root, 'dashboard', 'dist', 'spa-assets');
const topAssets = path.join(root, 'spa-assets');

function fail(message) {
  console.error(`ERROR: ${message}`);
  process.exit(1);
}

if (!fs.existsSync(distIndex)) {
  fail(`${path.relative(root, distIndex)} not found. Run 'npm --prefix dashboard run build' first.`);
}

const indexHtml = fs.readFileSync(distIndex, 'utf8');
const newJs = indexHtml.match(/index-[A-Za-z0-9_-]+\.js/)?.[0];
const newCss = indexHtml.match(/index-[A-Za-z0-9_-]+\.css/)?.[0];
if (!newJs || !newCss) {
  fail(`could not find index-*.js / index-*.css references in ${path.relative(root, distIndex)}`);
}

console.log('Detected new bundle:');
console.log(`  JS : ${newJs}`);
console.log(`  CSS: ${newCss}`);

fs.mkdirSync(topAssets, { recursive: true });
if (fs.existsSync(distAssets)) {
  for (const entry of fs.readdirSync(distAssets)) {
    if (!entry.endsWith('.js') && !entry.endsWith('.css')) continue;
    fs.copyFileSync(path.join(distAssets, entry), path.join(topAssets, entry));
  }
}

if (!fs.existsSync(path.join(topAssets, newJs))) {
  fail(`${path.join('spa-assets', newJs)} missing after copy`);
}
if (!fs.existsSync(path.join(topAssets, newCss))) {
  fail(`${path.join('spa-assets', newCss)} missing after copy`);
}

if (!fs.existsSync(deployVersion)) {
  fail('.deploy-version missing');
}

const deployText = fs.readFileSync(deployVersion, 'utf8');
const lineEnding = deployText.includes('\r\n') ? '\r\n' : '\n';
const lines = deployText.split(/\r?\n/);
let inBlock = false;
let jsDone = false;
let cssDone = false;
const updated = lines.map((line) => {
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
if (!jsDone || !cssDone) {
  fail('expected_bundle block not found or malformed');
}
fs.writeFileSync(deployVersion, updated.join(lineEnding), 'utf8');

console.log('spa-assets/ synced');
console.log('.deploy-version expected_bundle updated');
console.log('dashboard/dist/index.html already points at new hashes');

const swVersion = `coreflux-${newJs.replace(/^index-/, '').replace(/\.js$/, '')}`;
for (const sw of [path.join(topAssets, 'sw.js'), path.join(distAssets, 'sw.js')]) {
  if (!fs.existsSync(sw)) continue;
  const text = fs.readFileSync(sw, 'utf8');
  const next = text.replace(/^const CACHE_VERSION = '[^']*';/m, `const CACHE_VERSION = '${swVersion}';`);
  fs.writeFileSync(sw, next, 'utf8');
}
console.log(`service-worker CACHE_VERSION -> ${swVersion}`);
console.log('');
console.log('All sync points are now consistent.');

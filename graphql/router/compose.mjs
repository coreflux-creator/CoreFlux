/**
 * Compose the supergraph SDL from the two subgraph schemas on disk.
 *
 * Output: ./supergraph.graphql (what the Apollo Router consumes).
 *
 * Why we don't shell out to `rover`: rover is a Rust binary we'd need
 * to install separately on every deploy host. The `@apollo/composition`
 * NPM package gives identical output and lives in node_modules next to
 * the subgraph packages we already build. Same Federation v2 spec.
 */
import { composeServices } from '@apollo/composition';
import { parse } from 'graphql';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

const subgraphs = [
  {
    name: 'coreflux',
    url:  process.env.SUBGRAPH_COREFLUX_URL ?? 'http://localhost:4001/graphql',
    typeDefs: parse(readFileSync(resolve(__dirname, '../subgraph-coreflux/schema.graphql'), 'utf8')),
  },
  {
    name: 'jobdiva',
    url:  process.env.SUBGRAPH_JOBDIVA_URL ?? 'http://localhost:4002/graphql',
    typeDefs: parse(readFileSync(resolve(__dirname, '../subgraph-jobdiva/schema.graphql'), 'utf8')),
  },
];

const result = composeServices(subgraphs);
if (result.errors && result.errors.length > 0) {
  // eslint-disable-next-line no-console
  console.error('Composition failed:');
  for (const err of result.errors) console.error('  - ' + err.message);
  process.exit(1);
}
const sdl = result.supergraphSdl;
if (!sdl) {
  // eslint-disable-next-line no-console
  console.error('Composition produced no SDL');
  process.exit(1);
}
const out = resolve(__dirname, 'supergraph.graphql');
writeFileSync(out, sdl);
// eslint-disable-next-line no-console
console.log(`[compose] wrote ${out} (${sdl.length} bytes)`);

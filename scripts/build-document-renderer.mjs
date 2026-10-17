import { build } from 'esbuild';
import path from 'node:path';

await build({
  entryPoints: ['scripts/document-renderer.tsx'],
  outfile: 'bootstrap/ssr/document-renderer.mjs',
  bundle: true,
  platform: 'node',
  format: 'esm',
  packages: 'external',
  target: 'node20',
  jsx: 'automatic',
  alias: { '@': path.resolve('src') },
  loader: { '.css': 'empty' },
});

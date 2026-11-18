import { build } from 'esbuild';
import { spawnSync } from 'node:child_process';
import { mkdir, rm } from 'node:fs/promises';
const directory = '.local/frontend-regression';
await mkdir(directory, {recursive:true});
const outfile = directory + '/reconstruction.test.mjs';
try {
  await build({entryPoints:['tests/Frontend/reconstruction.test.tsx'],outfile,bundle:true,platform:'node',format:'esm',packages:'external',jsx:'automatic',define:{'import.meta.env':'{}'},loader:{'.css':'empty'},alias:{'@':'./src'}});
  const result=spawnSync(process.execPath,['--test',outfile],{stdio:'inherit'});
  process.exitCode=result.status ?? 1;
} finally { await rm(outfile,{force:true}); }

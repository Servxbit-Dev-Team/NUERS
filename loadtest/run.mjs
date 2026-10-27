// Load driver for POST /api/integrations/transactions (Live keys, source_capture events).
//
//   node loadtest/run.mjs --keys keys.json --url http://127.0.0.1:8090 \
//        --scenario many|single --concurrency 64 --duration 60 [--rate 278] [--warmup 5] [--out result.json]
//
// --rate 0 (default) is closed loop: every worker sends as fast as the server answers, which finds the ceiling.
// --rate N is an open-loop target of N requests per second, capped by --concurrency in flight.
import http from 'node:http';
import { readFileSync, writeFileSync } from 'node:fs';
import { performance } from 'node:perf_hooks';

const a = Object.fromEntries(process.argv.slice(2).reduce((acc, v, i, all) => (v.startsWith('--') ? [...acc, [v.slice(2), all[i + 1]]] : acc), []));
const keys = JSON.parse(readFileSync(a.keys ?? 'loadtest/keys.json', 'utf8'));
const base = new URL(a.url ?? 'http://127.0.0.1:8090');
const scenario = a.scenario ?? 'many';
const concurrency = Number(a.concurrency ?? 64);
const duration = Number(a.duration ?? 60);
const warmup = Number(a.warmup ?? 5);
const rate = Number(a.rate ?? 0);
// --ips N spreads requests over N distinct client IPs via X-Forwarded-For. The app only honours that header from
// trusted proxies, so the test copy must trust 127.0.0.1 (see loadtest/README.md). Default 0 = one IP, which shows the
// 300 requests/minute/IP limit in ApiBoundary.
const ips = Number(a.ips ?? 0);
const fakeIp = (i) => `10.${(i >> 16) & 255}.${(i >> 8) & 255}.${i & 255}`;
const run = Date.now().toString(36);
const agent = new http.Agent({ keepAlive: true, maxSockets: concurrency });

let seq = 0;
let inflight = 0;
const inflightSamples = [];
const lat = [];
const status = {};
const perSecond = new Map();
const errors = [];
const start = performance.now();
const measureFrom = start + warmup * 1000;
const stopAt = start + (warmup + duration) * 1000;

function event(n, k) {
  const ref = `${run}-${n}`;
  return JSON.stringify({
    schema_version: 'NUERS-ingestion-1', record_mode: 'source_capture', source_profile_id: k.profile_id,
    source_system: 'loadtest-pos', source_event_id: `lt-${ref}`, external_reference: `lt-inv-${ref}`,
    event_type: 'invoice.finalized', occurred_at: new Date(Date.now() - 60000).toISOString().replace(/\.\d+Z$/, '+00:00'),
    branch_code: '00000', receipt_number: `LT-${ref}`, document_type: 'vat_invoice', payment_method: 'cash',
    gross_amount: '560.00', tax_type: 'vatable', vat_inclusive: true,
    items: [{ description: 'Synthetic load item', quantity: 1, unit_price: '560.00', tax_type: 'vatable' }],
  });
}

function send(n) {
  const k = scenario === 'single' ? keys[0] : keys[n % keys.length];
  const body = event(n, k);
  return new Promise((resolve) => {
    const t0 = performance.now();
    inflight++;
    const req = http.request({ agent, hostname: base.hostname, port: base.port, path: '/api/integrations/transactions', method: 'POST',
      headers: { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body), 'x-nuers-api-key': k.key, accept: 'application/json', ...(ips > 0 ? { 'x-forwarded-for': fakeIp(n % ips) } : {}) } }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        inflight--;
        const t1 = performance.now();
        if (t0 >= measureFrom && t0 < stopAt) {
          lat.push(t1 - t0);
          status[res.statusCode] = (status[res.statusCode] ?? 0) + 1;
          const s = Math.floor((t1 - measureFrom) / 1000);
          perSecond.set(s, (perSecond.get(s) ?? 0) + 1);
          if (res.statusCode >= 300 && errors.length < 5) errors.push({ status: res.statusCode, body: Buffer.concat(chunks).toString().slice(0, 300) });
        }
        resolve();
      });
    });
    req.on('error', (e) => { inflight--; if (t0 >= measureFrom && t0 < stopAt) { status.error = (status.error ?? 0) + 1; if (errors.length < 5) errors.push({ error: e.code ?? e.message }); } resolve(); });
    req.end(body);
  });
}

async function worker() {
  while (performance.now() < stopAt) {
    if (rate > 0) {
      const due = start + (seq / rate) * 1000;
      const wait = due - performance.now();
      if (wait > 1) await new Promise((r) => setTimeout(r, wait));
    }
    await send(seq++);
  }
}

const sampler = setInterval(() => { if (performance.now() >= measureFrom && performance.now() < stopAt) inflightSamples.push(inflight); }, 100);
console.error(`scenario=${scenario} merchants=${scenario === 'single' ? 1 : keys.length} ips=${ips || 1} concurrency=${concurrency} rate=${rate || 'max'} warmup=${warmup}s duration=${duration}s`);
await Promise.all(Array.from({ length: concurrency }, worker));

clearInterval(sampler);
lat.sort((x, y) => x - y);
const pct = (p) => (lat.length ? Number(lat[Math.min(lat.length - 1, Math.floor((p / 100) * lat.length))].toFixed(1)) : null);
const ok = (status[201] ?? 0) + (status[200] ?? 0);
const secs = [...perSecond.values()];
const result = {
  scenario, merchants: scenario === 'single' ? 1 : keys.length, concurrency, target_rate: rate || 'max', duration_s: duration,
  requests: lat.length, accepted: ok, status, accepted_per_s: Number((ok / duration).toFixed(1)), accepted_per_hour_equivalent: Math.round((ok / duration) * 3600),
  latency_ms: { p50: pct(50), p95: pct(95), p99: pct(99), max: lat.length ? Number(lat[lat.length - 1].toFixed(1)) : null },
  avg_in_flight: Number((inflightSamples.reduce((x, y) => x + y, 0) / Math.max(1, inflightSamples.length)).toFixed(1)),
  per_second: { min: secs.length ? Math.min(...secs) : 0, max: secs.length ? Math.max(...secs) : 0 }, first_errors: errors,
};
console.log(JSON.stringify(result, null, 2));
if (a.out) writeFileSync(a.out, JSON.stringify(result, null, 2));
process.exit(0);

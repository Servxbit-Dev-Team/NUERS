// Concurrency checks for the Live intake path: do the guarantees the per-merchant lock provides still hold when
// requests race? Run against the throwaway MySQL only (setup/verify scripts refuse anything else).
//
//   node loadtest/race.mjs --url http://127.0.0.1:8090
//
// Needs the same environment as the rest of the harness (APP_KEY, DB_*, NUERS_LOADTEST=1) and a running serve.ps1.
// Each scenario creates a fresh merchant, fires requests together, then reads the database back.
import http from 'node:http';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const a = Object.fromEntries(process.argv.slice(2).reduce((acc, v, i, all) => (v.startsWith('--') ? [...acc, [v.slice(2), all[i + 1]]] : acc), []));
const base = new URL(a.url ?? 'http://127.0.0.1:8090');
const agent = new http.Agent({ keepAlive: true, maxSockets: 512 });
const tmp = mkdtempSync(join(tmpdir(), 'nuers-race-'));
const run = Date.now().toString(36);
let seq = 0;

const php = (...args) => execFileSync('php', args, { encoding: 'utf8', env: process.env });
function newMerchant(pesos) {
  const out = join(tmp, `m${seq++}.json`);
  php('loadtest/race-setup.php', String(pesos), out);
  return JSON.parse(readFileSync(out, 'utf8'))[0];
}
const verify = (m, prefix = '') => JSON.parse(php('loadtest/race-verify.php', m.merchant_id, prefix));

function body(m, { id, number, amount = '560.00' }) {
  return {
    schema_version: 'NUERS-ingestion-1', record_mode: 'source_capture', source_profile_id: m.profile_id, source_system: 'loadtest-pos',
    source_event_id: `race-${run}-${id}`, external_reference: `race-inv-${run}-${id}`, event_type: 'invoice.finalized',
    occurred_at: new Date(Date.now() - 60000).toISOString().replace(/\.\d+Z$/, '+00:00'), branch_code: '00000',
    receipt_number: number ?? `RACE-${run}-${id}`.toUpperCase(), document_type: 'vat_invoice', payment_method: 'cash',
    gross_amount: amount, tax_type: 'vatable', vat_inclusive: true,
    items: [{ description: 'Synthetic race item', quantity: 1, unit_price: amount, tax_type: 'vatable' }],
  };
}

function send(m, payload) {
  const data = JSON.stringify(payload);
  return new Promise((resolve) => {
    const req = http.request({ agent, hostname: base.hostname, port: base.port, path: '/api/integrations/transactions', method: 'POST',
      headers: { 'content-type': 'application/json', 'content-length': Buffer.byteLength(data), 'x-nuers-api-key': m.key, accept: 'application/json' } }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        let json = null;
        try { json = JSON.parse(Buffer.concat(chunks).toString()); } catch { /* keep null */ }
        resolve({ status: res.statusCode, json });
      });
    });
    req.on('error', (e) => resolve({ status: 0, error: e.code }));
    req.end(data);
  });
}

// Fire all payloads at once, in waves no larger than `wave` so the harness (12 php-cgi workers) is not asked for more sockets than it can serve.
async function race(m, payloads, wave = payloads.length) {
  const results = [];
  for (let i = 0; i < payloads.length; i += wave) {
    results.push(...(await Promise.all(payloads.slice(i, i + wave).map((p) => send(m, p)))));
  }
  return results;
}
const tally = (rs) => rs.reduce((t, r) => ((t[r.status] = (t[r.status] ?? 0) + 1), t), {});
const failures = [];
const check = (scenario, name, ok, detail) => {
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${name}${ok ? '' : `  (${detail})`}`);
  if (!ok) failures.push(`${scenario}: ${name}`);
};
const header = (t) => console.log(`\n${t}`);

// R1: the same event sent 20 times at once.
header('R1  identical event x20 at once (idempotency)');
{
  const m = newMerchant(1000);
  const p = body(m, { id: 'r1' });
  const rs = await race(m, Array.from({ length: 20 }, () => p));
  const t = tally(rs);
  const dup = rs.filter((r) => r.status === 200 && r.json?.duplicate === true).length;
  const v = verify(m);
  console.log(`  responses: ${JSON.stringify(t)}`);
  check('R1', 'exactly one 201', t[201] === 1, JSON.stringify(t));
  check('R1', 'every other response is a 200 replay marked duplicate', dup === 19, `duplicates=${dup}`);
  check('R1', 'no 5xx or transport errors', !Object.keys(t).some((s) => s === '0' || Number(s) >= 500), JSON.stringify(t));
  check('R1', 'one inbox row, one receipt, one fee charged', v.inbox === 1 && v.receipts === 1 && v.fee_entries === 1, JSON.stringify(v));
}

// R2: 20 different events that all claim the same document number.
header('R2  20 different events, same document number, at once');
{
  const m = newMerchant(1000);
  const number = `RACE-SAME-${run}`.toUpperCase();
  const rs = await race(m, Array.from({ length: 20 }, (_, i) => body(m, { id: `r2-${i}`, number })));
  const t = tally(rs);
  const v = verify(m, 'RACE-SAME');
  console.log(`  responses: ${JSON.stringify(t)}`);
  check('R2', 'exactly one 201', t[201] === 1, JSON.stringify(t));
  check('R2', 'all others rejected with 409', t[409] === 19, JSON.stringify(t));
  check('R2', 'the number exists once', v.receipts === 1 && v.duplicate_receipt_numbers === 0, JSON.stringify(v));
  check('R2', 'only the accepted event was charged', v.fee_entries === 1, JSON.stringify(v));
}

// R3: one event identity, different content, at once.
header('R3  same event id with 10 different amounts at once');
{
  const m = newMerchant(1000);
  const rs = await race(m, Array.from({ length: 10 }, (_, i) => body(m, { id: 'r3', number: `RACE-R3-${run}`.toUpperCase(), amount: `${100 + i}.00` })));
  const t = tally(rs);
  const v = verify(m);
  console.log(`  responses: ${JSON.stringify(t)}`);
  check('R3', 'exactly one 201', t[201] === 1, JSON.stringify(t));
  check('R3', 'the rest are 409 (changed content) and nothing else', t[409] === 9, JSON.stringify(t));
  check('R3', 'one inbox row and one charge', v.inbox === 1 && v.fee_entries === 1, JSON.stringify(v));
}

// R4: many distinct events on one merchant: wallet ledger exactness.
let fee = null;
header('R4  200 distinct events on one merchant, 40 at a time (wallet ledger exactness)');
{
  const m = newMerchant(100000);
  const rs = await race(m, Array.from({ length: 200 }, (_, i) => body(m, { id: `r4-${i}` })), 40);
  const t = tally(rs);
  const v = verify(m);
  fee = v.fee_entries ? v.fees_charged_centavos / v.fee_entries : null;
  console.log(`  responses: ${JSON.stringify(t)}   fee per sale: ${fee} centavos`);
  check('R4', 'all 200 accepted', t[201] === 200, JSON.stringify(t));
  check('R4', 'one fee entry per accepted event', v.fee_entries === 200 && v.inbox === 200 && v.receipts === 200, JSON.stringify(v));
  check('R4', 'wallet hash chain valid, sequence gapless, balance equals the sum of entries', v.wallet_chain_valid === true, JSON.stringify(v));
  check('R4', 'balance = funding - fees', v.wallet_available_centavos === 100000 * 100 - v.fees_charged_centavos, JSON.stringify(v));
  check('R4', 'audit hash chain intact', v.audit_chain_intact === true, JSON.stringify(v));
}

// R5: a wallet that can pay for exactly K sales receives 3K at once.
header('R5  wallet funded for exactly 10 sales, 30 distinct events at once');
if (fee && fee % 100 === 0) {
  const K = 10;
  const m = newMerchant((K * fee) / 100);
  const rs = await race(m, Array.from({ length: 30 }, (_, i) => body(m, { id: `r5-${i}` })));
  const t = tally(rs);
  const v = verify(m);
  console.log(`  responses: ${JSON.stringify(t)}`);
  check('R5', `exactly ${K} accepted`, t[201] === K, JSON.stringify(t));
  check('R5', `the other ${30 - K} refused with 402 and nothing else`, t[402] === 30 - K, JSON.stringify(t));
  check('R5', 'wallet is empty, never negative', v.wallet_available_centavos === 0 && v.wallet_min_balance_after >= 0, JSON.stringify(v));
  check('R5', 'refused events left no records', v.inbox === K && v.receipts === K && v.fee_entries === K, JSON.stringify(v));
  check('R5', 'wallet chain valid', v.wallet_chain_valid === true, JSON.stringify(v));
} else {
  console.log(`  SKIPPED: fee per sale (${fee}) is not a whole number of pesos, so a wallet for exactly K sales cannot be created with race-setup.php`);
}

console.log(failures.length ? `\nFAILED (${failures.length}):\n  ${failures.join('\n  ')}` : '\nAll race checks passed.');
process.exit(failures.length ? 1 : 0);

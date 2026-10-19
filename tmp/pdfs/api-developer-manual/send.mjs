// send.mjs - no external packages
import { readFile } from 'node:fs/promises';
const base = 'https://nuers.net/api/integrations/transactions';
const key = process.env.NUERS_API_KEY;
if (!key) throw new Error('NUERS_API_KEY is required');
const raw = await readFile('event.json', 'utf8');
const event = JSON.parse(raw);

async function request(path, method = 'GET', rawBody) {
  const headers = {
    Accept: 'application/json',
    'X-NUERS-API-Key': key
  };
  if (rawBody !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  const response = await fetch(base + path, {
    method, headers, body: rawBody,
    signal: AbortSignal.timeout(30000),
    redirect: 'error'
  });
  const text = await response.text();
  let body;
  try { body = JSON.parse(text); }
  catch { throw new Error('Unverifiable response; keep pending'); }
  return { http: response.status, body,
    retryAfter: response.headers.get('retry-after') };
}

const result = await request('', 'POST', raw);
// Continue send.mjs
function durable(e, b) {
  return b && typeof b === 'object' &&
    b.status === 'durably_recorded' &&
    b.environment === process.env.NUERS_ENVIRONMENT &&
    b.source_event_id === e.source_event_id &&
    b.external_reference === e.external_reference &&
    typeof b.id === 'string' && b.id.trim().length > 0 &&
    typeof b.receipt_id === 'string' &&
    b.receipt_id.trim().length > 0;
}
if ([200, 201].includes(result.http) &&
    durable(event, result.body)) {
  // In ONE local DB transaction:
  // save IDs + mark this original outbox row ACCEPTED.
  console.log(JSON.stringify({
    accepted: true, id: result.body.id,
    receipt_id: result.body.receipt_id,
    duplicate: result.body.duplicate
  }));
} else {
  // Apply the state machine on page 25.
  // Store only sanitized HTTP/error metadata in logs.
  throw new Error(`Keep pending/review: HTTP ${result.http}`);
}

// To retrieve later, use the stored inbox ID:
// const state = await request('/' + encodeURIComponent(id));
// To verify key scope and current pricing:
// const wallet = await request('/wallet');

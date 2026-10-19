export async function sendNuers(savedJson) {
  const event = JSON.parse(savedJson);
  const key = process.env.NUERS_API_KEY;
  const env = process.env.NUERS_ENVIRONMENT;
  if (!key || !['live', 'sandbox'].includes(env)) {
    throw new Error('Missing private connector configuration');
  }
  const url =
    'https://nuers.net/api/integrations/transactions';
  const res = await fetch(url, {
    method: 'POST', redirect: 'error',
    signal: AbortSignal.timeout(45000),
    headers: {
      'X-NUERS-API-Key': key,
      'Content-Type': 'application/json',
      'Accept': 'application/json'
    },
    body: savedJson
  });
  const body = await res.json().catch(() => null);
  const accepted = res.status === 201 ||
    (res.status === 200 && body?.duplicate === true);
  const valid = accepted && body?.id && body?.receipt_id &&
    body?.transaction_id && body.environment === env &&
    body.status === 'durably_recorded' &&
    body.nuers_status === 'success' &&
    body.source_event_id === event.source_event_id &&
    body.external_reference === event.external_reference;
  if (!valid) {
    const err = new Error('NUERS response needs review/retry');
    err.status = res.status;
    err.retryAfter = res.headers.get('retry-after');
    // Redact personal data before logging field-level errors.
    throw err;
  }
  return body; // Caller commits IDs + delivered state locally.
}

/** Connector UI adapter. Run on your backend using a saved event and trusted response.
 * Store API secrets server-side. Render label/reason with textContent, never innerHTML.
 * Persist acknowledged IDs and local outbox state together in your own database.
 */
export function nuersSalesStatus(event, environment, httpStatus, body) {
  const matching = body && body.source_event_id === event.source_event_id &&
    body.external_reference === event.external_reference && body.environment === environment;
  const accepted = (httpStatus === 201 || (httpStatus === 200 && body?.duplicate === true)) &&
    matching && body.nuers_status === 'success' && body.status === 'durably_recorded' &&
    ['id', 'transaction_id', 'receipt_id'].every(k => typeof body[k] === 'string' && body[k].trim());
  if (accepted) return {label: 'Success', reason: body.message, reference: body.id,
    transactionId: body.transaction_id, receiptId: body.receipt_id, statusUrl: body.status_url,
    environment, emailStatus: body.customer_receipt?.status ?? 'not_requested', acknowledged: true};
  if (!httpStatus || httpStatus >= 500 || httpStatus === 408 || httpStatus < 400 ||
      !body || body.error?.outcome === 'unknown' || body.nuers_status !== 'error') {
    return {label: 'Pending confirmation', reason: 'Keep the original sale and retry unchanged.', environment, acknowledged: false};
  }
  return {label: 'Error', reason: String(body.error?.reason || body.message || 'Request rejected'),
    fieldErrors: body.errors ?? {}, code: body.error?.code || body.code, environment, acknowledged: false};
}

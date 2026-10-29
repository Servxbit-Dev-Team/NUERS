export class ApiRateLimitError extends Error {
  readonly status = 429;
  retryAfterSeconds: number;
  constructor(message: string, retryAfterSeconds: number) {
    super(message); this.name = 'ApiRateLimitError'; this.retryAfterSeconds = retryAfterSeconds;
  }
}
export function rateLimitError(response: Response, payload: {message?: unknown; retry_after?: unknown}, now = Date.now()) {
  const header = response.headers.get('Retry-After');
  const seconds = header && /^\d+$/.test(header.trim()) ? Number(header) : header ? Math.ceil((Date.parse(header) - now) / 1000) : Number(payload?.retry_after);
  const retry = Number.isFinite(seconds) ? Math.max(0, Math.min(86400, Math.ceil(seconds))) : 0;
  const original = typeof payload?.message === 'string' ? payload.message.trim() : '';
  const generic = !original || /^too many (?:attempts|requests)\.?$/i.test(original);
  const wait = retry ? `Please wait ${retry} seconds before trying this action again.` : 'Please wait before trying this action again.';
  return new ApiRateLimitError(generic ? wait : `${original}${retry && !/\d+\s+seconds?/.test(original) ? ` Try again in ${retry} seconds.` : ''}`, retry);
}
export function retrySeconds(deadline: number, now = Date.now()) { return Math.max(0, Math.ceil((deadline - now) / 1000)); }

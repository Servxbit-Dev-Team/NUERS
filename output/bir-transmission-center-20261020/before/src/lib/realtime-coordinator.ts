/**
 * One refresh clock per browser tab. All visible widgets join the same batch;
 * the next batch waits for both background and user-initiated API requests.
 * Slow queries never accumulate a queue of one-second requests.
 */
export const REALTIME_INTERVAL_MS = 1000;

export type RefreshListener = () => void | Promise<unknown>;
export class RealtimeCoordinator {
  private listeners = new Set<RefreshListener>();
  private requests = 0;
  private running = false;
  private retryAt = 0;
  private failures = 0;
  private refreshRequired = false;
  private now: () => number;
  probe?: () => Promise<boolean>;

  constructor(now: () => number = Date.now) { this.now = now; }

  subscribe(listener: RefreshListener) {
    this.listeners.add(listener);
    return () => { this.listeners.delete(listener); };
  }

  invalidate() { this.refreshRequired = true; }

  beginRequest() {
    this.requests++;
    let complete = false;
    return (status = 200, retryAfter = 0) => {
      if (complete) return;
      complete = true;
      this.requests = Math.max(0, this.requests - 1);
      if (status === 0 || status === 429 || status >= 500) {
        this.refreshRequired = true;
        this.failures = Math.min(this.failures + 1, 6);
        this.retryAt = Math.max(this.retryAt, this.now() + Math.max(retryAfter, Math.min(60, 2 ** this.failures)) * 1000);
      }
    };
  }

  async tick(available = true) {
    if (!available || this.running || this.requests || this.now() < this.retryAt || !this.listeners.size) return;
    this.running = true;
    const previousRetry = this.retryAt;
    try {
      if (this.probe && !await this.probe() && !this.refreshRequired) return;
      this.refreshRequired = false;
      await Promise.allSettled([...this.listeners].map(listener => Promise.resolve().then(listener)));
      if (this.retryAt === previousRetry && !this.requests) this.failures = 0;
    } catch {
      // Transport records the retry deadline. A failed heartbeat never starts reads.
    } finally {
      this.running = false;
    }
  }
}

export const realtime = new RealtimeCoordinator();

import { useEffect, useRef } from "react";
import { REALTIME_INTERVAL_MS, realtime, type RefreshListener } from "./realtime-coordinator";
import { apiJson } from "./api-url";

let subscribers = 0;
let timer: ReturnType<typeof setInterval> | undefined;
const tick = () => void realtime.tick(document.visibilityState === "visible" && navigator.onLine);
let revision = "";
realtime.probe = async () => {
  const result = await apiJson<{ revision: string }>("/api/dashboard-revision", { timeoutMs: 10000 });
  const changed = revision !== result.revision;
  revision = result.revision;
  return changed;
};

export function subscribeRealtimeRefresh(refresh: RefreshListener) {
  const unsubscribe = realtime.subscribe(refresh);
  if (++subscribers === 1) {
    revision = "";
    timer = setInterval(tick, REALTIME_INTERVAL_MS);
    document.addEventListener("visibilitychange", tick);
    window.addEventListener("online", tick);
  }
  return () => {
    unsubscribe();
    if (--subscribers === 0) {
      clearInterval(timer);
      document.removeEventListener("visibilitychange", tick);
      window.removeEventListener("online", tick);
    }
  };
}

/** Background reads only. Initial loading, filters and manual actions stay explicit. */
export function useRealtimeRefresh(refresh: RefreshListener, enabled = true) {
  const latest = useRef({ refresh, enabled });
  if (enabled && !latest.current.enabled) realtime.invalidate();
  latest.current = { refresh, enabled };
  useEffect(() => {
    return subscribeRealtimeRefresh(() => {
      if (latest.current.enabled) return latest.current.refresh();
    });
  }, []);
}

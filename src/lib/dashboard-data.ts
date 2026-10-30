import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useCallback, useEffect, useRef, useState } from "react";
import { apiFetch, readJsonResponse } from "@/lib/api-url";

export type DashboardKpi = {
  key: string;
  label: string;
  value: string;
  subtext: string | null;
  icon: string | null;
  accent: string | null;
};

export type DashboardSeriesPoint = {
  label: string;
  [key: string]: string | number;
};

export type DashboardListItem = {
  title: string;
  subtitle: string | null;
  status: string | null;
  badge: string | null;
  primary_value: number | null;
  secondary_value: number | null;
  metadata: Record<string, string | number | boolean | null>;
};

export type DashboardPayload = {
  portal: string;
  kpis: DashboardKpi[];
  series: Record<string, DashboardSeriesPoint[]>;
  lists: Record<string, DashboardListItem[]>;
};

const emptyDashboard: DashboardPayload = {
  portal: "",
  kpis: [],
  series: {},
  lists: {},
};

const DASHBOARD_BROWSER_CACHE_TTL_MS = 120_000;

type CachedDashboardPayload = {
  payload: DashboardPayload;
  cachedAt: string;
};

function dashboardCacheKey(portal: string) {
  let scope = "anonymous";

  try {
    const token =
      typeof window !== "undefined" ? window.localStorage.getItem("nuers_api_token") : null;
    if (token) scope = token.slice(-16);
  } catch {
    // Restricted browser storage should not block dashboard rendering.
  }

  return `nuers_dashboard_${portal}_${scope}`;
}

function readCachedDashboard(portal: string): CachedDashboardPayload | null {
  try {
    if (typeof window === "undefined") return null;

    const raw = window.sessionStorage.getItem(dashboardCacheKey(portal));
    if (!raw) return null;

    const cached = JSON.parse(raw) as CachedDashboardPayload;
    const cachedAt = Date.parse(cached.cachedAt);

    if (
      !cached.payload ||
      Number.isNaN(cachedAt) ||
      Date.now() - cachedAt > DASHBOARD_BROWSER_CACHE_TTL_MS
    ) {
      window.sessionStorage.removeItem(dashboardCacheKey(portal));
      return null;
    }

    return cached;
  } catch {
    return null;
  }
}

function writeCachedDashboard(portal: string, payload: DashboardPayload) {
  try {
    if (typeof window === "undefined") return null;

    const cachedAt = new Date().toISOString();
    window.sessionStorage.setItem(dashboardCacheKey(portal), JSON.stringify({ payload, cachedAt }));

    return cachedAt;
  } catch {
    return null;
  }
}

function authHeaders() {
  const headers: Record<string, string> = {
    Accept: "application/json",
  };

  try {
    const token =
      typeof window !== "undefined" ? window.localStorage.getItem("nuers_api_token") : null;
    if (token) headers.Authorization = `Bearer ${token}`;
  } catch {
    // Restricted browser storage should not block dashboard rendering.
  }

  return headers;
}

type DashboardDataOptions = {
  refreshIntervalMs?: number;
};

export function useDashboardData(
  portal: "super-admin" | "bir" | "merchant" | "client",
  options: DashboardDataOptions = {},
) {
  const cachedDashboard = useRef<CachedDashboardPayload | null>(readCachedDashboard(portal));
  const [data, setData] = useState<DashboardPayload>(
    cachedDashboard.current?.payload ?? emptyDashboard,
  );
  const [loading, setLoading] = useState(!cachedDashboard.current);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [lastUpdatedAt, setLastUpdatedAt] = useState<string | null>(
    cachedDashboard.current?.cachedAt ?? null,
  );
  const hasLoaded = useRef(Boolean(cachedDashboard.current));
  const activePortal = useRef(portal);
  const refreshIntervalMs = options.refreshIntervalMs ?? 1000;

  const reload = useCallback(() => {
    setRefreshKey((value) => value + 1);
  }, []);

  useEffect(() => {
    let cancelled = false;
    let stop: (() => void) | undefined;

    if (activePortal.current !== portal) {
      activePortal.current = portal;
      cachedDashboard.current = readCachedDashboard(portal);
      hasLoaded.current = Boolean(cachedDashboard.current);
      setData(cachedDashboard.current?.payload ?? { ...emptyDashboard, portal });
      setLastUpdatedAt(cachedDashboard.current?.cachedAt ?? null);
    }

    async function loadDashboard(quiet = false) {
      const blockingLoad = !hasLoaded.current;
      if (blockingLoad) {
        setLoading(true);
      } else if (!quiet) {
        setRefreshing(true);
      }
      setError(null);

      try {
        const query =
          !blockingLoad && (refreshKey > 0 || refreshIntervalMs > 0)
            ? `?refresh=${Date.now()}`
            : "";
        const response = await apiFetch(`/api/dashboards/${portal}${query}`, {
          headers: authHeaders(),
        });
        const payload = await readJsonResponse<DashboardPayload>(
          response,
          "Unable to load dashboard data.",
        );

        if (!cancelled) {
          setData(payload);
          setLastUpdatedAt(writeCachedDashboard(portal, payload) ?? new Date().toISOString());
          hasLoaded.current = true;
        }
      } catch (err) {
        if (!cancelled) {
          if (blockingLoad) setData({ ...emptyDashboard, portal });
          setError(err instanceof Error ? err.message : "Unable to load dashboard data.");
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
          setRefreshing(false);
        }
      }
    }

    loadDashboard();

    if (refreshIntervalMs > 0) {
      stop = subscribeRealtimeRefresh(() => loadDashboard(true));
    }

    return () => {
      cancelled = true;
      stop?.();
    };
  }, [portal, refreshKey, refreshIntervalMs]);

  return { data, loading, refreshing, error, reload, lastUpdatedAt };
}

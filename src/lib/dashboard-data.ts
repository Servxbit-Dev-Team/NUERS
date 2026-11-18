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
  // Financial dashboards stay in mounted component memory, never browser storage.
  const [data, setData] = useState<DashboardPayload>({ ...emptyDashboard, portal });
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshKey, setRefreshKey] = useState(0);
  const [lastUpdatedAt, setLastUpdatedAt] = useState<string | null>(null);
  const hasLoaded = useRef(false);
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
      hasLoaded.current = false;
      setData({ ...emptyDashboard, portal });
      setLastUpdatedAt(null);
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
          setLastUpdatedAt(new Date().toISOString());
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

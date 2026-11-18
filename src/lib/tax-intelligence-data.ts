import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useEffect, useState } from "react";
import { apiFetch, readJsonResponse } from "@/lib/api-url";

export type TaxScope = "merchant" | "bir" | "super-admin";

export type TaxKpi = {
  key: string;
  label: string;
  value: string;
  subtext: string | null;
  icon: string | null;
  accent: string | null;
};

export type TaxSeriesPoint = {
  label: string;
  [key: string]: string | number;
};

export type TaxRecord = {
  reference: string;
  party_name: string;
  counterparty_name: string | null;
  tin: string | null;
  amount: number;
  vat_amount: number;
  withholding_amount: number | null;
  status: string;
  risk_level: string | null;
  score: number | null;
  metadata: Record<string, string | number | boolean | null>;
};

export type TaxGraphEdge = {
  source: string;
  target: string;
  relationship: string;
  risk_level: string | null;
  volume: number;
  metadata: Record<string, string | number | boolean | null>;
};

export type TaxIntelligencePayload = {
  scope: TaxScope | "";
  notice?: string;
  reporting_state?: string;
  kpis: TaxKpi[];
  series: Record<string, TaxSeriesPoint[]>;
  records: Record<string, TaxRecord[]>;
  graph: TaxGraphEdge[];
};

const emptyPayload: TaxIntelligencePayload = {
  scope: "",
  kpis: [],
  series: {},
  records: {},
  graph: [],
};

function authHeaders() {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Cache-Control": "no-store",
  };

  try {
    const token =
      typeof window !== "undefined" ? window.localStorage.getItem("nuers_api_token") : null;
    if (token) headers.Authorization = `Bearer ${token}`;
  } catch {
    // Restricted browser storage should not block tax dashboard rendering.
  }

  return headers;
}

export function useTaxIntelligenceData(scope: TaxScope) {
  const [data, setData] = useState<TaxIntelligencePayload>(emptyPayload);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function loadTaxIntelligence(quiet = false) {
      if (!quiet) setLoading(true);
      setError(null);

      try {
        const response = await apiFetch(`/api/tax-intelligence/${scope}?_=${Date.now()}`, {
          cache: "no-store",
          headers: authHeaders(),
        });
        const payload = await readJsonResponse<TaxIntelligencePayload>(
          response,
          "Unable to load tax intelligence data.",
        );

        if (!cancelled) setData(payload);
      } catch (err) {
        if (!cancelled) {
          if (!quiet) setData({ ...emptyPayload, scope });
          setError(err instanceof Error ? err.message : "Unable to load tax intelligence data.");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    loadTaxIntelligence();
    const stop = subscribeRealtimeRefresh(() => loadTaxIntelligence(true));
    return () => {
      stop();
      cancelled = true;
    };
  }, [scope]);

  return { data, loading, error };
}

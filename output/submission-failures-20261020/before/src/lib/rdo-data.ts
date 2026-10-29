import { useEffect, useState } from "react";
import { apiFetch, readJsonResponse } from "@/lib/api-url";

export type RdoOffice = {
  id: string;
  rdo_code: string;
  rdo_name: string;
  region: string;
  city: string | null;
  office_address: string | null;
  head_name: string | null;
  email: string | null;
  phone: string | null;
  status: string;
  coverage_area: string[];
  business_count: number;
  transaction_count: number;
};

export type RdoBusiness = {
  id: string;
  business_name: string;
  tin: string;
  city: string | null;
  barangay: string | null;
  sector: string;
  status: string;
  compliance_score: number;
  monthly_revenue: number;
  vat_registered: boolean;
  last_audit_date: string | null;
  next_audit_date: string | null;
};

export type RdoTransaction = {
  transaction_ref: string;
  business_name: string;
  tin: string;
  amount: number;
  vat_amount: number;
  payment_method: string;
  channel: string;
  status: string;
  receipt_number: string | null;
  created_at: string;
};

export type RdoRiskItem = {
  business_name: string;
  tin: string;
  issue: string;
  risk_level: string;
  score: number;
};

export type RdoDashboardPayload = {
  office: RdoOffice | null;
  kpis: Array<{ label: string; value: string; subtext: string }>;
  series: Array<{ label: string; revenue: number; vat: number; transactions: number }>;
  businesses: RdoBusiness[];
  transactions: RdoTransaction[];
  riskQueue: RdoRiskItem[];
};

const emptyDashboard: RdoDashboardPayload = {
  office: null,
  kpis: [],
  series: [],
  businesses: [],
  transactions: [],
  riskQueue: [],
};

function authHeaders() {
  const headers: Record<string, string> = { Accept: "application/json" };
  try {
    const token = window.localStorage.getItem("nuers_api_token");
    if (token) headers.Authorization = `Bearer ${token}`;
  } catch {
    // Browser storage can be restricted.
  }

  return headers;
}

export function useRdoOffices(refreshKey = 0) {
  const [offices, setOffices] = useState<RdoOffice[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError(null);

      try {
        const response = await apiFetch("/api/rdos", { headers: authHeaders() });
        const payload = await readJsonResponse<{ data?: RdoOffice[] }>(response, "Unable to load RDO offices.");
        if (!cancelled) setOffices(payload.data ?? []);
      } catch (err) {
        if (!cancelled) {
          setOffices([]);
          setError(err instanceof Error ? err.message : "Unable to load RDO offices.");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    load();

    return () => {
      cancelled = true;
    };
  }, [refreshKey]);

  return { offices, loading, error };
}

export function useRdoDashboard(code?: string) {
  const [data, setData] = useState<RdoDashboardPayload>(emptyDashboard);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError(null);

      try {
        const query = code ? `?code=${encodeURIComponent(code)}` : "";
        const response = await apiFetch(`/api/rdo/dashboard${query}`, { headers: authHeaders() });
        const payload = await readJsonResponse<RdoDashboardPayload>(response, "Unable to load RDO dashboard.");
        if (!cancelled) setData(payload);
      } catch (err) {
        if (!cancelled) {
          setData(emptyDashboard);
          setError(err instanceof Error ? err.message : "Unable to load RDO dashboard.");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    load();

    return () => {
      cancelled = true;
    };
  }, [code]);

  return { data, loading, error };
}

export async function registerRdoOffice(payload: Record<string, unknown>) {
  const response = await apiFetch("/api/rdos", {
    method: "POST",
    headers: {
      ...authHeaders(),
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
  });
  const body = await readJsonResponse<{ success: boolean; office: RdoOffice }>(response, "Unable to register RDO office.");

  return body;
}

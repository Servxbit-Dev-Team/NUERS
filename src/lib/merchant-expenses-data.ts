import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useCallback, useEffect, useState } from "react";
import { apiFetch, readJsonResponse } from "@/lib/api-url";

export type MerchantExpense = {
  id: string;
  merchant_id: string | null;
  supplier_merchant_id: string | null;
  source_invoice_reference: string;
  purchase_order_reference: string | null;
  supplier_name: string;
  supplier_tin: string | null;
  buyer_name: string | null;
  buyer_tin: string | null;
  expense_category: string;
  description: string | null;
  gross_amount: number;
  vatable_amount: number;
  input_vat_amount: number;
  withholding_tax_amount: number;
  net_payable: number;
  document_type: string;
  payment_status: string;
  validation_status: string;
  reconciliation_status: string;
  claim_status: string;
  risk_level: string | null;
  ai_score: number | null;
  issued_at: string | null;
  due_at: string | null;
  paid_at: string | null;
  metadata: Record<string, string | number | boolean | null>;
};

export type MerchantExpensePayload = {
  merchant: { id: string; business_name: string; tin: string } | null;
  summary: {
    total_expenses: number;
    input_vat: number;
    withholding_tax: number;
    net_payable: number;
    expense_count: number;
    supplier_count: number;
    pending_validation: number;
    high_risk: number;
  };
  series: {
    monthly: Array<{ label: string; gross: number; input_vat: number; withholding: number }>;
    categories: Array<{ label: string; count: number; amount: number }>;
  };
  filters: {
    categories: string[];
    statuses: string[];
    suppliers: string[];
  };
  expenses: MerchantExpense[];
};

export type MerchantExpenseInput = {
  source_invoice_id: string;
  expense_category: string;
  evidence_reference: string;
};

const emptyPayload: MerchantExpensePayload = {
  merchant: null,
  summary: {
    total_expenses: 0,
    input_vat: 0,
    withholding_tax: 0,
    net_payable: 0,
    expense_count: 0,
    supplier_count: 0,
    pending_validation: 0,
    high_risk: 0,
  },
  series: {
    monthly: [],
    categories: [],
  },
  filters: {
    categories: ["all"],
    statuses: ["all"],
    suppliers: ["all"],
  },
  expenses: [],
};

function authHeaders() {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
  };

  const token = window.localStorage.getItem("nuers_api_token");
  if (token) headers.Authorization = `Bearer ${token}`;

  return headers;
}

export function useMerchantExpenses(filters: {
  search?: string;
  category?: string;
  status?: string;
  supplier?: string;
}) {
  const [data, setData] = useState<MerchantExpensePayload>(emptyPayload);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async (quiet=false) => {
    if(!quiet)setLoading(true);
    setError(null);

    const params = new URLSearchParams();
    if (filters.search) params.set("search", filters.search);
    if (filters.category && filters.category !== "all") params.set("category", filters.category);
    if (filters.status && filters.status !== "all") params.set("status", filters.status);
    if (filters.supplier && filters.supplier !== "all") params.set("supplier", filters.supplier);

    try {
      const response = await apiFetch(`/api/merchant/expenses${params.toString() ? `?${params}` : ""}`, {
        headers: authHeaders(),
        cache: "no-store",
      });
      const payload = await readJsonResponse<MerchantExpensePayload>(response, "Unable to load business account expenses.");

      setData(payload);
    } catch (err) {
      if(!quiet)setData(emptyPayload);
      setError(err instanceof Error ? err.message : "Unable to load business account expenses.");
    } finally {
      setLoading(false);
    }
  }, [filters.category, filters.search, filters.status, filters.supplier]);

  useEffect(() => {
    load();
  }, [load]);

  useRealtimeRefresh(()=>load(true));
  return { data, loading, error, refresh: () => load() };
}

export async function createMerchantExpense(values: MerchantExpenseInput): Promise<{ id: string; claim_status: string; duplicate?: boolean }> {
  const response = await apiFetch("/api/merchant/expenses", {
    method: "POST",
    headers: authHeaders(),
    body: JSON.stringify(values),
  });
  const payload = await readJsonResponse<{ id: string; claim_status: string; duplicate?: boolean }>(response, "Unable to record business account expense.");

  return payload;
}

export type EisReadinessStatus = "production_ready" | "certification_pending" | "production_blocked";
export type EisTransmissionStatus =
  | "queued"
  | "submitting"
  | "submitted"
  | "acknowledged"
  | "rejected"
  | "failed"
  | "duplicate"
  | "recovered"
  | "cancelled";

export type EisIntegration = {
  id: string;
  merchant_id: string | null;
  merchant_name: string;
  taxpayer_tin: string | null;
  branch_code: string;
  environment: string;
  status: string;
  certification_status: string;
  ptt_status: string;
  endpoint_base_url: string;
  json_schema_version: string;
  api_version: string;
  eis_certificate_number: string | null;
  permit_to_transmit_number: string | null;
  credentials_present: boolean;
  transmission_deadline_days: number;
  signing_algorithm: string;
  encryption_mode: string;
  public_key_fingerprint: string | null;
};

export type EisReadinessCheck = {
  id: string;
  category: string;
  control_code: string;
  name: string;
  requirement: string;
  status: "ready" | "warning" | "pending" | "blocked";
  severity: "critical" | "high" | "medium" | "low";
  evidence: string | null;
  source_reference: string | null;
  remediation: string | null;
  last_checked_at: string | null;
};

export type EisServiceHealth = {
  id: string;
  service_name: string;
  status: string;
  latency_ms: number | null;
  uptime_percentage: number;
  checked_at: string;
  metadata: Record<string, string | number | boolean | null>;
};

export type EisTransmission = {
  id: string;
  type: string;
  status: EisTransmissionStatus;
  invoice_count: number;
  payload_hash: string;
  duplicate_of: string | null;
  bir_reference_number: string | null;
  bir_acknowledgement: string | null;
  response_code: string | null;
  response_message: string | null;
  attempt_number: number;
  max_attempts: number;
  next_retry_at: string | null;
  submitted_at: string | null;
  acknowledged_at: string | null;
  due_at: string | null;
  latency_ms: number | null;
  environment: string;
  api_version: string;
  endpoint_url: string | null;
  request_id: string | null;
  merchant_name: string | null;
  merchant_tin: string | null;
  invoice_number: string | null;
  document_type: string | null;
  issue_date: string | null;
  created_at: string;
};

export type EisReadinessPayload = {
  integration: EisIntegration | null;
  readiness: {
    score: number;
    status: EisReadinessStatus;
    critical_blockers: number;
    production_blocked: boolean;
    can_submit_to_bir: boolean;
    deadline_days: number;
    last_updated_at: string;
  };
  kpis: Array<{
    key: string;
    label: string;
    value: string;
    subtext: string;
    status: string;
  }>;
  checks: EisReadinessCheck[];
  services: EisServiceHealth[];
  recent_transmissions: EisTransmission[];
  charts: {
    hourly: Array<Record<string, string | number>>;
    weekly: Array<Record<string, string | number>>;
  };
  requirements: Array<{ title: string; detail: string }>;
};

export type EisTransmissionsPayload = {
  summary: {
    total: number;
    acknowledged: number;
    failed: number;
    queued: number;
    duplicates: number;
    retry_queue: number;
  };
  transmissions: EisTransmission[];
  retry_queue: Array<{
    id: string;
    transmission_id: string;
    transmission_type: string;
    invoice_count: number;
    attempt_number: number;
    max_attempts: number;
    scheduled_at: string;
    error_message: string | null;
  }>;
};

export type EisValidationResult = {
  status: "valid" | "invalid";
  errors: string[];
  warnings: string[];
  invoice_count: number;
  payload_hash: string;
  payload_size: number;
  due_at: string | null;
};

async function api<T>(url: string, init?: RequestInit): Promise<T> {
  const response = await apiFetch(url, {
    cache: "no-store",
    ...init,
    headers: {
      "Content-Type": "application/json",
      "Cache-Control": "no-store",
      ...(init?.headers ?? {}),
    },
  });
  return readJsonResponse<T>(response, "Unable to complete BIR EIS request.");
}

export function getEisReadiness() {
  return api<EisReadinessPayload>(`/api/bir-eis/readiness?_=${Date.now()}`);
}

export function getEisTransmissions(params: Record<string, string>) {
  const search = new URLSearchParams({ ...params, _: String(Date.now()) });
  return api<EisTransmissionsPayload>(`/api/bir-eis/transmissions?${search.toString()}`);
}

export function validateEisPayload(payload: string | Record<string, unknown>) {
  return apiFetch("/api/bir-eis/validate", {
    method: "POST",
    cache: "no-store",
    headers: {
      "Content-Type": "application/json",
      "Cache-Control": "no-store",
    },
    body: JSON.stringify({ payload }),
  }).then(async (response) => {
    const contentType = response.headers.get("Content-Type") ?? "";
    if (!contentType.toLowerCase().includes("application/json")) {
      throw new Error("API endpoint returned HTML instead of JSON. Check the live API deployment path.");
    }

    const result = await response.json().catch(() => ({}));
    if (!response.ok && !result?.errors) {
      throw new Error(result?.message || "Unable to validate EIS payload.");
    }
    return result as EisValidationResult;
  });
}

export function submitEisTransmission(input: {
  payload: string | Record<string, unknown>;
  transmission_type: string;
  environment: string;
  api_version: string;
}) {
  return api<{ message: string; transmission: EisTransmission; validation: EisValidationResult }>("/api/bir-eis/transmissions", {
    method: "POST",
    body: JSON.stringify(input),
  });
}

export function retryEisTransmission(id: string) {
  return api<{ message: string; transmission: EisTransmission }>(`/api/bir-eis/transmissions/${id}/retry`, {
    method: "POST",
    body: JSON.stringify({}),
  });
}
import { apiFetch, readJsonResponse } from "@/lib/api-url";

export type Tier = "load_balancer" | "web" | "database" | "storage";
export type Metrics = {
  cpu_percent?: number;
  memory_percent?: number;
  disk_percent?: number;
  memory_bytes?: number;
  cpu_cores?: number;
  disk_used_bytes?: number;
  disk_total_bytes?: number;
  network_rx_bytes?: number;
  network_tx_bytes?: number;
};
export type Node = {
  id: string;
  code: string;
  tier: Tier;
  label: string;
  ha_group: string;
  environment: string;
  site: string;
  planned_specs: string | null;
  enrollment: string;
  fingerprint: string | null;
  observed_at: string | null;
  status: string;
  fresh: boolean;
  maintenance: boolean;
  maintenance_start: string | null;
  maintenance_end: string | null;
  maintenance_reason: string | null;
  revision: number;
  services: { key: string; state: string }[];
  telemetry: Metrics & {
    hostname?: string;
    ip?: string;
    os?: string;
    kernel?: string;
    role?: string;
    uptime_seconds?: number;
    backup_verified_at?: string;
    replication_lag_seconds?: number;
    checks?: Record<string, boolean>;
    capabilities?: string[];
    services?: Record<string, string>;
  };
};
export type ActionDefinition = { label: string; risk: string; tiers: Tier[] };
export type Overview = {
  nodes: Node[];
  actor_id: string;
  actions: Record<string, ActionDefinition>;
  summary: {
    total: number;
    enrolled: number;
    online: number;
    offline: number;
    unknown: number;
    degraded: number;
    maintenance: number;
    cpu: number | null;
    memory: number | null;
    disk_used: number | null;
    disk_total: number | null;
    cpu_samples: number;
    memory_samples: number;
    disk_samples: number;
  };
  controller: {
    configured: boolean;
    execution_enabled: boolean;
    last_sync_at: string | null;
    sync_state: string;
    freshness_seconds: number;
    protocol: string;
  };
  generated_at: string;
};
export type Preflight = {
  passed: boolean;
  risk: string;
  approvals_required: number;
  checks: { label: string; passed: boolean }[];
  observed_at: string | null;
  evaluated_at: string;
  policy_version: string;
};
export type Action = {
  id: string;
  node_id: string;
  node_code: string;
  actor_id: string;
  action: string;
  state: string;
  risk: string;
  reason: string;
  revision: number;
  parameters: Record<string, string>;
  preflight: Preflight;
  policy: Record<string, unknown>;
  result: Record<string, unknown> | null;
  created_at: string;
  expires_at: string;
  approvals: { actor_id: string; decision: string; note: string; created_at: string }[];
};
export type Event = {
  id: number;
  event: string;
  subject_id: string | null;
  actor_id: string;
  details: Record<string, unknown>;
  occurred_at: string;
  entry_hash: string;
  previous_hash: string;
};
export type Detail = { node: Node; samples: (Metrics & { at: string })[]; events: Event[] };
export const API = "/api/admin/infrastructure";
export const nice = (s: string) => s.replace(/[_.]/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
export function stamp(s?: string | null) {
  return s
    ? new Date(/[zZ]|[+-]\d\d:\d\d$/.test(s) ? s : s.replace(" ", "T") + "Z").toLocaleString(
        "en-PH",
        {
          timeZone: "Asia/Manila",
          month: "short",
          day: "numeric",
          hour: "numeric",
          minute: "2-digit",
        },
      )
    : "Not recorded";
}
export function bytes(n?: number | null) {
  if (n == null) return "—";
  const i = n ? Math.min(4, Math.floor(Math.log(n) / Math.log(1024))) : 0;
  return `${(n / 1024 ** i).toLocaleString("en-PH", { maximumFractionDigits: 1 })} ${["B", "KB", "MB", "GB", "TB"][i]}`;
}
export function uptime(n?: number) {
  return n == null
    ? "No telemetry"
    : `${Math.floor(n / 86400)}d ${Math.floor((n % 86400) / 3600)}h ${Math.floor((n % 3600) / 60)}m`;
}

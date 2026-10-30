import type { ReactNode } from "react";
import {
  Server,
  Database,
  Network,
  HardDrive,
  ShieldCheck,
  Activity,
  ArrowUpRight,
} from "lucide-react";
import type { Node, Metrics } from "./types";
import { nice, bytes, uptime } from "./types";
export const tierIcon = {
  load_balancer: Network,
  web: Server,
  database: Database,
  storage: HardDrive,
};
export function Status({ value }: { value: string }) {
  const text: Record<string, string> = {
    not_configured: "Not configured",
    stale: "Telemetry stale",
    online: "Online",
    offline: "Offline",
    maintenance: "Maintenance",
    awaiting_approval: "Awaiting approval",
    uncertain: "Needs reconciliation",
  };
  return (
    <span className={`sm-status sm-state-${value}`}>
      <i />
      {text[value] || nice(value)}
    </span>
  );
}
export function Empty({
  children,
  icon = "shield",
}: {
  children: ReactNode;
  icon?: "shield" | "activity";
}) {
  const Icon = icon === "shield" ? ShieldCheck : Activity;
  return (
    <div className="sm-empty">
      <Icon size={28} />
      <p>{children}</p>
    </div>
  );
}
export function Panel({
  title,
  children,
  action,
  className = "",
}: {
  title: string;
  children: ReactNode;
  action?: ReactNode;
  className?: string;
}) {
  return (
    <section className={`sm-panel ${className}`}>
      <header>
        <h2>{title}</h2>
        {action}
      </header>
      {children}
    </section>
  );
}
export function Meter({
  label,
  value,
  color,
}: {
  label: string;
  value?: number | null;
  color: string;
}) {
  return (
    <div className="sm-meter">
      <span>{label}</span>
      <div>
        <i style={{ width: `${value ?? 0}%`, background: color }} />
      </div>
      <b>{value == null ? "—" : `${Math.round(value)}%`}</b>
    </div>
  );
}
export function NodeCard({
  node,
  selected,
  onSelect,
}: {
  node: Node;
  selected: boolean;
  onSelect: () => void;
}) {
  const Icon = tierIcon[node.tier];
  const t = node.telemetry;
  return (
    <button
      className={`sm-node sm-tier-${node.tier} ${selected ? "selected" : ""}`}
      onClick={onSelect}
      aria-pressed={selected}
      aria-label={`Manage ${node.code}`}
    >
      <div className="sm-node-top">
        <span className="sm-node-icon">
          <Icon size={21} />
        </span>
        <span>
          <strong>{node.code}</strong>
          <small>{node.label}</small>
        </span>
        <Status value={node.status} />
      </div>
      <dl>
        <div>
          <dt>IP Address</dt>
          <dd>{t.ip || "Not configured"}</dd>
        </div>
        <div>
          <dt>OS</dt>
          <dd>{t.os || "Not reported"}</dd>
        </div>
        <div>
          <dt>Uptime</dt>
          <dd>{node.fresh ? uptime(t.uptime_seconds) : "No current telemetry"}</dd>
        </div>
      </dl>
      <div className="sm-card-meters">
        <Meter label="CPU" value={node.fresh ? t.cpu_percent : null} color="#00af76" />
        <Meter label="RAM" value={node.fresh ? t.memory_percent : null} color="#0063ff" />
        <Meter label="Disk" value={node.fresh ? t.disk_percent : null} color="#8194b5" />
      </div>
    </button>
  );
}
export function Spark({ values, color }: { values: (number | undefined)[]; color: string }) {
  const present = values.filter((v): v is number => v != null);
  return present.length > 1 ? (
    <svg
      className="sm-spark"
      role="img"
      aria-label="Observed utilization history"
      viewBox="0 0 160 42"
      preserveAspectRatio="none"
    >
      <path
        d={`M ${present.map((v, i) => `${(i / (present.length - 1)) * 160},${40 - v * 0.38}`).join(" L ")}`}
        fill="none"
        stroke={color}
        strokeWidth="2"
      />
    </svg>
  ) : (
    <div className="sm-no-samples">No history yet</div>
  );
}
export function Gauges({ node, samples }: { node: Node; samples: Metrics[] }) {
  return (
    <div className="sm-gauges">
      {(
        [
          {
            key: "cpu_percent",
            label: "CPU Usage",
            color: "#00b576",
            detail: node.telemetry.cpu_cores
              ? `${node.telemetry.cpu_cores} cores`
              : "Cores not reported",
          },
          {
            key: "memory_percent",
            label: "Memory Usage",
            color: "#3286ff",
            detail: bytes(node.telemetry.memory_bytes),
          },
          {
            key: "disk_percent",
            label: "Disk Usage",
            color: "#a044ff",
            detail: bytes(node.telemetry.disk_total_bytes),
          },
        ] as const
      ).map((g) => {
        const value = node.fresh ? node.telemetry[g.key] : null;
        return (
          <div key={g.key}>
            <h3>{g.label}</h3>
            <div
              className="sm-gauge"
              style={{
                background: `conic-gradient(${g.color} ${(value ?? 0) * 3.6}deg,#e8edf5 0)`,
              }}
            >
              <div>
                <strong>{value == null ? "—" : `${Math.round(value)}%`}</strong>
                <small>{g.detail}</small>
              </div>
            </div>
            <Spark color={g.color} values={samples.map((s) => s[g.key])} />
          </div>
        );
      })}
    </div>
  );
}
export function Pagination({
  page,
  total,
  pages,
  onChange,
}: {
  page: number;
  total: number;
  pages: number;
  onChange: (p: number) => void;
}) {
  return (
    <footer className="sm-pagination">
      <span>{total.toLocaleString()} records</span>
      <div>
        <button disabled={page <= 1} onClick={() => onChange(page - 1)} aria-label="Previous page">
          ‹
        </button>
        <b>{page}</b>
        <span>of {Math.max(1, pages)}</span>
        <button disabled={page >= pages} onClick={() => onChange(page + 1)} aria-label="Next page">
          ›
        </button>
      </div>
    </footer>
  );
}
export function ExternalLabel({ children }: { children: ReactNode }) {
  return (
    <>
      {children}
      <ArrowUpRight size={13} />
    </>
  );
}

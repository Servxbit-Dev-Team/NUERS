import { useEffect, useState } from "react";
import { Activity, ArrowUpRight, Database } from "lucide-react";
import { apiJson } from "@/lib/api-url";
import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { API, bytes, stamp, type Metrics, type Node } from "./types";
import { Empty, Panel, Status } from "./components";

type Sample = Metrics & { at: string };
type Metric = { key: keyof Metrics; label: string; color: string; max?: number; format: (value: number) => string };
const number = (value: number) => value.toLocaleString("en-PH", { maximumFractionDigits: 1 });
const percent = (value: number) => `${value.toFixed(1)}%`;
const metrics: Metric[] = [
  { key: "cpu_percent", label: "CPU", color: "#00a86b", format: percent, max: 100 },
  { key: "memory_percent", label: "Memory", color: "#0674ff", format: percent, max: 100 },
  { key: "disk_percent", label: "Disk", color: "#9245ed", format: percent, max: 100 },
];
const time = (value: string) => new Date(/[zZ]|[+-]\d\d:\d\d$/.test(value) ? value : value.replace(" ", "T") + "Z").getTime();

function MetricHistory({ metric, samples, current, from, to }: {
  metric: Metric; samples: Sample[]; current?: number; from: number; to: number;
}) {
  const points = samples.map(sample => ({ t: time(sample.at), v: sample[metric.key] })).filter(point => point.t >= from && point.t <= to);
  const valid = points.filter((point): point is { t: number; v: number } => typeof point.v === "number" && Number.isFinite(point.v));
  const maximum = metric.max || Math.max(1, ...valid.map(point => point.v)) * 1.1;
  const position = (point: { t: number; v: number }) => `${12 + (point.t - from) / Math.max(1, to - from) * 296},${75 - Math.min(point.v / maximum, 1) * 60}`;
  const segments: string[] = [];
  let segment: string[] = [], previous = 0;
  for (const point of points) {
    if (!Number.isFinite(point.v) || (previous && point.t - previous > 20 * 60000)) {
      if (segment.length) segments.push(segment.join(" L "));
      segment = [];
    }
    if (typeof point.v === "number" && Number.isFinite(point.v)) {
      segment.push(position({ t: point.t, v: point.v })); previous = point.t;
    }
  }
  if (segment.length) segments.push(segment.join(" L "));
  return <div className="sm-fleet-metric">
    <div><span>{metric.label}</span><strong>{current == null ? "Not reported" : metric.format(current)}</strong></div>
    {valid.length ? <svg viewBox="0 0 320 88" role="img" aria-label={`${metric.label} history`}>
      {[15, 45, 75].map(y => <line key={y} x1="12" x2="308" y1={y} y2={y} stroke="#e8eff8" />)}
      {segments.map((path, i) => <path key={i} d={`M ${path}`} fill="none" stroke={metric.color} strokeWidth="2" />)}
      {valid.map((point, i) => { const [x, y] = position(point).split(","); return <circle key={i} cx={x} cy={y} r={valid.length < 8 ? 2.6 : 1.2} fill={metric.color}><title>{stamp(new Date(point.t).toISOString())} PHT: {metric.format(point.v)}</title></circle>; })}
    </svg> : <div className="sm-fleet-no-history">History collecting</div>}
  </div>;
}

export function FleetMonitoring({ nodes, range, generatedAt, onSelect }: {
  nodes: Node[]; range: string; generatedAt: string; onSelect: (id: string) => void;
}) {
  const [histories, setHistories] = useState<Record<string, Sample[]>>({});
  const [error, setError] = useState("");
  useEffect(() => {
    const controller = new AbortController(); let busy = false;
    const refresh = async () => {
      if (busy) return; busy = true;
      try {
        const result = await apiJson<{ histories: Record<string, Sample[]> }>(API + `/monitoring?range=${encodeURIComponent(range)}`, { signal: controller.signal });
        if (!controller.signal.aborted) { setHistories(result.histories); setError(""); }
      } catch (e) { if (!controller.signal.aborted) setError((e as Error).message); }
      finally { busy = false; }
    };
    void refresh(); const unsubscribe = subscribeRealtimeRefresh(refresh);
    return () => { controller.abort(); unsubscribe(); };
  }, [range]);
  const to = time(generatedAt), from = to - ({ "1h": 1, "6h": 6, "24h": 24, "7d": 168 }[range] || 1) * 3600000;
  return <>
    {error && <p className="sm-error" role="alert">{error}</p>}
    <div className="sm-fleet-summary"><Activity size={18} /><strong>{nodes.length} resources</strong><span>{nodes.filter(n => n.status === "online").length} online</span><span>{nodes.filter(n => n.status === "stopped").length} stopped</span><span>Updated {stamp(generatedAt)} PHT</span></div>
    <div className="sm-fleet-grid">{nodes.map(node => {
      const charts: Metric[] = node.resource_type === "managed_load_balancer" ? [
        { key: "request_count", label: "Requests / minute", color: "#0674ff", format: number },
        { key: "response_ms", label: "Response time", color: "#9245ed", format: value => `${number(value)} ms` },
        { key: "http_5xx", label: "Target 5xx / minute", color: "#ef3340", format: number },
      ] : ["object_storage", "managed_filesystem"].includes(node.resource_type) ? [
        { key: "health", label: "Observed health", color: "#00a86b", max: 1, format: value => value === 1 ? "Available" : "Unavailable / stopped" },
        { key: "disk_used_bytes", label: "Storage used", color: "#9245ed", format: bytes },
      ] : metrics;
      const current = { ...node.telemetry, health: node.telemetry.reachable && node.telemetry.healthy ? 1 : 0 };
      const samples = [...(histories[node.id] || [])];
      if (node.fresh && node.observed_at) samples.push({ at: node.observed_at, ...current });
      samples.sort((a, b) => time(a.at) - time(b.at));
      return <Panel key={node.id} title={`${node.code} · ${node.label}`} action={<Status value={node.status} />} className="sm-fleet-card">
        <div className="sm-fleet-meta"><span>{node.provider_label}</span><span>{node.environment} · {node.region}</span></div>
        {node.telemetry.database_nodes === 2 && <div className="sm-fleet-db"><Database size={16} /><span><strong>2 database nodes</strong> · DB-01 Primary ({node.telemetry.primary_zone}) · DB-02 Standby ({node.telemetry.standby_zone})</span></div>}
        <div className="sm-fleet-charts">{charts.map(metric => <MetricHistory key={metric.key} metric={metric} samples={samples} current={node.fresh ? current[metric.key] : undefined} from={from} to={to} />)}</div>
        <div className="sm-fleet-axis"><span>{stamp(new Date(from).toISOString())}</span><span>{stamp(new Date(to).toISOString())} PHT</span></div>
        <footer className="sm-fleet-footer"><span>{node.resource_type === "object_storage" ? "S3 storage metrics update daily" : "Retained history · 15-minute samples"}{node.telemetry.metric_observed_at ? ` · Metric ${stamp(node.telemetry.metric_observed_at)} PHT` : ""}</span><button onClick={() => onSelect(node.id)}>Details <ArrowUpRight size={14} /></button></footer>
      </Panel>;
    })}</div>
    {!nodes.length && <Empty>No resources match these filters.</Empty>}
  </>;
}

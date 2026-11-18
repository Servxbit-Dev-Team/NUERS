import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useEffect, useMemo, useState, type ElementType } from "react";
import { Link } from "react-router-dom";
import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  Database,
  FileJson,
  Globe,
  KeyRound,
  RefreshCw,
  Send,
  ShieldCheck,
  Wifi,
  XCircle,
} from "lucide-react";
import { Bar, BarChart, CartesianGrid, Line, LineChart, XAxis, YAxis } from "recharts";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from "@/components/ui/chart";
import { Progress } from "@/components/ui/progress";
import { Skeleton } from "@/components/ui/skeleton";
import {
  getEisReadiness,
  type EisReadinessCheck,
  type EisReadinessPayload,
  type EisServiceHealth,
  type EisTransmission,
} from "@/lib/eis-api";
import { cn } from "@/lib/utils";

const chartConfig: ChartConfig = {
  submitted: { label: "Submitted", color: "var(--chart-1)" },
  acknowledged: { label: "Acknowledged", color: "var(--chart-4)" },
  failed: { label: "Failed", color: "var(--chart-5)" },
};

const statusMeta: Record<string, { label: string; className: string; icon: ElementType }> = {
  production_ready: {
    label: "Production Ready",
    className: "bg-success/15 text-success border-success/30",
    icon: CheckCircle2,
  },
  certification_pending: {
    label: "Certification Pending",
    className: "bg-warning/15 text-warning-foreground border-warning/30",
    icon: Clock,
  },
  production_blocked: {
    label: "Production Blocked",
    className: "bg-destructive/15 text-destructive border-destructive/30",
    icon: XCircle,
  },
};

function CheckBadge({ status }: { status: EisReadinessCheck["status"] }) {
  if (status === "ready")
    return <Badge className="bg-success/15 text-success border-success/30">Ready</Badge>;
  if (status === "warning")
    return (
      <Badge className="bg-warning/15 text-warning-foreground border-warning/30">Warning</Badge>
    );
  if (status === "blocked")
    return (
      <Badge className="bg-destructive/15 text-destructive border-destructive/30">Blocked</Badge>
    );
  return <Badge variant="secondary">Pending</Badge>;
}

function ServiceBadge({ status }: { status: string }) {
  if (status === "operational")
    return <Badge className="bg-success/15 text-success border-success/30">Operational</Badge>;
  if (status === "pending_credentials")
    return (
      <Badge className="bg-warning/15 text-warning-foreground border-warning/30">
        Pending Credentials
      </Badge>
    );
  if (status === "degraded")
    return (
      <Badge className="bg-warning/15 text-warning-foreground border-warning/30">Degraded</Badge>
    );
  return <Badge variant="secondary">{status.replaceAll("_", " ")}</Badge>;
}

function TransmissionBadge({ status }: { status: EisTransmission["status"] }) {
  if (status === "acknowledged" || status === "recovered")
    return <Badge className="bg-success/15 text-success border-success/30">{status}</Badge>;
  if (status === "failed" || status === "rejected")
    return (
      <Badge className="bg-destructive/15 text-destructive border-destructive/30">{status}</Badge>
    );
  if (status === "duplicate") return <Badge variant="secondary">duplicate</Badge>;
  return (
    <Badge className="bg-warning/15 text-warning-foreground border-warning/30">{status}</Badge>
  );
}

function EmptyChart() {
  return (
    <div className="flex h-full items-center justify-center rounded-md border border-dashed text-sm text-muted-foreground">
      Transmission telemetry appears after EIS payloads are queued.
    </div>
  );
}

export function EisHub() {
  const [data, setData] = useState<EisReadinessPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function load(blocking = false) {
    if (blocking) setLoading(true);
    else setRefreshing(true);
    setError(null);

    try {
      setData(await getEisReadiness());
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to load BIR EIS readiness.");
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }

  useEffect(() => {
    load(true);
    const stop = subscribeRealtimeRefresh(() => load(false));
    return () => stop();
  }, []);

  const blockers = useMemo(
    () =>
      data?.checks.filter(
        (check) =>
          check.status === "blocked" || (check.severity === "critical" && check.status !== "ready"),
      ) ?? [],
    [data],
  );

  if (loading) {
    return (
      <div className="space-y-5">
        <Skeleton className="h-24 w-full" />
        <div className="grid gap-4 md:grid-cols-4">
          {Array.from({ length: 4 }).map((_, index) => (
            <Skeleton key={index} className="h-32" />
          ))}
        </div>
        <Skeleton className="h-96 w-full" />
      </div>
    );
  }

  const readiness = data?.readiness;
  const meta = readiness ? statusMeta[readiness.status] : statusMeta.production_blocked;
  const StatusIcon = meta.icon;
  const hourly = data?.charts.hourly ?? [];
  const weekly = data?.charts.weekly ?? [];

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div className="space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold tracking-tight">BIR EIS Production Readiness</h1>
            <Badge className={meta.className}>
              <StatusIcon className="mr-1 h-3.5 w-3.5" />
              {meta.label}
            </Badge>
          </div>
          <p className="max-w-4xl text-sm text-muted-foreground">
            Certification, encrypted JSON payload validation, transmission queueing, duplicate
            prevention, retry governance, and audit controls for BIR EIS integration.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => load(false)}
            disabled={refreshing}
          >
            <RefreshCw className={cn("h-4 w-4", refreshing && "animate-spin")} />
            Refresh
          </Button>
          <Button asChild size="sm" className="gap-2">
            <Link to="/super-admin/eis-transmissions">
              <Send className="h-4 w-4" />
              Transmissions
            </Link>
          </Button>
        </div>
      </div>

      {error && (
        <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">
          {error}
        </div>
      )}

      <Card className="border-l-4 border-l-warning">
        <CardContent className="grid gap-5 p-5 lg:grid-cols-[1fr_340px]">
          <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <p className="text-sm font-semibold text-foreground">Production Gate Score</p>
                <p className="text-sm text-muted-foreground">
                  {readiness?.production_blocked
                    ? "Production submission requires verified solution accreditation, taxpayer PTI, EIS certification, PTT, credentials and implemented BIR transport."
                    : "All critical production controls are registered."}
                </p>
              </div>
              <div className="text-right">
                <p className="text-3xl font-bold">{readiness?.score ?? 0}%</p>
                <p className="text-xs text-muted-foreground">
                  {readiness?.critical_blockers ?? 0} critical blockers
                </p>
              </div>
            </div>
            <Progress value={readiness?.score ?? 0} className="h-2" />
            <div className="grid gap-3 sm:grid-cols-3">
              <div className="rounded-md border p-3">
                <p className="text-xs uppercase text-muted-foreground">Environment</p>
                <p className="font-semibold capitalize">
                  {data?.integration?.environment ?? "certification"}
                </p>
              </div>
              <div className="rounded-md border p-3">
                <p className="text-xs uppercase text-muted-foreground">Deadline SLA</p>
                <p className="font-semibold">{readiness?.deadline_days ?? 3} calendar days</p>
              </div>
              <div className="rounded-md border p-3">
                <p className="text-xs uppercase text-muted-foreground">Credentials</p>
                <p className="font-semibold">
                  {data?.integration?.credentials_present ? "Registered" : "Not registered"}
                </p>
              </div>
            </div>
          </div>
          <div className="rounded-md border bg-muted/20 p-4">
            <div className="mb-3 flex items-center gap-2">
              <AlertTriangle className="h-4 w-4 text-warning" />
              <p className="text-sm font-semibold">Production Blockers</p>
            </div>
            <div className="space-y-3">
              {blockers.length === 0 ? (
                <p className="text-sm text-muted-foreground">No critical blocker recorded.</p>
              ) : (
                blockers.slice(0, 4).map((check) => (
                  <div key={check.id} className="space-y-1 rounded-md border bg-background p-3">
                    <div className="flex items-center justify-between gap-2">
                      <p className="text-sm font-semibold">{check.control_code}</p>
                      <CheckBadge status={check.status} />
                    </div>
                    <p className="text-xs text-muted-foreground">{check.remediation}</p>
                  </div>
                ))
              )}
            </div>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {data?.kpis.map((kpi) => (
          <Card key={kpi.key}>
            <CardContent className="p-4">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="text-sm text-muted-foreground">{kpi.label}</p>
                  <p className="mt-1 text-2xl font-bold">{kpi.value}</p>
                  <p className="mt-1 text-xs text-muted-foreground">{kpi.subtext}</p>
                </div>
                {kpi.key === "production_gate" ? (
                  <KeyRound className="h-5 w-5 text-warning" />
                ) : (
                  <Database className="h-5 w-5 text-primary" />
                )}
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base">
              <FileJson className="h-4 w-4" />
              EIS Transmission Telemetry
            </CardTitle>
          </CardHeader>
          <CardContent className="grid gap-4 lg:grid-cols-2">
            <div className="h-64">
              {hourly.length ? (
                <ChartContainer config={chartConfig} className="h-full">
                  <LineChart data={hourly}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    <Line
                      isAnimationActive={false}
                      dataKey="submitted"
                      stroke="var(--chart-1)"
                      strokeWidth={2}
                      dot={false}
                    />
                    <Line
                      isAnimationActive={false}
                      dataKey="acknowledged"
                      stroke="var(--chart-4)"
                      strokeWidth={2}
                      dot={false}
                    />
                    <Line
                      isAnimationActive={false}
                      dataKey="failed"
                      stroke="var(--chart-5)"
                      strokeWidth={2}
                      dot={false}
                    />
                  </LineChart>
                </ChartContainer>
              ) : (
                <EmptyChart />
              )}
            </div>
            <div className="h-64">
              {weekly.length ? (
                <ChartContainer config={chartConfig} className="h-full">
                  <BarChart data={weekly}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                    <YAxis tick={{ fontSize: 11 }} allowDecimals={false} />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    <Bar
                      isAnimationActive={false}
                      dataKey="submitted"
                      fill="var(--chart-1)"
                      radius={[4, 4, 0, 0]}
                    />
                    <Bar
                      isAnimationActive={false}
                      dataKey="acknowledged"
                      fill="var(--chart-4)"
                      radius={[4, 4, 0, 0]}
                    />
                    <Bar
                      isAnimationActive={false}
                      dataKey="failed"
                      fill="var(--chart-5)"
                      radius={[4, 4, 0, 0]}
                    />
                  </BarChart>
                </ChartContainer>
              ) : (
                <EmptyChart />
              )}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base">
              <Wifi className="h-4 w-4" />
              Service Health
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            {data?.services.map((service: EisServiceHealth) => (
              <div
                key={service.id}
                className="flex items-center justify-between gap-3 rounded-md border p-3"
              >
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold">{service.service_name}</p>
                  <p className="text-xs text-muted-foreground">
                    {service.latency_ms === null
                      ? "No live BIR latency until credentials are present"
                      : `${service.latency_ms}ms latency`}
                  </p>
                </div>
                <ServiceBadge status={service.status} />
              </div>
            ))}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-[1fr_380px]">
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base">
              <ShieldCheck className="h-4 w-4" />
              BIR EIS Readiness Controls
            </CardTitle>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <table className="w-full min-w-[860px] text-sm">
              <thead>
                <tr className="border-b text-left text-xs text-muted-foreground">
                  <th className="py-2 pr-3 font-medium">Control</th>
                  <th className="py-2 pr-3 font-medium">Requirement</th>
                  <th className="py-2 pr-3 font-medium">Severity</th>
                  <th className="py-2 pr-3 font-medium">Status</th>
                  <th className="py-2 font-medium">Evidence / Next Step</th>
                </tr>
              </thead>
              <tbody>
                {data?.checks.map((check) => (
                  <tr key={check.id} className="border-b last:border-0 align-top">
                    <td className="py-3 pr-3">
                      <p className="font-semibold">{check.control_code}</p>
                      <p className="text-xs text-muted-foreground">{check.category}</p>
                    </td>
                    <td className="max-w-md py-3 pr-3 text-muted-foreground">{check.name}</td>
                    <td className="py-3 pr-3 capitalize">{check.severity}</td>
                    <td className="py-3 pr-3">
                      <CheckBadge status={check.status} />
                    </td>
                    <td className="max-w-lg py-3 text-xs text-muted-foreground">
                      <p>{check.evidence}</p>
                      {check.status !== "ready" && (
                        <p className="mt-1 font-medium text-foreground">{check.remediation}</p>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>

        <div className="space-y-4">
          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-2 text-base">
                <Globe className="h-4 w-4" />
                Official Integration Profile
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-3 text-sm">
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">Endpoint</span>
                <span className="text-right font-medium">
                  {data?.integration?.endpoint_base_url}
                </span>
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">API Version</span>
                <span className="font-medium">{data?.integration?.api_version}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">JSON Schema</span>
                <span className="font-medium">{data?.integration?.json_schema_version}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">Encryption</span>
                <span className="text-right font-medium">{data?.integration?.encryption_mode}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">EIS CERT</span>
                <span className="font-medium">
                  {data?.integration?.eis_certificate_number ?? "Not registered"}
                </span>
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-muted-foreground">PTT</span>
                <span className="font-medium">
                  {data?.integration?.permit_to_transmit_number ?? "Not registered"}
                </span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2">
              <CardTitle className="text-base">Recent Database Transmissions</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              {data?.recent_transmissions.length ? (
                data.recent_transmissions.map((tx) => (
                  <div key={tx.id} className="rounded-md border p-3">
                    <div className="flex items-center justify-between gap-2">
                      <p className="truncate font-mono text-xs font-semibold">{tx.id}</p>
                      <TransmissionBadge status={tx.status} />
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {tx.merchant_name ?? "Certification payload"} • {tx.invoice_count} document(s)
                    </p>
                    <p className="mt-1 truncate font-mono text-[11px] text-muted-foreground">
                      {tx.payload_hash}
                    </p>
                  </div>
                ))
              ) : (
                <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                  No EIS transmissions have been queued yet.
                </p>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}

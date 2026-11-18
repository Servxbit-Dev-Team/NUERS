import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Plus, RefreshCw } from "lucide-react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog";
import { apiJson } from "@/lib/api-url";
import { Empty, Panel, Status, Pagination } from "./components";
import { ProviderFields } from "./provider-fields";
import { API, nice, stamp, type ProviderCatalog, type ProviderChoice } from "./types";

type Expansion = ProviderChoice & { id: string; created_at: string; environment: string; region: string; network_zone: string; quantity: number; vcpu: number | null; memory_gib: number | null; storage_gib: number | null; estimated_monthly_php: number | null; reason: string; state: string };
const pesos = (value: number) => new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(value);

export function CapacityWorkspace({ catalog }: { catalog: ProviderCatalog }) {
  const [page, setPage] = useState(1), [open, setOpen] = useState(false), [error, setError] = useState(""), [message, setMessage] = useState("");
  const [result, setResult] = useState<{ data: Expansion[]; total: number; last_page: number } | null>(null);
  const load = useCallback(async () => {
    try { setResult(await apiJson(API + `/expansions?page=${page}`)); setError(""); }
    catch (e) { setError((e as Error).message); }
  }, [page]);
  useEffect(() => { void load(); }, [load]);
  return <>
    <Panel title="Providers & Expansion" action={<button className="sm-primary" onClick={() => setOpen(true)}><Plus size={16} />Request capacity</button>}>
      <div className="sm-provider-grid">{Object.entries(catalog.guidance).map(([key, text]) => <article key={key} className="sm-detail-box"><h3>{catalog.providers[key]}</h3><p>{text}</p><small>Control requires a verified resource and enabled controller adapter.</small></article>)}</div>
    </Panel>
    <Panel title="Capacity Requests" action={<button onClick={() => void load()}><RefreshCw size={15} />Refresh requests</button>}>
      {message && <p role="status" className="sm-callout">{message}</p>}
      {error ? <p role="alert" className="sm-error">{error}</p> : <div className="sm-table-wrap">
        <table><thead><tr>{["Requested", "Provider / resource", "Location / network", "Capacity", "Added monthly estimate", "Status"].map(label => <th key={label}>{label}</th>)}</tr></thead>
          <tbody>{result?.data.map(row => <tr key={row.id}>
            <td>{stamp(row.created_at)} PHT</td>
            <td><strong>{catalog.providers[row.provider]}</strong><br />{catalog.resource_types[row.resource_type]?.label}<br />{nice(row.tier)} · {nice(row.environment)}</td>
            <td>{row.region}<br /><small>{row.network_zone}</small></td>
            <td>{row.quantity} resource(s)<br />{row.vcpu ? `${row.vcpu} vCPU · ` : ""}{row.memory_gib ? `${row.memory_gib} GiB RAM · ` : ""}{row.storage_gib ? `${row.storage_gib} GiB per resource` : "Usage-based capacity"}<br /><small>{row.reason}</small></td>
            <td>{row.estimated_monthly_php == null ? "Quote required" : pesos(row.estimated_monthly_php)}<br /><small>Unverified estimate · all requested resources</small></td>
            <td><Status value={row.state} /><br /><small>No purchase or provisioning performed</small></td>
          </tr>)}</tbody></table>
        {!result?.data.length && <Empty>{result ? "No expansion requests yet." : "Loading capacity requests…"}</Empty>}
      </div>}
      <Pagination page={page} pages={result?.last_page || 1} total={result?.total || 0} onChange={setPage} />
    </Panel>
    {open && <CapacityDialog catalog={catalog} onClose={() => setOpen(false)} onSaved={() => { setOpen(false); setMessage("Expansion request recorded for operator review. No server has been purchased or started."); if (page === 1) void load(); else setPage(1); }} />}
  </>;
}

function CapacityDialog({ catalog, onClose, onSaved }: { catalog: ProviderCatalog; onClose: () => void; onSaved: () => void }) {
  const [choice, setChoice] = useState<ProviderChoice>({ provider: "aws", resource_type: "virtual_machine", tier: "web" });
  const [environment, setEnvironment] = useState("staging"), [region, setRegion] = useState(""), [network, setNetwork] = useState("");
  const [quantity, setQuantity] = useState("1"), [vcpu, setVcpu] = useState(""), [memory, setMemory] = useState(""), [storage, setStorage] = useState(""), [estimate, setEstimate] = useState("");
  const [reason, setReason] = useState(""), [password, setPassword] = useState(""), [code, setCode] = useState(""), [error, setError] = useState(""), [busy, setBusy] = useState(false);
  const [idempotency] = useState(() => crypto.randomUUID());
  const compute = !["managed_load_balancer", "object_storage"].includes(choice.resource_type);
  async function submit(event: FormEvent) {
    event.preventDefault(); setBusy(true); setError("");
    try {
      const proof = await apiJson<{ proof_id: string }>(API + "/step-up", { method: "POST", body: JSON.stringify({ password, code }) });
      setPassword(""); setCode("");
      await apiJson(API + "/expansions", { method: "POST", body: JSON.stringify({ ...choice, environment, region, network_zone: network, quantity: Number(quantity), ...(compute && vcpu ? { vcpu: Number(vcpu) } : {}), ...(compute && memory ? { memory_gib: Number(memory) } : {}), ...(storage ? { storage_gib: Number(storage) } : {}), ...(estimate ? { estimated_monthly_php: Number(estimate) } : {}), reason, proof_id: proof.proof_id, idempotency_key: idempotency }) });
      onSaved();
    } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  }
  return <Dialog open onOpenChange={open => { if (!open && !busy) onClose(); }}>
    <DialogContent className="sm-dialog" onEscapeKeyDown={event => { if (busy) event.preventDefault(); }} onPointerDownOutside={event => { if (busy) event.preventDefault(); }}>
      <DialogHeader><DialogTitle>Request Infrastructure Expansion</DialogTitle><DialogDescription>Specify the provider, capacity and estimated added cost for review. This does not place an order.</DialogDescription></DialogHeader>
      <form className="sm-form" onSubmit={submit}>
        <div className="sm-form-grid">
          <ProviderFields catalog={catalog} value={choice} onChange={setChoice} />
          <label>Environment<select value={environment} onChange={e => setEnvironment(e.target.value)}><option value="staging">Staging</option><option value="production">Production</option></select></label>
          <label>Region / data location<input required maxLength={80} value={region} onChange={e => setRegion(e.target.value)} /></label>
          <label>Private network / zone<input required maxLength={80} value={network} onChange={e => setNetwork(e.target.value)} /></label>
          <label>Number of resources<input type="number" min={1} max={20} required value={quantity} onChange={e => setQuantity(e.target.value)} /></label>
          {compute && <><label>vCPU per resource<input type="number" min={1} max={512} value={vcpu} onChange={e => setVcpu(e.target.value)} /></label><label>RAM per resource (GiB)<input type="number" min={1} max={4096} value={memory} onChange={e => setMemory(e.target.value)} /></label></>}
          <label>{choice.resource_type === "object_storage" ? "Expected stored data (GiB per bucket)" : "Storage per resource (GiB)"}<input type="number" min={1} max={1048576} value={storage} onChange={e => setStorage(e.target.value)} /></label>
          <label>Added monthly cost, all resources (PHP)<input type="number" min={0} max={10000000} step="0.01" value={estimate} onChange={e => setEstimate(e.target.value)} placeholder="Leave blank if quote is pending" /></label>
        </div>
        <p className="sm-callout">{catalog.guidance[choice.provider]} Total monthly spend must stay within {pesos(catalog.budget_ceiling_php)} unless the owner approves a revised budget.</p>
        <label>Purpose and rollout requirements<textarea required minLength={10} maxLength={500} value={reason} onChange={e => setReason(e.target.value)} /></label>
        <div className="sm-form-grid"><label>Current password<input type="password" autoComplete="current-password" required value={password} onChange={e => setPassword(e.target.value)} /></label><label>Authenticator code<input inputMode="numeric" pattern="[0-9]{6}" maxLength={6} required value={code} onChange={e => setCode(e.target.value)} /></label></div>
        {error && <p role="alert" className="sm-error">{error}</p>}
        <button className="sm-primary" disabled={busy} type="submit">{busy ? "Recording request…" : "Submit for capacity review"}</button>
      </form>
    </DialogContent>
  </Dialog>;
}

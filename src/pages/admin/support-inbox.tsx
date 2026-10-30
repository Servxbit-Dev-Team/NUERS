import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useCallback, useEffect, useState } from "react";
import { Inbox, Search, RefreshCw, MessageCircle, ShieldCheck } from "lucide-react";
import { ChatThread, type Conversation } from "@/components/support/chat";
import { apiJson } from "@/lib/api-url";
import "./support-inbox.css";
type Row = Conversation & { preview: string; priority: string; revision: number };
type Payload = {
  data: Row[];
  total: number;
  page: number;
  last_page: number;
  counts: { open: number; pending: number; resolved: number; unread: number };
  agents: { id: number; name: string }[];
};
export function AdminSupportInbox() {
  const [data, setData] = useState<Payload | null>(null),
    [selected, setSelected] = useState(""),
    [detail, setDetail] = useState<Conversation | null>(null),
    [search, setSearch] = useState(""),
    [query, setQuery] = useState(""),
    [status, setStatus] = useState(""),
    [priority, setPriority] = useState(""),
    [unread, setUnread] = useState(false),
    [page, setPage] = useState(1),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(true);
  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);
  const load = useCallback(async () => {
    try {
      const p = new URLSearchParams({ page: String(page), search: query });
      if (status) p.set("status", status);
      if (priority) p.set("priority", priority);
      if (unread) p.set("unread", "1");
      const r = await apiJson<Payload>(`/api/admin/support?${p}`);
      setData(r);
      setError("");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load the support inbox.");
    } finally {
      setLoading(false);
    }
  }, [page, query, status, priority, unread]);
  useEffect(() => {
    setLoading(true);
    void load();
    const stop = subscribeRealtimeRefresh(load);
    return () => stop();
  }, [load]);
  async function update(key: "status" | "priority" | "assigned_to", value: string) {
    if (!detail || busy) return;
    setBusy(true);
    try {
      await apiJson(`/api/admin/support/${detail.id}`, {
        method: "PATCH",
        body: JSON.stringify({
          revision: detail.revision,
          status: detail.status,
          priority: detail.priority,
          assigned_to: detail.assigned_to || null,
          [key]: key === "assigned_to" ? (value ? Number(value) : null) : value,
        }),
      });
      setDetail((v) =>
        v
          ? {
              ...v,
              [key]: key === "assigned_to" ? (value ? Number(value) : null) : value,
              revision: (v.revision || 0) + 1,
            }
          : v,
      );
      await load();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not update conversation.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <main className="si-page">
      <header>
        <div>
          <span>CLIENT SUPPORT & SERVICE OPERATIONS</span>
          <h1>
            <Inbox />
            Support inbox
          </h1>
          <p>Review website messages, coordinate ownership and reply to visitors.</p>
        </div>
        <button onClick={() => void load()}>
          <RefreshCw size={15} />
          Refresh
        </button>
      </header>
      <div className="si-kpis">
        {(["open", "pending", "resolved", "unread"] as const).map((k) => (
          <div key={k}>
            <small>{k === "unread" ? "Unread messages" : `${k} conversations`}</small>
            <strong>{data?.counts[k] ?? "—"}</strong>
          </div>
        ))}
      </div>
      <section className="si-console">
        <aside>
          <div className="si-search">
            <Search size={16} />
            <input
              type="search"
              aria-label="Search conversations"
              placeholder="Search name, email or message…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <div className="si-filters">
            <select
              aria-label="Filter status"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All statuses</option>
              <option value="open">Open</option>
              <option value="pending">Pending reply</option>
              <option value="resolved">Resolved</option>
            </select>
            <select
              aria-label="Filter priority"
              value={priority}
              onChange={(e) => {
                setPriority(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All priorities</option>
              <option value="normal">Normal</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
            </select>
            <label>
              <input
                type="checkbox"
                checked={unread}
                onChange={(e) => {
                  setUnread(e.target.checked);
                  setPage(1);
                }}
              />
              Unread only
            </label>
          </div>
          <div className="si-list">
            {loading && !data ? (
              <p className="si-empty">Loading conversations…</p>
            ) : !data?.data.length ? (
              <div className="si-empty">
                <MessageCircle />
                <strong>No conversations found</strong>
                <p>New website messages will appear here.</p>
              </div>
            ) : (
              data.data.map((c) => (
                <button
                  key={c.id}
                  className={selected === c.id ? "si-selected" : ""}
                  onClick={() => {
                    setSelected(c.id);
                    setDetail(c);
                  }}
                >
                  <div>
                    <strong>{c.visitor_name}</strong>
                    <time>
                      {new Date(
                        c.last_message_at.replace(" ", "T") +
                          (/Z|[+-]\d\d:\d\d$/.test(c.last_message_at) ? "" : "Z"),
                      ).toLocaleDateString("en-PH", { month: "short", day: "numeric" })}
                    </time>
                  </div>
                  <h2>{c.subject}</h2>
                  <p>{c.preview}</p>
                  <div>
                    <span className={`nc-status nc-${c.status}`}>{c.status}</span>
                    {c.priority !== "normal" && (
                      <span className={`si-priority si-${c.priority}`}>{c.priority}</span>
                    )}
                    {Number(c.unread_count) > 0 && <b>{c.unread_count} unread</b>}
                  </div>
                </button>
              ))
            )}
          </div>
          <div data-slot="table-pagination" className="si-pagination">
            <span>{data?.total || 0} conversations</span>
            <button
              aria-label="Previous page"
              disabled={page === 1}
              onClick={() => setPage((p) => p - 1)}
            >
              ‹
            </button>
            <span>
              {page}/{data?.last_page || 1}
            </span>
            <button
              aria-label="Next page"
              disabled={page >= (data?.last_page || 1)}
              onClick={() => setPage((p) => p + 1)}
            >
              ›
            </button>
          </div>
        </aside>
        <article>
          {selected && detail ? (
            <>
              <header className="si-detail-header">
                <div>
                  <strong>{detail.subject}</strong>
                  <span>
                    {detail.visitor_name}
                    {detail.visitor_email ? ` · ${detail.visitor_email}` : ""}
                  </span>
                  <small>Reference: {detail.id}</small>
                </div>
                <div className="si-controls">
                  <label>
                    Status
                    <select
                      disabled={busy}
                      value={detail.status}
                      onChange={(e) => void update("status", e.target.value)}
                    >
                      <option value="open">Open</option>
                      <option value="pending">Pending</option>
                      <option value="resolved">Resolved</option>
                    </select>
                  </label>
                  <label>
                    Priority
                    <select
                      disabled={busy}
                      value={detail.priority || "normal"}
                      onChange={(e) => void update("priority", e.target.value)}
                    >
                      <option value="normal">Normal</option>
                      <option value="high">High</option>
                      <option value="urgent">Urgent</option>
                    </select>
                  </label>
                  <label>
                    Assigned to
                    <select
                      disabled={busy}
                      value={detail.assigned_to || ""}
                      onChange={(e) => void update("assigned_to", e.target.value)}
                    >
                      <option value="">Unassigned</option>
                      {data?.agents.map((a) => (
                        <option key={a.id} value={a.id}>
                          {a.name}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
              </header>
              <ChatThread key={selected} id={selected} admin onConversation={setDetail} />
            </>
          ) : (
            <div className="si-empty si-select-empty">
              <ShieldCheck />
              <h2>Select a conversation</h2>
              <p>
                Replies are saved and shown in the visitor’s chat widget. No email or SMS is sent
                automatically.
              </p>
            </div>
          )}
        </article>
      </section>
      {error && (
        <div className="nc-error" role="alert">
          {error}
          <button onClick={() => void load()}>Reload inbox</button>
        </div>
      )}
      <p className="si-footnote">
        Administrator access only · Shared team unread status · Server-side message history and
        assignment audit · Polling updates every 5 seconds
      </p>
    </main>
  );
}

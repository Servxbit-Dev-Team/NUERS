import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useEffect, useState } from "react";
import { Bell, RefreshCw } from "lucide-react";
import { apiJson } from "@/lib/api-url";
type Operations = {
  dispatch_enabled: boolean;
  catalog: Record<string, string[]>;
  runtime: { worker_seen_at: string | null };
  counts: { state: string; total: number }[];
  recent: {
    id: string;
    event: string;
    category: string;
    state: string;
    attempts: number;
    error_code: string | null;
    created_at: string;
  }[];
};
export function NotificationOperations() {
  const [data, setData] = useState<Operations | null>(null),
    [error, setError] = useState("");
  async function load() {
    try {
      setData(await apiJson<Operations>("/api/admin/configuration/notifications"));
      setError("");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unable to load notifications.");
    }
  }
  useEffect(() => {
    void load();
  }, []);
  useRealtimeRefresh(load);
  const last = data?.runtime.worker_seen_at;
  const recent =
    last &&
    Date.now() - new Date(last).getTime() >= 0 &&
    Date.now() - new Date(last).getTime() < 180000;
  return (
    <section className="nconfig-panel" id="notification-operations">
      <header>
        <h2>
          <Bell size={18} />
          Email Notifications
        </h2>
        <span>NUERS transactional delivery</span>
        <button type="button" className="nconfig-button" onClick={() => void load()}>
          <RefreshCw size={15} />
          Refresh
        </button>
      </header>
      {error && (
        <p role="alert" className="nconfig-error">
          {error}
        </p>
      )}
      <p className="nconfig-help">
        Sender: <strong>NUERS</strong>. All email uses the saved SMTP connection. Essential security
        alerts stay enabled. Users can manage optional categories in Security &amp; Email.
      </p>
      <p className="nconfig-help">
        <strong>
          Worker:{" "}
          {data
            ? !data.dispatch_enabled
              ? "Awaiting activation"
              : recent
                ? "Active"
                : "No recent heartbeat"
            : "Loading…"}
        </strong>
        {last && (
          <> · Last seen {new Date(last).toLocaleString("en-PH", { timeZone: "Asia/Manila" })}</>
        )}
      </p>
      <div className="nconfig-categories">
        {data &&
          Object.entries(data.catalog).map(([category, events]) => (
            <div className="nconfig-notification-category" key={category}>
              <strong>{category.replaceAll("_", " ")}</strong>
              <ul>
                {events.map((e) => (
                  <li key={e}>{e}</li>
                ))}
              </ul>
            </div>
          ))}
      </div>
      <p className="nconfig-help">
        {data?.counts.map((x) => `${x.state.replaceAll("_", " ")}: ${x.total}`).join(" · ") ||
          "No queued notifications yet."}
      </p>
      <div className="nconfig-table">
        <table>
          <thead>
            <tr>
              <th>Notification</th>
              <th>Category</th>
              <th>State</th>
              <th>Attempts</th>
              <th>Created (PHT)</th>
            </tr>
          </thead>
          <tbody>
            {data?.recent.map((x) => (
              <tr key={x.id}>
                <td>{x.event.replaceAll(".", " · ")}</td>
                <td>{x.category}</td>
                <td>
                  {x.state.replaceAll("_", " ")}
                  {x.error_code && (
                    <small className="block">{x.error_code.replaceAll("_", " ")}</small>
                  )}
                </td>
                <td>{x.attempts}</td>
                <td>
                  {new Date(x.created_at).toLocaleString("en-PH", { timeZone: "Asia/Manila" })}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!data?.recent.length && (
          <p className="nconfig-empty">No notification deliveries recorded yet.</p>
        )}
      </div>
      <p className="nconfig-help">
        Uncertain outcomes require review before a new send request. SMTP acceptance confirms mail
        server acceptance; inbox delivery and BIR acceptance are separate.
      </p>
    </section>
  );
}

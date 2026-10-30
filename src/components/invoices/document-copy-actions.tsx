import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useMerchantWorkAccess, workPermission } from "@/lib/merchant-access";
import { useEffect, useRef, useState } from "react";
import { Download, Loader2, Send } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiFetch, apiJson, readJsonResponse } from "@/lib/api-url";
import { toast } from "sonner";

type CopyInfo = {
  email: string;
  can_send: boolean;
  draft: boolean;
  smtp_enabled: boolean;
  history: { id: string; email: string; state: string; created_at: string }[];
};
export function DocumentCopyActions({
  source,
  id,
  number,
  compact = false,
}: {
  source: string;
  id: string;
  number: string;
  compact?: boolean;
}) {
  const work = useMerchantWorkAccess();
  const [open, setOpen] = useState(false),
    [info, setInfo] = useState<CopyInfo | null>(null),
    [email, setEmail] = useState("");
  const [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [busy, setBusy] = useState(false),
    [downloading, setDownloading] = useState(false),
    [attempted, setAttempted] = useState(false);
  const requestId = useRef(crypto.randomUUID());
  const path = `/api/document-copies/${encodeURIComponent(source)}/${encodeURIComponent(id)}`;
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    setInfo(null);
    setError("");
    setMessage("");
    setAttempted(false);
    requestId.current = crypto.randomUUID();
    apiJson<CopyInfo>(path, { signal: controller.signal })
      .then((data) => {
        if (!controller.signal.aborted) {
          setInfo(data);
          setEmail(data.email);
        }
      })
      .catch((e) => {
        if (!controller.signal.aborted) setError(e.message);
      });
    return () => controller.abort();
  }, [open, path]);
  useEffect(() => {
    if (!open || !info?.history.some((x) => ["queued", "preparing", "sending"].includes(x.state)))
      return;
    let active = true;
    const stop = subscribeRealtimeRefresh(() =>
      apiJson<CopyInfo>(path)
        .then((x) => {
          if (active) setInfo(x);
        })
        .catch(() => {}),
    );
    return () => {
      active = false;
      stop();
    };
  }, [open, info?.history, path]);
  async function download() {
    setDownloading(true);
    try {
      const response = await apiFetch(`${path}/pdf`);
      if (!response.ok) await readJsonResponse(response, "Unable to download the document.");
      const blob = await response.blob();
      if (!blob.type.includes("application/pdf"))
        throw new Error("The server did not return a PDF.");
      const url = URL.createObjectURL(blob),
        a = document.createElement("a");
      a.href = url;
      a.download = `${number.replace(/[^a-zA-Z0-9_-]/g, "-")}.pdf`;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 30000);
      toast.success("E-document PDF downloaded.");
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "PDF download failed.");
    } finally {
      setDownloading(false);
    }
  }
  return (
    <>
      <button
        type="button"
        className="mr-button"
        disabled={downloading || !workPermission(work, "documents.export")}
        title={`Download PDF ${number}`}
        aria-label={`Download PDF ${number}`}
        onClick={() => void download()}
      >
        {downloading ? <Loader2 size={16} className="animate-spin" /> : <Download size={16} />}
        {!compact && "Download PDF"}
      </button>
      {(!work || work.owner) && (
        <button
          type="button"
          className="mr-button"
          title={`Send copy to client ${number}`}
          aria-label={`Send copy to client ${number}`}
          onClick={() => setOpen(true)}
        >
          <Send size={16} />
          {!compact && "Send copy to client"}
        </button>
      )}
      <Dialog
        open={open}
        onOpenChange={(value) => {
          if (!busy) setOpen(value);
        }}
      >
        <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Send copy to client</DialogTitle>
            <DialogDescription>
              {number} · The customer receives an email with the E-document PDF attached.
            </DialogDescription>
          </DialogHeader>
          {!info && !error && <p role="status">Loading customer email…</p>}
          {error && (
            <p role="alert" className="text-sm text-red-700">
              {error}
            </p>
          )}
          {message && (
            <p role="status" className="text-sm text-green-700">
              {message}
            </p>
          )}
          {info && (
            <>
              <form
                className="space-y-4"
                onSubmit={async (e) => {
                  e.preventDefault();
                  if (busy || attempted) return;
                  setBusy(true);
                  setError("");
                  setAttempted(true);
                  try {
                    const result = await apiJson<{ message: string }>(`${path}/email`, {
                      method: "POST",
                      body: JSON.stringify({ email, request_id: requestId.current }),
                    });
                    setMessage(result.message);
                  } catch (err) {
                    setError(
                      err instanceof Error
                        ? err.message
                        : "Delivery could not be confirmed. Check history before sending again.",
                    );
                  } finally {
                    setBusy(false);
                    try {
                      setInfo(await apiJson<CopyInfo>(path));
                    } catch {
                      /* Keep the recorded outcome visible if history refresh fails. */
                    }
                  }
                }}
              >
                <label className="block text-sm font-medium">
                  Customer email
                  <input
                    autoComplete="email"
                    type="email"
                    required
                    maxLength={254}
                    className="mt-2 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-base"
                    placeholder="customer@example.com"
                    value={email}
                    disabled={busy || attempted}
                    onChange={(e) => setEmail(e.target.value)}
                  />
                </label>
                <p className="text-sm text-slate-500">
                  {info.email
                    ? "Review the address before sending. You can use another email for this copy."
                    : "No email is recorded. Enter the customer’s email to send this copy."}{" "}
                  This address applies to this delivery and preserves the original invoice.
                </p>
                {info.draft && (
                  <p className="text-sm text-amber-700">
                    Draft documents can be downloaded for review. Customer delivery is available
                    after issuance.
                  </p>
                )}
                {!info.smtp_enabled && (
                  <p className="text-sm text-amber-700">
                    Email delivery is disabled. An administrator needs to save, test and enable SMTP
                    in Configurations & Changes.
                  </p>
                )}
                <button
                  type="submit"
                  disabled={!info.can_send || busy || attempted}
                  className="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                  {busy ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />}
                  {busy ? "Queuing…" : attempted ? "Request recorded" : "Send PDF copy"}
                </button>
              </form>
              <div className="border-t pt-3">
                <h3 className="text-sm font-semibold">Recent delivery history</h3>
                {info.history.length ? (
                  <ul className="mt-2 space-y-2 text-sm">
                    {info.history.map((item) => (
                      <li key={item.id}>
                        <strong>{item.email}</strong>
                        <span className="block text-slate-500">
                          {item.state.replaceAll("_", " ")} ·{" "}
                          {new Date(item.created_at).toLocaleString("en-PH", {
                            timeZone: "Asia/Manila",
                          })}
                        </span>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="mt-2 text-sm text-slate-500">No email copies have been recorded.</p>
                )}
                <p className="mt-3 text-xs text-slate-500">
                  SMTP acceptance means the mail server accepted the message. Inbox delivery and BIR
                  acceptance are separate.
                </p>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}

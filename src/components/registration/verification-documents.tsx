import { useEffect, useState } from "react";
import {
  AlertTriangle,
  CheckCircle2,
  Download,
  FileText,
  LoaderCircle,
  RefreshCw,
  ShieldCheck,
  Upload,
} from "lucide-react";
import { apiFetch, apiJson, readJsonResponse } from "@/lib/api-url";
import { uploadVerificationDocument, verificationFileError } from "@/lib/verification-upload";
import { DocumentReview } from "./document-review";
import {
  documentSize,
  type DocumentItem as Item,
  type DocumentState as State,
} from "./document-types";
import { Eye } from "lucide-react";
import "./verification-documents.css";
export type VerificationDocumentDefinition = { name: string; description: string };
export type VerificationDocumentCatalog = Record<string, VerificationDocumentDefinition>;
export function RegistrationDocumentPicker({
  catalog,
  files,
  onChange,
}: {
  catalog: VerificationDocumentCatalog;
  files: Record<string, File>;
  onChange: (kind: string, file: File | null) => void;
}) {
  const [error, setError] = useState("");
  return (
    <div className="vd-panel">
      <div className="vd-notice">
        <ShieldCheck />
        <p>
          These files support NUERS business-account verification. You can continue without them and
          upload them later in <strong>Merchant Dashboard → Business Profile → Documents</strong>.
          Your account remains pending until all required documents are uploaded, reviewed and
          approved.
        </p>
      </div>
      <p className="vd-help">
        PDF, PNG or JPG · maximum 100 MB per file. Selected files upload securely when you submit
        your registration after email verification. Files stay in this tab until then; avoid closing
        it.
      </p>
      <div className="vd-grid">
        {Object.entries(catalog).map(([kind, item]) => (
          <article key={kind}>
            <FileText />
            <div>
              <h3>{item.name}</h3>
              <p>{item.description}</p>
              <label className="vd-file-label">
                <span>{files[kind] ? "Replace selected file" : "Choose file"}</span>
                <input
                  aria-label={item.name}
                  type="file"
                  accept="application/pdf,image/png,image/jpeg"
                  onChange={(e) => {
                    const file = e.target.files?.[0] || null;
                    if (
                      file &&
                      (file.size > 100 * 1024 * 1024 ||
                        !["application/pdf", "image/png", "image/jpeg"].includes(file.type))
                    ) {
                      setError("Choose a PDF, PNG or JPG no larger than 100 MB.");
                      e.target.value = "";
                      return;
                    }
                    setError("");
                    onChange(kind, file);
                  }}
                />
              </label>
              {files[kind] && (
                <div className="vd-selected">
                  <CheckCircle2 size={15} />
                  <span>
                    {files[kind].name} · {Math.ceil(files[kind].size / 1024)} KB
                  </span>
                  <button type="button" onClick={() => onChange(kind, null)}>
                    Remove
                  </button>
                </div>
              )}
            </div>
          </article>
        ))}
      </div>
      {error && (
        <p className="vd-error" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}
export function VerificationDocumentsPanel({
  merchantId,
  businessName,
  revision,
  onReviewed,
}: {
  merchantId?: string;
  businessName?: string;
  revision?: number;
  onReviewed?: () => Promise<void>;
}) {
  const [data, setData] = useState<State | null>(null),
    [loading, setLoading] = useState(true),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [review, setReview] = useState<Item | null>(null);
  const endpoint = merchantId
    ? `/api/admin/businesses/${merchantId}/verification-documents`
    : "/api/merchant/verification-documents";
  async function load() {
    setLoading(true);
    try {
      setData(await apiJson<State>(endpoint));
      setError("");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    const c = new AbortController();
    setLoading(true);
    apiJson<State>(endpoint, { signal: c.signal })
      .then(setData)
      .catch((e) => {
        if (e.name !== "AbortError") setError(e.message);
      })
      .finally(() => {
        if (!c.signal.aborted) setLoading(false);
      });
    return () => c.abort();
  }, [endpoint, revision]);
  async function upload(item: Item, file: File) {
    setError("");
    setMessage("");
    const validation = verificationFileError(file);
    if (validation) {
      setError(validation);
      return;
    }
    setBusy(true);
    try {
      setData(
        await uploadVerificationDocument<State>(item.kind, file, {
          onProgress: (percent) =>
            setMessage(`Uploading securely · ${percent}% — keep this page open.`),
        }),
      );
      setMessage(
        "Document saved privately for administrator review. Uploading does not activate the account.",
      );
    } catch (e) {
      setMessage("");
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function download(item: Item) {
    setBusy(true);
    setError("");
    try {
      const r = await apiFetch(
        `/api/${merchantId ? "admin" : "merchant"}/verification-documents/${item.id}`,
      );
      if (!r.ok) await readJsonResponse(r);
      const url = URL.createObjectURL(await r.blob()),
        a = document.createElement("a");
      a.href = url;
      a.download =
        r.headers.get("content-disposition")?.match(/filename="([^"]+)"/)?.[1] ||
        "NUERS-verification-document";
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="vd-panel" aria-label="Account verification documents">
      <header className="vd-heading">
        <div>
          <h3>Account Verification Documents</h3>
          <p>
            {data
              ? `${data.uploaded} of ${data.total} uploaded · ${data.verified} verified`
              : "Private supporting documents"}
          </p>
        </div>
        <button
          type="button"
          className="vd-button"
          onClick={() => void load()}
          disabled={loading || busy}
        >
          <RefreshCw size={15} />
          Refresh
        </button>
      </header>
      <div className={"vd-notice" + (data?.account?.active ? " vd-approved" : "")} role="status">
        {data?.account?.active ? <CheckCircle2 /> : <AlertTriangle />}
        <p>
          {data?.notice || "Loading the current document and account status…"}{" "}
          {!merchantId && data?.required && (
            <strong> Replacing a document returns an active account to pending review.</strong>
          )}
        </p>
      </div>
      {error && (
        <p role="alert" className="vd-error">
          {error}
        </p>
      )}
      {message && (
        <p role="status" className="vd-success">
          {message}
        </p>
      )}
      {loading && !data ? (
        <p>
          <LoaderCircle className="animate-spin" />
          Loading document checklist…
        </p>
      ) : (
        <div className="vd-grid">
          {data?.items.map((item) => (
            <article key={item.kind}>
              <FileText />
              <div>
                <div className="vd-item-title">
                  <h3>{item.name}</h3>
                  <span className={`vd-status ${item.status}`}>
                    {
                      {
                        missing: "Not uploaded",
                        pending: "Awaiting review",
                        verified: "Verified",
                        needs_changes: "Needs changes",
                      }[item.status]
                    }
                  </span>
                </div>
                <p>{item.description}</p>
                {item.filename && (
                  <small className="vd-filename">
                    {item.filename} · {documentSize(item.bytes)}
                    {item.version ? ` · Version ${item.version}` : ""}
                  </small>
                )}
                {item.review_note && (
                  <p>
                    <strong>Review note:</strong> {item.review_note}
                  </p>
                )}
                <div className="vd-actions">
                  {item.id && (
                    <button
                      type="button"
                      className="vd-button"
                      disabled={busy}
                      onClick={() => void download(item)}
                    >
                      <Download size={15} />
                      Download file
                    </button>
                  )}
                  {merchantId ? (
                    item.id && (
                      <button
                        className="vd-button primary"
                        type="button"
                        disabled={busy}
                        onClick={() => setReview(item)}
                      >
                        <Eye size={15} />
                        {item.status === "pending" ? "Preview & review" : "View document & history"}
                      </button>
                    )
                  ) : (
                    <label className={`vd-file-label ${busy ? "disabled" : ""}`}>
                      <span>
                        <Upload size={15} />
                        {item.id ? "Upload replacement" : "Upload document"}
                      </span>
                      <input
                        aria-label={`Upload ${item.name}`}
                        disabled={busy}
                        type="file"
                        accept="application/pdf,image/png,image/jpeg"
                        onChange={(e) => {
                          const file = e.target.files?.[0];
                          if (file) void upload(item, file);
                          e.target.value = "";
                        }}
                      />
                    </label>
                  )}
                </div>
              </div>
            </article>
          ))}
        </div>
      )}
      <p className="vd-help">
        Files are encrypted in private storage. Only the business account and authorized NUERS
        administrators can download them. PDF, PNG or JPG · maximum 100 MB each. Previous account
        documents are retained in the audit record.
      </p>
      {review && merchantId && (
        <DocumentReview
          merchantId={merchantId}
          businessName={businessName}
          item={review}
          onClose={() => setReview(null)}
          onSaved={async (state) => {
            setData(state);
            await onReviewed?.();
            setMessage("Document decision recorded. The current account status is shown above.");
          }}
        />
      )}
    </section>
  );
}

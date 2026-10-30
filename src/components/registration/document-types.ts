export type DocumentItem = {
  kind: string;
  name: string;
  description: string;
  id: string | null;
  status: "missing" | "pending" | "verified" | "needs_changes";
  filename: string | null;
  bytes: number | null;
  mime?: string;
  sha256?: string;
  created_at: string | null;
  uploaded_by?: string;
  reviewed_at?: string | null;
  reviewed_by?: string | null;
  review_note: string | null;
  version?: number;
  is_current?: boolean;
};
export type DocumentState = {
  items: DocumentItem[];
  required: boolean;
  uploaded: number;
  verified: number;
  total: number;
  ready_for_activation: boolean;
  account?: { status: string; stage: string; active: boolean };
  notice: string;
};
export const documentStatus = {
  missing: "Not uploaded",
  pending: "Awaiting review",
  verified: "Verified",
  needs_changes: "Needs changes",
};
export const documentDate = (value?: string | null) =>
  value
    ? new Date(value.includes("T") ? value : value.replace(" ", "T") + "+08:00").toLocaleString(
        "en-PH",
        {
          month: "short",
          day: "numeric",
          year: "numeric",
          hour: "numeric",
          minute: "2-digit",
          timeZone: "Asia/Manila",
        },
      )
    : "Not recorded";
export const documentSize = (value?: number | null) =>
  !value
    ? "—"
    : value >= 1048576
      ? (value / 1048576).toFixed(1) + " MB"
      : Math.ceil(value / 1024) + " KB";

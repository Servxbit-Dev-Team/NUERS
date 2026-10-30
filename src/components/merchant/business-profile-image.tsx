import "./business-profile-image.css";
import { useRef, useState } from "react";
import { Camera, Upload, Trash2, Loader2 } from "lucide-react";
import { apiFetch, readJsonResponse, publicAssetUrl } from "@/lib/api-url";
export function BusinessProfileImage({
  logo,
  name,
  onChange,
  disabled = false,
  invoice = false,
}: {
  logo: string | null;
  name: string;
  onChange: (url: string | null) => void;
  disabled?: boolean;
  invoice?: boolean;
}) {
  const input = useRef<HTMLInputElement>(null),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState("");
  async function change(file?: File) {
    setError("");
    setMessage("");
    if (
      file &&
      (!["image/png", "image/jpeg", "image/webp"].includes(file.type) ||
        file.size > 2 * 1024 * 1024)
    ) {
      setError("Choose a PNG, JPG or WebP image no larger than 2 MB.");
      return;
    }
    setBusy(true);
    try {
      let body: FormData | string;
      if (file) {
        body = new FormData();
        body.append("logo", file);
        body.append("expected_logo", logo || "");
      } else body = JSON.stringify({ expected_logo: logo });
      const r = await apiFetch(
        invoice ? "/api/merchant/invoice-settings/logo" : "/api/merchant/business-profile/image",
        { method: file ? "POST" : "DELETE", body },
      );
      const v = await readJsonResponse<{ logo: string | null; pending_approval?: boolean }>(r);
      onChange(v.logo);
      setMessage(
        invoice
          ? "Receipt logo saved. Preview and future PDF copies use this logo."
          : "Profile picture updated. No administrator approval is needed.",
      );
      if (!invoice) {
        window.dispatchEvent(new Event("nuers:profile-change-requested"));
        window.dispatchEvent(
          new CustomEvent("nuers:business-profile-updated", { detail: { logo_url: v.logo } }),
        );
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className={"business-image-control" + (invoice ? " invoice-logo-control" : "")}>
      <div className="business-image-avatar">
        {logo ? (
          <img
            src={publicAssetUrl(logo)}
            alt={`${name} ${invoice ? "receipt logo" : "profile picture"}`}
          />
        ) : (
          <span>
            {name
              .split(/\s/)
              .slice(0, 2)
              .map((s) => s[0])
              .join("")}
          </span>
        )}
        <Camera size={17} />
      </div>
      <div>
        <strong>{invoice ? "E-Receipt Logo" : "Business Profile Image"}</strong>
        <p>
          {invoice
            ? "Used on invoice and e-receipt previews, PDF copies and customer emails. Separate from your profile picture."
            : "Shown on your merchant dashboard, business profile and admin registry."}
        </p>
        <div className="business-image-actions">
          <button type="button" disabled={disabled || busy} onClick={() => input.current?.click()}>
            {busy ? <Loader2 className="bp-spin" size={15} /> : <Upload size={15} />}{" "}
            {logo
              ? invoice
                ? "Change receipt logo"
                : "Change image"
              : invoice
                ? "Upload receipt logo"
                : "Upload image"}
          </button>
          {logo && (
            <button type="button" disabled={disabled || busy} onClick={() => void change()}>
              <Trash2 size={15} />
              {invoice ? "Remove receipt logo" : "Remove image"}
            </button>
          )}
        </div>
        <small>
          PNG, JPG or WebP · up to 2 MB · saved immediately · no admin approval required.
        </small>
        {message && <p role="status">{message}</p>}
        {error && (
          <p role="alert" className="business-image-error">
            {error}
          </p>
        )}
      </div>
      <input
        ref={input}
        type="file"
        hidden
        accept="image/png,image/jpeg,image/webp"
        aria-label={invoice ? "E-receipt logo" : "Business profile image"}
        onChange={(e) => {
          const f = e.target.files?.[0];
          if (f) void change(f);
          e.target.value = "";
        }}
      />
    </div>
  );
}

import { useEffect, useState, type FormEvent } from "react";
import { Navigate } from "react-router-dom";
import QRCode from "qrcode";
import { useAuth } from "@/lib/auth";
import { ApiRateLimitError, retrySeconds } from "@/lib/rate-limit";
import { apiJson } from "@/lib/api-url";
import { MfaScreen, type MfaCodeMode, type MfaStage } from "./mfa-screen";

export function MfaPage() {
  const { user, loading, signOut } = useAuth();
  const [enrolled, setEnrolled] = useState<boolean | null>(null);
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [codeMode, setCodeMode] = useState<MfaCodeMode>("authenticator");
  const [secret, setSecret] = useState("");
  const [qr, setQr] = useState("");
  const [recovery, setRecovery] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [retryAt, setRetryAt] = useState(0),
    [clock, setClock] = useState(Date.now());
  const retryAfter = retrySeconds(retryAt, clock);
  function continueSession() {
    const requested = new URLSearchParams(window.location.search).get("returnTo");
    // Only return to this application's own paths; never replay the rejected mutation.
    const target =
      requested &&
      /^\/(?!\/)/.test(requested) &&
      !/[\\\u0000-\u0020]/.test(requested) &&
      !requested.startsWith("/security/mfa")
        ? requested
        : "/login";
    window.location.replace(target);
  }
  useEffect(() => {
    if (!retryAt) return;
    const tick = () => {
      const now = Date.now();
      setClock(now);
      if (now >= retryAt) {
        setRetryAt(0);
        setError("");
      }
    };
    const timer = setInterval(tick, 1000);
    window.addEventListener("focus", tick);
    document.addEventListener("visibilitychange", tick);
    return () => {
      clearInterval(timer);
      window.removeEventListener("focus", tick);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [retryAt]);
  function failed(e: unknown, fallback: string) {
    setError(e instanceof Error ? e.message : fallback);
    if (e instanceof ApiRateLimitError && e.retryAfterSeconds > 0) {
      setClock(Date.now());
      setRetryAt(Date.now() + e.retryAfterSeconds * 1000);
    }
  }

  async function loadStatus() {
    setError("");
    try {
      const status = await apiJson<{ enrolled: boolean; verified: boolean }>(
        "/api/auth/mfa/status",
      );
      if (status.verified && !recovery.length) {
        continueSession();
        return;
      }
      setEnrolled(status.enrolled);
    } catch (e) {
      failed(e, "Unable to load your security status.");
    }
  }
  useEffect(() => {
    let active = true;
    setEnrolled(null);
    setRetryAt(0);
    setClock(Date.now());
    setPassword("");
    setCode("");
    setCodeMode("authenticator");
    setSecret("");
    setQr("");
    setRecovery([]);
    setError("");
    if (user)
      apiJson<{ enrolled: boolean; verified: boolean }>("/api/auth/mfa/status")
        .then((x) => {
          if (active) {
            if (x.verified) {
              continueSession();
              return;
            }
            setEnrolled(x.enrolled);
          }
        })
        .catch((e) => {
          if (active) failed(e, "Unable to load your security status.");
        });
    return () => {
      active = false;
    };
  }, [user?.id]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy || enrolled === null || retrySeconds(retryAt) > 0) return;
    setBusy(true);
    setError("");
    try {
      if (!enrolled && !secret) {
        const result = await apiJson<{ secret: string; otpauth_url: string }>(
          "/api/auth/mfa/enroll",
          { method: "POST", body: JSON.stringify({ password }) },
        );
        setPassword("");
        const image = await QRCode.toDataURL(result.otpauth_url, {
          width: 220,
          margin: 3,
          errorCorrectionLevel: "M",
        });
        setSecret(result.secret);
        setQr(image);
        setCode("");
      } else if (!enrolled) {
        const result = await apiJson<{ recovery_codes: string[] }>("/api/auth/mfa/confirm", {
          method: "POST",
          body: JSON.stringify({ code }),
        });
        setSecret("");
        setQr("");
        setCode("");
        setRecovery(result.recovery_codes);
        setEnrolled(true);
      } else {
        await apiJson("/api/auth/mfa/verify", { method: "POST", body: JSON.stringify({ code }) });
        setCode("");
        continueSession();
      }
    } catch (e) {
      failed(e, "Verification failed.");
    } finally {
      setBusy(false);
    }
  }
  if (!loading && !user) return <Navigate to="/login" replace />;
  const stage: MfaStage =
    loading || enrolled === null
      ? "loading"
      : recovery.length
        ? "recovery"
        : enrolled
          ? "verify"
          : secret
            ? "enroll"
            : "password";
  return (
    <MfaScreen
      stage={stage}
      email={user?.email}
      password={password}
      code={code}
      codeMode={codeMode}
      secret={secret}
      qr={qr}
      recovery={recovery}
      busy={busy}
      error={error}
      retryAfter={retryAfter}
      onPassword={setPassword}
      onCode={(value) =>
        setCode(codeMode === "authenticator" ? value.replace(/\D/g, "").slice(0, 6) : value.trim())
      }
      onMode={(mode) => {
        setCodeMode(mode);
        setCode("");
        if (!retrySeconds(retryAt)) setError("");
      }}
      onSubmit={submit}
      onRetry={() => {
        if (!retrySeconds(retryAt)) void loadStatus();
      }}
      onRestart={() => {
        setSecret("");
        setQr("");
        setCode("");
        setPassword("");
        setError("");
      }}
      onContinue={() => {
        setRecovery([]);
        continueSession();
      }}
      onSignOut={() => {
        setSecret("");
        setQr("");
        setRecovery([]);
        setPassword("");
        setCode("");
        void signOut();
      }}
    />
  );
}

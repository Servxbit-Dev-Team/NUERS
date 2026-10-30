import { useState, type FormEvent } from "react";
import {
  ArrowRight,
  Check,
  CheckCheck,
  ChevronDown,
  CircleAlert,
  Clock3,
  Copy,
  Download,
  Eye,
  EyeOff,
  KeyRound,
  LoaderCircle,
  LockKeyhole,
  LogOut,
  ShieldCheck,
  Smartphone,
} from "lucide-react";
import "./mfa.css";

export type MfaStage = "loading" | "password" | "enroll" | "verify" | "recovery";
export type MfaCodeMode = "authenticator" | "recovery";
type Props = {
  stage: MfaStage;
  email?: string;
  password: string;
  code: string;
  codeMode: MfaCodeMode;
  secret: string;
  qr: string;
  recovery: string[];
  busy: boolean;
  error: string;
  retryAfter?: number;
  onPassword: (value: string) => void;
  onCode: (value: string) => void;
  onMode: (value: MfaCodeMode) => void;
  onSubmit: (event: FormEvent) => void;
  onRetry: () => void;
  onRestart: () => void;
  onContinue: () => void;
  onSignOut: () => void;
};

export function MfaScreen(p: Props) {
  const [showPassword, setShowPassword] = useState(false);
  const [manual, setManual] = useState(false);
  const [copied, setCopied] = useState(false);
  const [copyError, setCopyError] = useState("");
  const [saved, setSaved] = useState(false);
  const setup = p.stage === "password" || p.stage === "enroll";
  const title =
    p.stage === "recovery"
      ? "Save your recovery codes"
      : p.stage === "enroll"
        ? "Connect your authenticator"
        : p.stage === "verify"
          ? "Verify it’s you"
          : "Secure your NUERS account";
  const subtitle =
    p.stage === "recovery"
      ? "Keep these codes somewhere safe before you continue."
      : p.stage === "enroll"
        ? "Scan the QR code, then enter the code from your app."
        : p.stage === "verify"
          ? "Enter a verification code to continue to your dashboard."
          : "Add an extra layer of protection to your account.";
  async function copyKey() {
    setCopyError("");
    try {
      await navigator.clipboard.writeText(p.secret);
      setCopied(true);
    } catch {
      setCopyError("Copy is unavailable. Select the setup key and copy it manually.");
    }
  }
  function downloadCodes() {
    const text = [
      "NUERS · One-time recovery codes",
      "",
      ...p.recovery,
      "",
      "Each code can be used once. Keep this file private.",
    ].join("\n");
    const url = URL.createObjectURL(new Blob([text], { type: "text/plain;charset=utf-8" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = "NUERS-recovery-codes.txt";
    link.click();
    URL.revokeObjectURL(url);
  }
  return (
    <main className="nmfa">
      <header className="nmfa-top">
        <img
          src="/brand/nuers-official-logo.png"
          alt="NUERS — National Unified Electronic Receipt System"
        />
        <span>
          <LockKeyhole size={15} aria-hidden="true" /> Account security
        </span>
      </header>
      <div className="nmfa-stage">
        <div className="nmfa-layout">
          <aside className="nmfa-story" aria-label="NUERS account protection">
            <span className="nmfa-eyebrow">
              <ShieldCheck size={16} aria-hidden="true" /> NUERS SECURE ACCESS
            </span>
            <div className="nmfa-story-icon">
              <ShieldCheck size={46} strokeWidth={1.5} aria-hidden="true" />
            </div>
            <h2>
              Your account.
              <br />
              An extra layer
              <br />
              of protection.
            </h2>
            <p>Protect access to your business records with a code only you can provide.</p>
            <div className="nmfa-story-points">
              <div>
                <Smartphone size={21} aria-hidden="true" />
                <span>
                  <strong>Authenticator verification</strong>
                  <small>Use your preferred authenticator app.</small>
                </span>
              </div>
              <div>
                <KeyRound size={21} aria-hidden="true" />
                <span>
                  <strong>Recovery when you need it</strong>
                  <small>Keep one-time backup codes privately.</small>
                </span>
              </div>
              <div>
                <Clock3 size={21} aria-hidden="true" />
                <span>
                  <strong>Verified for this login</strong>
                  <small>Verify once when signing in to your account.</small>
                </span>
              </div>
            </div>
            <div className="nmfa-mission">
              DIGITAL TRANSACTIONS.
              <br />A STRONGER PHILIPPINES.
              <i />
            </div>
          </aside>
          <section className="nmfa-card" aria-labelledby="nmfa-title" aria-busy={p.busy}>
            <div className="nmfa-card-heading">
              <span className="nmfa-card-icon">
                {p.stage === "recovery" ? (
                  <CheckCheck size={27} aria-hidden="true" />
                ) : p.stage === "verify" ? (
                  <LockKeyhole size={27} aria-hidden="true" />
                ) : (
                  <Smartphone size={27} aria-hidden="true" />
                )}
              </span>
              <span className="nmfa-badge">
                <ShieldCheck size={13} aria-hidden="true" />
                {p.stage === "recovery" ? "Authenticator connected" : "Two-step verification"}
              </span>
            </div>
            <h1 id="nmfa-title">{title}</h1>
            <p className="nmfa-subtitle">{subtitle}</p>
            {p.email && (
              <div className="nmfa-account">
                <span className="nmfa-avatar" aria-hidden="true">
                  {p.email[0].toUpperCase()}
                </span>
                <span>
                  <small>Signed in as</small>
                  <strong>{p.email}</strong>
                </span>
              </div>
            )}
            {(setup || p.stage === "recovery") && (
              <ol className="nmfa-progress" aria-label="Authenticator setup progress">
                {["Confirm identity", "Connect app", "Save codes"].map((label, i) => {
                  const current = p.stage === "password" ? 0 : p.stage === "enroll" ? 1 : 2;
                  return (
                    <li
                      key={label}
                      className={i === current ? "current" : i < current ? "complete" : ""}
                      aria-current={i === current ? "step" : undefined}
                    >
                      <span>{i < current ? <Check size={13} aria-hidden="true" /> : i + 1}</span>
                      {label}
                    </li>
                  );
                })}
              </ol>
            )}
            {p.error && (
              <div className="nmfa-error" role="alert">
                <CircleAlert size={18} aria-hidden="true" />
                <span>{p.error}</span>
              </div>
            )}
            {p.stage === "loading" ? (
              <div className="nmfa-loading" role="status">
                <LoaderCircle size={22} className="nmfa-spin" aria-hidden="true" />
                <span>
                  {p.error
                    ? "Unable to load your security status."
                    : "Checking your account security…"}
                </span>
                {p.error && (
                  <button
                    type="button"
                    className="nmfa-outline"
                    disabled={!!p.retryAfter}
                    onClick={p.onRetry}
                  >
                    {p.retryAfter ? `Try again in ${p.retryAfter}s` : "Try again"}
                  </button>
                )}
              </div>
            ) : p.stage === "recovery" ? (
              <>
                <div className="nmfa-note">
                  <KeyRound size={19} aria-hidden="true" />
                  <p>
                    Each code works once if you can’t access your authenticator. These codes are
                    shown only now. Save them in your password manager or a secure offline location.
                  </p>
                </div>
                <div className="nmfa-codes" aria-label="One-time recovery codes">
                  {p.recovery.map((value, i) => (
                    <div key={value}>
                      <span>{String(i + 1).padStart(2, "0")}</span>
                      <code>{value}</code>
                    </div>
                  ))}
                </div>
                <button
                  type="button"
                  className="nmfa-outline nmfa-download"
                  onClick={downloadCodes}
                >
                  <Download size={17} aria-hidden="true" /> Download recovery codes
                </button>
                <label className="nmfa-saved">
                  <input
                    type="checkbox"
                    checked={saved}
                    onChange={(e) => setSaved(e.target.checked)}
                  />
                  I have stored my recovery codes in a safe place.
                </label>
                <button
                  className="nmfa-primary"
                  type="button"
                  disabled={!saved || p.busy}
                  onClick={p.onContinue}
                >
                  Continue to dashboard
                  <ArrowRight size={18} aria-hidden="true" />
                </button>
              </>
            ) : (
              <form onSubmit={p.onSubmit} className="nmfa-form">
                {p.stage === "password" ? (
                  <>
                    <label className="nmfa-label" htmlFor="nmfa-password">
                      Current password
                    </label>
                    <div className="nmfa-password">
                      <LockKeyhole size={18} aria-hidden="true" />
                      <input
                        id="nmfa-password"
                        type={showPassword ? "text" : "password"}
                        required
                        autoComplete="current-password"
                        value={p.password}
                        disabled={p.busy}
                        onChange={(e) => p.onPassword(e.target.value)}
                        placeholder="Enter your NUERS password"
                        aria-describedby="nmfa-password-help"
                      />
                      <button
                        type="button"
                        aria-label={showPassword ? "Hide password" : "Show password"}
                        aria-pressed={showPassword}
                        onClick={() => setShowPassword(!showPassword)}
                        disabled={p.busy}
                      >
                        {showPassword ? <EyeOff size={19} /> : <Eye size={19} />}
                      </button>
                    </div>
                    <p id="nmfa-password-help" className="nmfa-help">
                      Confirm your identity before connecting an authenticator.
                    </p>
                  </>
                ) : (
                  <>
                    {p.stage === "enroll" && (
                      <>
                        <div className="nmfa-scan">
                          <div className="nmfa-qr">
                            {p.qr ? (
                              <img
                                src={p.qr}
                                width={176}
                                height={176}
                                alt="Scan this QR code in your authenticator app"
                              />
                            ) : (
                              <span role="status">Preparing QR code…</span>
                            )}
                          </div>
                          <div>
                            <h2>Scan with your app</h2>
                            <p>
                              Open Google Authenticator, Microsoft Authenticator, or another
                              compatible app. Add an account and scan this code.
                            </p>
                            <span>
                              <Smartphone size={14} aria-hidden="true" /> Look for the NUERS account
                            </span>
                          </div>
                        </div>
                        <button
                          className="nmfa-manual-toggle"
                          type="button"
                          aria-expanded={manual}
                          aria-controls="nmfa-manual-key"
                          onClick={() => {
                            setManual(!manual);
                            setCopied(false);
                          }}
                        >
                          <KeyRound size={16} aria-hidden="true" />
                          Can’t scan the QR code?
                          <ChevronDown size={15} aria-hidden="true" />
                        </button>
                        {manual && (
                          <div id="nmfa-manual-key" className="nmfa-manual">
                            <p>
                              Choose manual entry in your app. Use a time-based, six-digit code and
                              this setup key:
                            </p>
                            <div>
                              <code>{p.secret}</code>
                              <button
                                className="nmfa-outline"
                                type="button"
                                onClick={copyKey}
                                aria-label={copied ? "Setup key copied" : "Copy setup key"}
                              >
                                {copied ? <Check size={16} /> : <Copy size={16} />}
                                {copied ? "Copied" : "Copy"}
                              </button>
                            </div>
                            {copyError && <p role="status">{copyError}</p>}
                          </div>
                        )}
                      </>
                    )}
                    {p.stage === "verify" && (
                      <div className="nmfa-methods" role="group" aria-label="Verification method">
                        <button
                          type="button"
                          aria-pressed={p.codeMode === "authenticator"}
                          disabled={p.busy}
                          onClick={() => p.onMode("authenticator")}
                        >
                          <Smartphone size={16} aria-hidden="true" />
                          Authenticator app
                        </button>
                        <button
                          type="button"
                          aria-pressed={p.codeMode === "recovery"}
                          disabled={p.busy}
                          onClick={() => p.onMode("recovery")}
                        >
                          <KeyRound size={16} aria-hidden="true" />
                          Recovery code
                        </button>
                      </div>
                    )}
                    <label className="nmfa-label" htmlFor="nmfa-code">
                      {p.codeMode === "recovery" && p.stage === "verify"
                        ? "One-time recovery code"
                        : "Six-digit verification code"}
                    </label>
                    <input
                      id="nmfa-code"
                      className={
                        p.codeMode === "recovery" && p.stage === "verify"
                          ? "nmfa-code nmfa-recovery-input"
                          : "nmfa-code"
                      }
                      type="text"
                      required
                      inputMode={
                        p.codeMode === "recovery" && p.stage === "verify" ? "text" : "numeric"
                      }
                      autoComplete="one-time-code"
                      autoCapitalize="none"
                      spellCheck={false}
                      minLength={p.codeMode === "recovery" && p.stage === "verify" ? 32 : 6}
                      maxLength={p.codeMode === "recovery" && p.stage === "verify" ? 32 : 6}
                      pattern={
                        p.codeMode === "recovery" && p.stage === "verify"
                          ? "[A-Za-z0-9]{32}"
                          : "[0-9]{6}"
                      }
                      placeholder={
                        p.codeMode === "recovery" && p.stage === "verify"
                          ? "Enter an unused recovery code"
                          : "000000"
                      }
                      value={p.code}
                      onChange={(e) => p.onCode(e.target.value)}
                      disabled={p.busy}
                      aria-describedby="nmfa-code-help"
                    />
                    <p id="nmfa-code-help" className="nmfa-help">
                      {p.codeMode === "recovery" && p.stage === "verify"
                        ? "Enter one of your saved 32-character codes. Each code can be used only once."
                        : "Enter the current code from any verified authenticator device."}
                    </p>
                  </>
                )}
                <button type="submit" className="nmfa-primary" disabled={p.busy || !!p.retryAfter}>
                  {p.retryAfter ? (
                    <>
                      <Clock3 size={18} aria-hidden="true" />
                      Try again in {p.retryAfter}s
                    </>
                  ) : p.busy ? (
                    <>
                      <LoaderCircle size={18} className="nmfa-spin" aria-hidden="true" />
                      {p.stage === "password" ? "Connecting…" : "Verifying…"}
                    </>
                  ) : (
                    <>
                      {p.stage === "password"
                        ? "Set up authenticator"
                        : p.stage === "enroll"
                          ? "Verify & connect"
                          : "Verify & continue"}
                      <ArrowRight size={18} aria-hidden="true" />
                    </>
                  )}
                </button>
                {p.stage === "enroll" && (
                  <button
                    type="button"
                    className="nmfa-text"
                    onClick={() => {
                      setManual(false);
                      p.onRestart();
                    }}
                    disabled={p.busy}
                  >
                    Start setup again
                  </button>
                )}
              </form>
            )}
            <div className="nmfa-private">
              <ShieldCheck size={16} aria-hidden="true" />
              <span>
                Keep setup keys, verification codes, and recovery codes private. NUERS support will
                never ask you to share them.
              </span>
            </div>
            <div className="nmfa-card-footer">
              <span>
                <LockKeyhole size={13} aria-hidden="true" /> NUERS account protection
              </span>
              <button type="button" disabled={p.busy} onClick={p.onSignOut}>
                <LogOut size={15} aria-hidden="true" />
                Sign out
              </button>
            </div>
          </section>
        </div>
      </div>
      <footer className="nmfa-footer">
        <span>© {new Date().getFullYear()} NUERS. All rights reserved.</span>
        <span>Digital transactions. A stronger Philippines.</span>
      </footer>
    </main>
  );
}

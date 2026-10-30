import { useEffect, useRef, useState, type FormEvent } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { ArrowRight, CircleHelp, Eye, EyeOff, LockKeyhole, Mail, X } from "lucide-react";
import { Dialog as DialogPrimitive } from "radix-ui";
import { useAuth } from "@/lib/auth";
import { apiUrl } from "@/lib/api-url";
import "./login.css";
import { SoftwareIdentity } from "@/components/enterprise/software-identity";

const REMEMBERED_EMAIL_KEY = "nuers_remembered_email";

function rememberedEmail() {
  try {
    return window.localStorage.getItem(REMEMBERED_EMAIL_KEY) ?? "";
  } catch {
    return "";
  }
}

const assurances = [
  {
    icon: "receipt",
    label: (
      <>
        BIR
        <br />
        Compliant
      </>
    ),
  },
  {
    icon: "shield",
    label: (
      <>
        Secure &amp;
        <br />
        Reliable
      </>
    ),
  },
  {
    icon: "reporting",
    label: (
      <>
        Real-Time
        <br />
        Reporting
      </>
    ),
  },
  {
    icon: "network",
    label: (
      <>
        Nationwide
        <br />
        Coverage
      </>
    ),
  },
];

export function LoginPage() {
  const { signIn, completeGoogleSignIn, loading: authLoading, user, profile } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const googleCompletionStarted = useRef(false);
  const oauthReturn = useRef(searchParams.has("google") || searchParams.has("google_error"));
  const navigate = useNavigate();
  const [email, setEmail] = useState(rememberedEmail);
  const [rememberMe, setRememberMe] = useState(() => Boolean(rememberedEmail()));
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [help, setHelp] = useState<"support" | "password" | null>(null);

  function openPortal(role: string | null) {
    if (role === "merchant") navigate("/merchant", { replace: true });
    else if (role === "consumer" || role === "client") navigate("/client", { replace: true });
    else if (role === "bir") navigate("/bir", { replace: true });
    else if (role === "rdo") navigate("/rdo", { replace: true });
    else navigate("/super-admin", { replace: true });
  }

  useEffect(() => {
    if (
      !authLoading &&
      user &&
      profile?.role &&
      !oauthReturn.current &&
      !googleCompletionStarted.current
    )
      openPortal(profile.role);
  }, [authLoading, user, profile?.role]);

  useEffect(() => {
    const googleError = searchParams.get("google_error");
    if (googleError) {
      const messages: Record<string, string> = {
        not_configured: "Google sign-in is not available yet. Please use your email and password.",
        cancelled: "Google sign-in was cancelled. Please try again.",
        invalid_state: "Your Google sign-in session expired. Please try again.",
        unverified: "Please use a Google account with a verified email address.",
        email_link_unavailable:
          "This Google account cannot be linked automatically. Please use your NUERS email and password.",
        account_not_found:
          "No NUERS account is registered with that Google email. Please register first.",
        account_unavailable:
          "This NUERS account is unavailable. Please contact your administrator.",
        identity_conflict:
          "The registered email and linked Google account do not match. Use your NUERS email and password.",
      };
      setError(messages[googleError] ?? "Unable to sign in with Google. Please try again.");
      const next = new URLSearchParams(searchParams);
      next.delete("google_error");
      setSearchParams(next, { replace: true });
      return;
    }
    if (searchParams.get("google") !== "success" || authLoading || googleCompletionStarted.current)
      return;
    googleCompletionStarted.current = true;
    setLoading(true);
    setError("");
    const next = new URLSearchParams(searchParams);
    next.delete("google");
    setSearchParams(next, { replace: true });
    completeGoogleSignIn()
      .then((result) => {
        if (result.error) setError(result.error);
        else openPortal(result.role);
      })
      .catch(() => setError("Unable to finish Google sign-in. Please try again."))
      .finally(() => setLoading(false));
  }, [searchParams, authLoading, completeGoogleSignIn, setSearchParams]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (loading) return;
    setError("");
    setLoading(true);
    try {
      const result = await signIn(email.trim(), password);
      if (result.error) {
        setError(result.error);
        return;
      }
      try {
        if (rememberMe) window.localStorage.setItem(REMEMBERED_EMAIL_KEY, email.trim());
        else window.localStorage.removeItem(REMEMBERED_EMAIL_KEY);
      } catch {
        // The login also works when browser storage is restricted.
      }
      openPortal(result.role);
    } catch {
      setError("Unable to sign in. Please try again.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="nuers-login">
      <div className="nuers-login__stage">
        <section className="nuers-login__hero" aria-label="About NUERS">
          <Link to="/" className="nuers-login__brand" aria-label="NUERS home">
            <img
              src="/brand/nuers-official-logo.png"
              alt="NUERS — National Unified Electronic Receipt System. Digital transactions. A stronger Philippines."
            />
          </Link>
          <h2>
            A Unified Philippines
            <br />
            Through Digital Receipts
          </h2>
          <p className="nuers-login__intro">
            Secure. Compliant. Real-Time.
            <br />
            For a Better Tomorrow.
          </p>
          <div className="nuers-login__assurances">
            {assurances.map(({ icon, label }) => (
              <div className="nuers-login__assurance" key={icon}>
                <img src={`/auth-assets/icon-${icon}.svg`} alt="" aria-hidden="true" />
                <span>{label}</span>
              </div>
            ))}
          </div>
        </section>

        <section className="nuers-login__card" aria-labelledby="login-title">
          <button type="button" className="nuers-login__help" onClick={() => setHelp("support")}>
            Need help? <CircleHelp aria-hidden="true" />
          </button>
          <div className="nuers-login__content">
            <h1 id="login-title">Welcome Back</h1>
            <p className="nuers-login__subtitle">Sign in to your NUERS account</p>
            {searchParams.get("session") === "invalid" && (
              <p className="nl-session-notice" role="status">
                Your session is no longer valid. Please sign in again.
              </p>
            )}
            <form
              className="nuers-login__form"
              onSubmit={handleSubmit}
              aria-label="Sign in to NUERS"
            >
              {error && (
                <div className="nuers-login__error" role="alert">
                  {error}
                </div>
              )}
              <div className="nuers-login__field">
                <Mail aria-hidden="true" />
                <input
                  id="email"
                  name="email"
                  type="email"
                  aria-label="Email Address"
                  placeholder="Email Address"
                  autoComplete="username"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  required
                  disabled={loading}
                />
              </div>
              <div className="nuers-login__field">
                <LockKeyhole aria-hidden="true" />
                <input
                  id="password"
                  name="password"
                  type={showPassword ? "text" : "password"}
                  aria-label="Password"
                  placeholder="Password"
                  autoComplete="current-password"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  required
                  disabled={loading}
                />
                <button
                  type="button"
                  className="nuers-login__password-toggle"
                  onClick={() => setShowPassword((value) => !value)}
                  aria-label={showPassword ? "Hide password" : "Show password"}
                  aria-pressed={showPassword}
                >
                  {showPassword ? <EyeOff aria-hidden="true" /> : <Eye aria-hidden="true" />}
                </button>
              </div>
              <div className="nuers-login__options">
                <label>
                  <input
                    type="checkbox"
                    checked={rememberMe}
                    onChange={(event) => setRememberMe(event.target.checked)}
                  />{" "}
                  <span>Remember me</span>
                </label>
                <button type="button" onClick={() => navigate("/forgot-password")}>
                  Forgot password?
                </button>
              </div>
              <button
                type="submit"
                className="nuers-login__submit"
                disabled={loading}
                aria-busy={loading}
              >
                <span>{loading ? "Signing In…" : "Sign In"}</span>
                <ArrowRight aria-hidden="true" />
              </button>
            </form>
            <div className="nuers-login__social">
              <div className="nuers-login__divider">
                <span>OR CONTINUE WITH</span>
              </div>
              <button
                type="button"
                className="nuers-login__google"
                disabled={loading}
                onClick={() => {
                  window.location.assign(apiUrl("/auth/google/redirect"));
                }}
              >
                <img src="/auth-assets/google-g.svg" alt="" aria-hidden="true" />
                <span>Sign in with Google</span>
              </button>
              <p className="text-center text-xs text-slate-500 mt-2">
                Use the email already registered with NUERS. Google sign-in does not create an
                account.
              </p>
            </div>
            <p className="nuers-login__register">
              Don’t have an account? <Link to="/register">Register Now</Link>
            </p>
          </div>
          <footer className="nuers-login__card-footer">
            <span>© 2026 NUERS. All rights reserved.</span>
            <SoftwareIdentity />
          </footer>
        </section>
      </div>
      <p className="nuers-login__mission">
        Supporting Businesses.
        <br />
        Strengthening the Nation.
      </p>
      <DialogPrimitive.Root
        open={help !== null}
        onOpenChange={(open) => {
          if (!open) setHelp(null);
        }}
      >
        <DialogPrimitive.Portal>
          <DialogPrimitive.Overlay className="nuers-login__dialog-backdrop" />
          <DialogPrimitive.Content className="nuers-login__dialog">
            <DialogPrimitive.Close aria-label="Close help">
              <X aria-hidden="true" />
            </DialogPrimitive.Close>
            <DialogPrimitive.Title>
              {help === "password" ? "Forgot your password?" : "Need help signing in?"}
            </DialogPrimitive.Title>
            <DialogPrimitive.Description>
              {help === "password"
                ? "Contact your NUERS account administrator to request a password reset. Have the email address registered to your account ready."
                : "Use the email address and password registered to your NUERS account. Your account opens the portal assigned to your role. For access or account assistance, contact your NUERS administrator."}
            </DialogPrimitive.Description>
            <Link to="/verify" onClick={() => setHelp(null)}>
              Verify a receipt without signing in <ArrowRight aria-hidden="true" />
            </Link>
          </DialogPrimitive.Content>
        </DialogPrimitive.Portal>
      </DialogPrimitive.Root>
    </main>
  );
}

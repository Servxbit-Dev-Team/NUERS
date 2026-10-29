import { useState } from "react";
import { Link } from "react-router-dom";
import {
  ArrowLeft, CheckCircle2, FileText, QrCode, Receipt, Search,
  ShieldCheck, Signature, XCircle,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Separator } from "@/components/ui/separator";
import { useAuth } from "@/lib/auth";
import { apiFetch, readJsonResponse } from "@/lib/api-url";

type VerificationResult = {
  receipt_number: string;
  merchant_name: string;
  merchant_tin: string;
  gross_amount: string;
  total_due: string;
  status: string;
  issued_at: string | null;
  signature_valid: boolean | null;
  authenticity: string;
};

function dashboardPathForRole(role: string | null | undefined, isAuthenticated: boolean): string {
  if (role === "admin" || role === "super_admin") return "/super-admin";
  if (role === "bir") return "/bir";
  if (role === "rdo") return "/rdo";
  if (role === "merchant" || role === "business" || role === "business_account") return "/merchant";
  if (role === "consumer" || role === "client") return "/client";
  return isAuthenticated ? "/" : "/login";
}

export function ReceiptVerificationPortal() {
  const { user, profile, loading: authLoading } = useAuth();
  const [receiptNumber, setReceiptNumber] = useState(() => new URLSearchParams(window.location.search).get("receipt") || "");
  const [result, setResult] = useState<VerificationResult | null>(null);
  const [message, setMessage] = useState("Sign in to look up receipts within your authorized account.");
  const [loading, setLoading] = useState(false);
  const dashboardPath = dashboardPathForRole(profile?.role, Boolean(user));

  async function verifyReceipt() {
    if (!receiptNumber.trim()) return;

    setLoading(true);
    setMessage("Checking your authorized NUERS records...");

    try {
      const response = await apiFetch(`/api/verify-receipt/${encodeURIComponent(receiptNumber.trim())}`);
      const payload = await readJsonResponse<{ receipt: VerificationResult }>(response, "Receipt could not be verified.");

      setResult(payload.receipt);
      setMessage(payload.receipt.authenticity);
    } catch (err) {
      setResult(null);
      setMessage(err instanceof Error ? err.message : "Verification service is unavailable. Please try again.");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="min-h-screen bg-background">
      <header className="border-b bg-card">
        <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
          <Link to="/" className="flex items-center gap-3">
            <img src="/NUERSLOGO.png" alt="NUERS" className="h-9 w-auto dark:hidden" />
            <img src="/NUERSLOGOWHITE.png" alt="" aria-hidden="true" className="hidden h-9 w-auto dark:block" />
            <div className="hidden sm:block">
              <p className="text-sm font-semibold text-foreground">National Unified Electronic Receipt System</p>
              <p className="text-xs text-muted-foreground">Account receipt lookup</p>
            </div>
          </Link>
          {authLoading ? (
            <Button variant="outline" size="sm" className="gap-2 text-xs" disabled>
              <ArrowLeft className="h-3.5 w-3.5" /> Loading dashboard
            </Button>
          ) : (
            <Button asChild variant="outline" size="sm" className="gap-2 text-xs">
              <Link to={dashboardPath}>
                <ArrowLeft className="h-3.5 w-3.5" /> Back to Dashboard
              </Link>
            </Button>
          )}
        </div>
      </header>

      <main className="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[1fr_380px] lg:px-8">
        <section className="space-y-6">
          <div>
            <Badge variant="secondary" className="gap-1.5 text-xs">
              <ShieldCheck className="h-3 w-3" /> NUERS receipt registry
            </Badge>
            <h1 className="mt-4 max-w-3xl text-3xl font-bold tracking-tight text-foreground">
              Look up your recorded receipts.
            </h1>
            <p className="mt-3 max-w-3xl text-sm leading-6 text-muted-foreground">
              Sign in as the issuing business, verified buyer or authorized administrator. A registry match
              confirms that NUERS holds the record; it does not establish signature validity or BIR acceptance.
            </p>
          </div>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm">Receipt Lookup</CardTitle>
              <p className="text-xs text-muted-foreground">Enter the document number printed on your receipt.</p>
            </CardHeader>
            <CardContent className="space-y-4">
              {!user && !authLoading && <Button asChild variant="outline"><Link to="/login">Sign in to access your receipts</Link></Button>}
              <div className="flex flex-col gap-2 sm:flex-row">
                <div className="relative flex-1">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    value={receiptNumber}
                    onChange={(event) => setReceiptNumber(event.target.value)}
                    placeholder="Enter receipt number"
                    className="pl-9 font-mono"
                  />
                </div>
                <Button onClick={verifyReceipt} disabled={loading || authLoading || !user} className="gap-2">
                  <ShieldCheck className="h-4 w-4" /> {loading ? "Verifying" : "Verify Receipt"}
                </Button>
              </div>

              <div className="rounded-lg border bg-secondary/40 p-4">
                <div className="flex items-start gap-3">
                  {result ? (
                    <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-success" />
                  ) : (
                    <QrCode className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                  )}
                  <div>
                    <p className="text-sm font-medium text-foreground">{result ? "Authorized registry match" : user ? "Ready to look up" : "Sign in required"}</p>
                    <p className="mt-1 text-xs leading-5 text-muted-foreground">{message}</p>
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          <div className="grid gap-4 md:grid-cols-3">
            {[
              { label: "Account access", detail: "Receipt lookup is restricted to your authorized account.", icon: ShieldCheck },
              { label: "Signature verification", detail: "Not enabled. A registry match does not verify a digital signature.", icon: Signature },
              { label: "Audit Evidence", detail: "Record verification method, timestamp, and outcome.", icon: FileText },
            ].map((item) => (
              <Card key={item.label}>
                <CardContent className="p-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-md bg-secondary">
                    <item.icon className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <h2 className="mt-4 text-sm font-semibold text-foreground">{item.label}</h2>
                  <p className="mt-2 text-xs leading-5 text-muted-foreground">{item.detail}</p>
                </CardContent>
              </Card>
            ))}
          </div>
        </section>

        <aside className="space-y-4">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm">Verification Result</CardTitle>
              <p className="text-xs text-muted-foreground">Available record details appear after an authorized lookup.</p>
            </CardHeader>
            <CardContent>
              {result ? (
                <div className="space-y-4">
                  <div className="flex items-center gap-3 rounded-lg border border-success/30 bg-success/5 p-3">
                    <CheckCircle2 className="h-5 w-5 text-success" />
                    <div>
                      <p className="text-sm font-semibold text-foreground">{result.authenticity}</p>
                      <p className="text-xs text-muted-foreground">Digital signature valid: {result.signature_valid === null ? "Not verified" : result.signature_valid ? "Yes" : "No"}</p>
                    </div>
                  </div>
                  <div className="space-y-2 text-xs">
                    {[
                      ["Receipt No.", result.receipt_number],
                      ["Business Account", result.merchant_name],
                      ["TIN", result.merchant_tin],
                      ["Gross Amount", `PHP ${Number(result.gross_amount).toLocaleString("en-PH", { minimumFractionDigits: 2 })}`],
                      ["Total Due", `PHP ${Number(result.total_due).toLocaleString("en-PH", { minimumFractionDigits: 2 })}`],
                      ["Status", result.status],
                      ["Issued At", result.issued_at ? new Date(result.issued_at).toLocaleString("en-PH") : "Not available"],
                    ].map(([label, value]) => (
                      <div key={label} className="flex justify-between gap-3">
                        <span className="text-muted-foreground">{label}</span>
                        <span className="text-right font-medium text-foreground">{value}</span>
                      </div>
                    ))}
                  </div>
                </div>
              ) : (
                <div className="flex flex-col items-center rounded-lg border border-dashed p-8 text-center">
                  <XCircle className="h-10 w-10 text-muted-foreground" />
                  <p className="mt-3 text-sm font-medium text-foreground">No receipt selected</p>
                  <p className="mt-1 text-xs leading-5 text-muted-foreground">
                    Only receipts within your authorized account are shown.
                  </p>
                </div>
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm">Lookup scope</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              {["Authorized account access", "Recorded receipt number", "Recorded issuer and amount", "Recorded document status"].map((item) => (
                <div key={item} className="flex items-center justify-between rounded-lg border p-3">
                  <span className="flex items-center gap-2 text-xs font-medium text-foreground">
                    <Receipt className="h-3.5 w-3.5 text-muted-foreground" /> {item}
                  </span>
                  <CheckCircle2 className="h-3.5 w-3.5 text-success" />
                </div>
              ))}
              <Separator />
              <p className="text-[10px] leading-4 text-muted-foreground">
                Receipt numbers and TINs do not grant access to another account. Authorized lookups are recorded; public QR and signature verification are not enabled.
              </p>
            </CardContent>
          </Card>
        </aside>
      </main>
    </div>
  );
}

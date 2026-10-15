import { useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
  ArrowRight,
  Building2,
  CheckCircle2,
  Eye,
  EyeOff,
  IdCard,
  KeyRound,
  Mail,
  Phone,
  ShieldCheck,
  Store,
  UserRound,
  Wallet,
} from "lucide-react";
import { AuthShell } from "@/components/layouts/auth-shell";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { cn } from "@/lib/utils";
import { useAuth } from "@/lib/auth";

type AccountType = "merchant" | "client";

type RegisterFormState = {
  businessName: string;
  tin: string;
  firstName: string;
  lastName: string;
  email: string;
  phone: string;
  password: string;
  confirmPassword: string;
  accepted: boolean;
};

const initialForm: RegisterFormState = {
  businessName: "",
  tin: "",
  firstName: "",
  lastName: "",
  email: "",
  phone: "",
  password: "",
  confirmPassword: "",
  accepted: false,
};

const accountTypes = [
  {
    value: "merchant" as const,
    title: "Business Account",
    description: "Businesses, LGUs, schools, hospitals",
    icon: Store,
  },
  {
    value: "client" as const,
    title: "Client",
    description: "Citizens, taxpayers, students, patients",
    icon: Wallet,
  },
];

export function ClientRegisterPage() {
  const { signUp } = useAuth();
  const navigate = useNavigate();
  const [accountType, setAccountType] = useState<AccountType>("client");
  const [form, setForm] = useState<RegisterFormState>(initialForm);
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  function updateField<K extends keyof RegisterFormState>(key: K, value: RegisterFormState[K]) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError("");

    if (form.password !== form.confirmPassword) {
      setError("Passwords do not match.");
      return;
    }

    if (!form.accepted) {
      setError("Please confirm the NUERS account terms before continuing.");
      return;
    }

    const fullName =
      accountType === "merchant"
        ? form.businessName.trim()
        : `${form.firstName.trim()} ${form.lastName.trim()}`.trim();
    const organization = accountType === "merchant" ? form.businessName.trim() : "Citizen Account";

    setLoading(true);
    const { error: signUpError } = await signUp(form.email, form.password, {
      role: accountType,
      full_name: fullName,
      organization,
      tin: form.tin,
      phone: form.phone,
    });
    setLoading(false);

    if (signUpError) {
      setError(signUpError);
      return;
    }

    navigate(accountType === "merchant" ? "/merchant" : "/client");
  }

  return (
    <AuthShell
      eyebrow="NUERS onboarding"
      title="Create your account"
      description="Open a secure NUERS profile for electronic receipts, digital payments, receipt verification, and compliance-ready transaction records."
      heroTitle="Join the national electronic receipt network"
      heroDescription="Register business accounts and service recipients into a unified platform built for secure collections, transparent reporting, and digital receipt access."
      footer={
        <>
          Already have an account?{" "}
          <Link to="/login" className="font-semibold text-primary hover:underline">
            Sign in
          </Link>
        </>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-5">
        <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Account type">
          {accountTypes.filter(type => type.value === "client").map((type) => {
            const Icon = type.icon;
            const selected = accountType === type.value;
            return (
              <button
                key={type.value}
                type="button"
                onClick={() => setAccountType(type.value)}
                className={cn(
                  "rounded-lg border p-4 text-left transition-colors",
                  selected
                    ? "border-primary bg-primary/5 shadow-[inset_0_0_0_1px_hsl(var(--primary))]"
                    : "border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50",
                )}
                aria-pressed={selected}
              >
                <div className="flex items-center justify-between gap-3">
                  <div className={cn(
                    "flex h-10 w-10 items-center justify-center rounded-lg",
                    selected ? "bg-primary text-white" : "bg-slate-100 text-slate-500",
                  )}>
                    <Icon className="h-5 w-5" />
                  </div>
                  {selected && <CheckCircle2 className="h-4 w-4 text-primary" />}
                </div>
                <p className="mt-3 text-sm font-semibold text-slate-950">{type.title}</p>
                <p className="mt-1 text-xs leading-5 text-slate-500">{type.description}</p>
              </button>
            );
          })}
        </div>

        {error && (
          <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-xs font-medium text-destructive">
            {error}
          </div>
        )}

        {accountType === "merchant" ? (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              id="businessName"
              label="Business name"
              icon={Building2}
              placeholder="Your Company Inc."
              value={form.businessName}
              onChange={(value) => updateField("businessName", value)}
              required
            />
            <Field
              id="tin"
              label="TIN number"
              icon={IdCard}
              placeholder="000-000-000-000"
              value={form.tin}
              onChange={(value) => updateField("tin", value)}
              required
            />
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            <Field
              id="firstName"
              label="First name"
              icon={UserRound}
              placeholder="Juan"
              value={form.firstName}
              onChange={(value) => updateField("firstName", value)}
              required
            />
            <Field
              id="lastName"
              label="Last name"
              icon={UserRound}
              placeholder="Dela Cruz"
              value={form.lastName}
              onChange={(value) => updateField("lastName", value)}
              required
            />
          </div>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            id="email"
            label={accountType === "merchant" ? "Business email" : "Email address"}
            icon={Mail}
            type="email"
            placeholder={accountType === "merchant" ? "finance@company.com" : "you@email.com"}
            value={form.email}
            onChange={(value) => updateField("email", value)}
            autoComplete="email"
            required
          />
          <Field
            id="phone"
            label="Contact number"
            icon={Phone}
            placeholder="+63 XXX XXX XXXX"
            value={form.phone}
            onChange={(value) => updateField("phone", value)}
            autoComplete="tel"
            required
          />
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <PasswordField
            id="password"
            label="Password"
            value={form.password}
            showPassword={showPassword}
            setShowPassword={setShowPassword}
            onChange={(value) => updateField("password", value)}
            autoComplete="new-password"
          />
          <PasswordField
            id="confirmPassword"
            label="Confirm password"
            value={form.confirmPassword}
            showPassword={showPassword}
            setShowPassword={setShowPassword}
            onChange={(value) => updateField("confirmPassword", value)}
            autoComplete="new-password"
          />
        </div>

        <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
          <div className="flex items-start gap-3">
            <ShieldCheck className="mt-0.5 h-5 w-5 text-primary" />
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-sm font-semibold text-slate-900">Security-ready account</p>
                <Badge variant="secondary" className="rounded-md bg-white text-slate-600">
                  MFA capable
                </Badge>
              </div>
              <p className="mt-1 text-xs leading-5 text-slate-500">
                Your profile is prepared for role-based access, receipt history, compliance records, and audit logging.
              </p>
            </div>
          </div>
        </div>

        <label className="flex items-start gap-3 text-xs leading-5 text-slate-600">
          <input
            type="checkbox"
            checked={form.accepted}
            onChange={(event) => updateField("accepted", event.target.checked)}
            className="mt-1 h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary"
          />
          <span>
            I confirm that the account information is accurate, agree to the <Link to="/terms-of-service" target="_blank" rel="noopener noreferrer" className="underline">Terms of Service</Link>, and acknowledge the <Link to="/privacy-notice" target="_blank" rel="noopener noreferrer" className="underline">Privacy Notice</Link>.
          </span>
        </label>

        <Button type="submit" className="h-11 w-full gap-2 bg-[#082844] text-white hover:bg-[#0b3559]" disabled={loading}>
          {loading ? "Creating account..." : accountType === "merchant" ? "Register business account" : "Create client account"}
          {!loading && <ArrowRight className="h-4 w-4" />}
        </Button>
      </form>
    </AuthShell>
  );
}

function Field({
  id,
  label,
  icon: Icon,
  value,
  onChange,
  type = "text",
  placeholder,
  autoComplete,
  required,
}: {
  id: string;
  label: string;
  icon: React.ElementType;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  placeholder: string;
  autoComplete?: string;
  required?: boolean;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id} className="text-sm font-medium">{label}</Label>
      <div className="relative">
        <Icon className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <Input
          id={id}
          type={type}
          placeholder={placeholder}
          value={value}
          autoComplete={autoComplete}
          className="h-11 pl-10"
          onChange={(event) => onChange(event.target.value)}
          required={required}
        />
      </div>
    </div>
  );
}

function PasswordField({
  id,
  label,
  value,
  onChange,
  showPassword,
  setShowPassword,
  autoComplete,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  showPassword: boolean;
  setShowPassword: (value: boolean) => void;
  autoComplete: string;
}) {
  return (
    <div className="space-y-2">
      <Label htmlFor={id} className="text-sm font-medium">{label}</Label>
      <div className="relative">
        <KeyRound className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <Input
          id={id}
          type={showPassword ? "text" : "password"}
          placeholder={id === "password" ? "Strong password" : "Repeat password"}
          value={value}
          autoComplete={autoComplete}
          className="h-11 pl-10 pr-10"
          onChange={(event) => onChange(event.target.value)}
          required
        />
        <button
          type="button"
          onClick={() => setShowPassword(!showPassword)}
          className="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500"
          aria-label={showPassword ? "Hide password" : "Show password"}
        >
          {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
        </button>
      </div>
    </div>
  );
}

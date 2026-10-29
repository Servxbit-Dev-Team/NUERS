import { Link } from "react-router-dom";
import { BarChart3, Landmark, LockKeyhole, ReceiptText, ShieldCheck } from "lucide-react";
import { Badge } from "@/components/ui/badge";

type AuthShellProps = {
  eyebrow: string;
  title: string;
  description: string;
  heroTitle: string;
  heroDescription: string;
  children: React.ReactNode;
  footer: React.ReactNode;
};

const assuranceItems = [
  { label: "Encrypted eOR", value: "AES-256", icon: LockKeyhole },
  { label: "Audit trails", value: "Real time", icon: ReceiptText },
  { label: "Revenue view", value: "Nationwide", icon: BarChart3 },
  { label: "Regulator ready", value: "BIR aligned", icon: Landmark },
];

export function AuthShell({
  eyebrow,
  title,
  description,
  heroTitle,
  heroDescription,
  children,
  footer,
}: AuthShellProps) {
  return (
    <main className="min-h-screen bg-slate-50 text-slate-950">
      <div className="grid min-h-screen lg:grid-cols-[minmax(0,1.04fr)_minmax(480px,0.96fr)]">
        <section className="relative hidden overflow-hidden bg-[#082844] text-white lg:flex">
          <img
            src="/nuers_wBG.jpg"
            alt=""
            className="absolute inset-0 h-full w-full object-cover opacity-15"
          />
          <div className="absolute inset-0 bg-[linear-gradient(118deg,#06243d_0%,#082844_52%,rgba(10,87,128,0.9)_100%)]" />
          <div className="absolute inset-x-0 bottom-0 h-1 bg-[linear-gradient(90deg,#f59e0b_0%,#f97316_32%,#38bdf8_66%,#ffffff_100%)]" />

          <div className="relative z-10 flex min-h-screen w-full flex-col justify-between px-14 py-10 xl:px-20 xl:py-12 2xl:px-24">
            <div className="flex items-center justify-between">
              <img src="/NUERSLOGOWHITE.png" alt="NUERS" className="h-12 w-auto" />
              <Badge className="rounded-md border-white/20 bg-white/10 text-white hover:bg-white/10">
                Secure Access
              </Badge>
            </div>

            <div className="max-w-2xl">
              <div className="mb-5 inline-flex items-center gap-2 rounded-md border border-white/15 bg-white/10 px-3 py-2 text-xs font-medium text-white/80">
                <ShieldCheck className="h-4 w-4 text-amber-300" />
                National Unified Electronic Receipt System
              </div>
              <h2 className="text-4xl font-semibold leading-tight tracking-normal text-white xl:text-5xl">
                {heroTitle}
              </h2>
              <p className="mt-5 max-w-xl text-sm leading-6 text-white/72">
                {heroDescription}
              </p>

              <div className="mt-8 grid max-w-xl grid-cols-2 gap-3">
                {assuranceItems.map((item) => {
                  const Icon = item.icon;
                  return (
                    <div key={item.label} className="rounded-lg border border-white/12 bg-white/[0.07] p-4">
                      <Icon className="h-4 w-4 text-amber-300" />
                      <p className="mt-3 text-sm font-medium text-white">{item.value}</p>
                      <p className="mt-1 text-xs text-white/55">{item.label}</p>
                    </div>
                  );
                })}
              </div>
            </div>

            <div className="flex items-center justify-between text-xs text-white/50">
              <span>Bureau of Internal Revenue | Republic of the Philippines</span>
              <Link to="/verify" className="font-medium text-white/70 hover:text-white">
                Verify receipt
              </Link>
            </div>
          </div>
        </section>

        <section className="flex min-h-screen flex-col bg-[linear-gradient(180deg,#ffffff_0%,#f8fafc_62%,#eff6ff_100%)] px-5 py-6 sm:px-8 lg:px-12">
          <div className="flex items-center justify-between lg:hidden">
            <img src="/NUERSLOGO.png" alt="NUERS" className="h-9 w-auto" />
            <Link to="/verify" className="text-xs font-medium text-primary hover:underline">
              Verify receipt
            </Link>
          </div>

          <div className="mx-auto flex w-full max-w-[520px] flex-1 flex-col justify-center py-8">
            <div className="rounded-lg border border-slate-200/80 bg-white p-6 shadow-[0_18px_55px_rgba(15,23,42,0.08)] sm:p-8">
              <div className="mb-7">
                <Badge variant="secondary" className="rounded-md bg-amber-50 text-amber-700 hover:bg-amber-50">
                  {eyebrow}
                </Badge>
                <h1 className="mt-4 text-3xl font-semibold tracking-normal text-slate-950">{title}</h1>
                <p className="mt-3 text-sm leading-6 text-slate-600">{description}</p>
              </div>

              {children}
            </div>

            <div className="mt-6 text-center text-sm text-slate-600">{footer}</div>
          </div>
        </section>
      </div>
    </main>
  );
}

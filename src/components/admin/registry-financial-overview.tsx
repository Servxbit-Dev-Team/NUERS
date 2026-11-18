import { ArrowDownToLine, ArrowLeftRight, Clock3, Link2, ReceiptText, Wallet } from "lucide-react";
import type { ReactNode } from "react";
import type { Business } from "@/pages/business-registry";
import { formatTin } from "@/lib/tin";
import { publicAssetUrl } from "@/lib/api-url";

export type MerchantOverview = {
  currency: string;
  environment: string;
  available_centavos: number;
  reserved_centavos: number;
  transactions: number;
  transaction_amount_centavos: number;
  successful_transactions: number;
  failed_transactions: number;
  pending_transactions: number;
  charged_centavos: number;
  charged_transactions: number;
  deposits_centavos: number;
  withdrawals_centavos: number;
  pending_topups: number;
  pending_topups_centavos: number;
  live_keys: number;
  sandbox_keys: number;
  sandbox_events: number;
  last_transaction_at: string | null;
  last_sandbox_at: string | null;
  wallet_updated_at: string | null;
  wallet_status: "not_funded" | "empty" | "low" | "funded";
  low_balance_threshold_centavos: number;
};
export type RegistryFinancialSummary = Pick<
  MerchantOverview,
  | "available_centavos"
  | "reserved_centavos"
  | "transactions"
  | "transaction_amount_centavos"
  | "charged_centavos"
  | "charged_transactions"
  | "deposits_centavos"
  | "pending_topups"
  | "pending_topups_centavos"
> & { low_balance_wallets?: number };
const money = (cents: number) =>
  new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(cents / 100);
const count = (value: number) => value.toLocaleString("en-PH");
const date = (value?: string | null) =>
  value
    ? new Date(value).toLocaleString("en-PH", {
        timeZone: "Asia/Manila",
        dateStyle: "medium",
        timeStyle: "short",
      })
    : "No activity yet";
const walletLabels = {
  not_funded: "Not funded",
  empty: "Empty",
  low: "Low balance",
  funded: "Funded",
};

export function RegistryFinancialCards({
  data,
  individual = false,
}: {
  data: RegistryFinancialSummary;
  individual?: boolean;
}) {
  const cards = [
    {
      name: "Available Wallet",
      value: money(data.available_centavos),
      note: `${money(data.reserved_centavos)} reserved`,
      icon: Wallet,
      color: "blue",
    },
    {
      name: "Total Transactions",
      value: count(data.transactions),
      note: "All recorded Live transactions",
      icon: ArrowLeftRight,
      color: "green",
    },
    {
      name: "Transaction Amount",
      value: money(data.transaction_amount_centavos),
      note: "All recorded Live amounts",
      icon: ReceiptText,
      color: "blue",
    },
    {
      name: "NUERS Charged",
      value: money(data.charged_centavos),
      note: `${count(data.charged_transactions)} posted fee debits`,
      icon: ReceiptText,
      color: "purple",
    },
    {
      name: "Total Deposits",
      value: money(data.deposits_centavos),
      note: "Credited funds only · all time",
      icon: ArrowDownToLine,
      color: "green",
    },
    {
      name: "Pending Top-Ups",
      value: money(data.pending_topups_centavos),
      note: `${count(data.pending_topups)} awaiting review`,
      icon: Clock3,
      color: "yellow",
    },
  ];
  return (
    <section className="br-financial-overview" aria-label="Merchant financial overview">
      <header>
        <div>
          <h2>{individual ? "Merchant Financial Overview" : "Merchant Portfolio Overview"}</h2>
          <p>
            {individual ? "This merchant" : "All merchants matching the current filters"} · Live
            records · All time · PHP
          </p>
        </div>
        <span className="br-live-label">
          <i />
          Live data
        </span>
      </header>
      <div className="br-financial-cards">
        {cards.map(({ name, value, note, icon: Icon, color }) => (
          <article key={name}>
            <span className={`ow-metric-icon ${color}`}>
              <Icon size={22} />
            </span>
            <div>
              <h3>{name}</h3>
              <strong>{value}</strong>
              <small>{note}</small>
            </div>
          </article>
        ))}
      </div>
      <p className="br-financial-note">
        Wallet balances use posted entries. Pending top-ups and Sandbox activity are separate.
        Transaction totals include all recorded statuses; they are not net sales.
      </p>
    </section>
  );
}

export function RegistryFinancialTable({
  rows,
  loading,
  onView,
  onWallet,
  actions,
}: {
  rows: Business[];
  loading: boolean;
  onView: (business: Business) => void;
  onWallet: (id: string) => void;
  actions: (business: Business) => ReactNode;
}) {
  return (
    <div
      className="ow-scroll br-financial-scroll"
      tabIndex={0}
      role="region"
      aria-label="Merchant balances and transaction overview"
    >
      <table className="br-financial-table">
        <thead>
          <tr>
            <th scope="col">Merchant / Registration</th>
            <th scope="col">Available Wallet</th>
            <th scope="col">Live Transactions</th>
            <th scope="col">Transaction Amount</th>
            <th scope="col">NUERS Charged</th>
            <th scope="col">Deposits / Funding</th>
            <th scope="col">API / Sandbox</th>
            <th scope="col">Last Transaction (PHT)</th>
            <th scope="col" className="br-actions-cell">
              Actions
            </th>
          </tr>
        </thead>
        <tbody>
          {rows.map((b) => {
            const o = b.overview;
            return (
              <tr key={b.id}>
                <td>
                  <div className="br-business">
                    <span className="br-avatar">
                      {b.logo_url ? (
                        <img src={publicAssetUrl(b.logo_url)} alt="" />
                      ) : (
                        b.business_name.slice(0, 2).toUpperCase()
                      )}
                    </span>
                    <div>
                      <button title={b.business_name} onClick={() => onView(b)}>
                        {b.business_name}
                      </button>
                      <small>
                        {formatTin(b.tin)} · {b.vat_registered ? "VAT" : "Non-VAT"}
                      </small>
                      <small>{b.owner_name || "Representative not recorded"}</small>
                    </div>
                  </div>
                  <div className="br-merchant-meta">
                    <span className={`ow-badge ${b.status}`}>
                      {b.status === "pending" ? "For onboarding" : b.status}
                    </span>
                    <span>
                      RDO {b.rdo_code || "—"} · {b.city || b.region || "Location not recorded"}
                    </span>
                  </div>
                </td>
                {o ? (
                  <>
                    <td className="br-money">
                      <button
                        className="br-wallet-link"
                        onClick={() => onWallet(b.id)}
                        aria-label={`Open wallet for ${b.business_name}`}
                      >
                        {money(o.available_centavos)}
                      </button>
                      <span
                        className={`br-wallet-state ${o.wallet_status}`}
                        title={`Low balance threshold ${money(o.low_balance_threshold_centavos)}`}
                      >
                        {walletLabels[o.wallet_status]}
                      </span>
                      <small>{money(o.reserved_centavos)} reserved</small>
                    </td>
                    <td>
                      <strong>{count(o.transactions)}</strong>
                      <small>{count(o.successful_transactions)} successful</small>
                      <small className={o.failed_transactions > 0 ? "br-attention" : ""}>
                        {count(o.failed_transactions)} failed / cancelled ·{" "}
                        {count(o.pending_transactions)} other / pending
                      </small>
                    </td>
                    <td className="br-money">
                      <strong>{money(o.transaction_amount_centavos)}</strong>
                      <small>Live · all statuses</small>
                    </td>
                    <td className="br-money">
                      <strong>{money(o.charged_centavos)}</strong>
                      <small>{count(o.charged_transactions)} fee debits</small>
                    </td>
                    <td className="br-money">
                      <strong>{money(o.deposits_centavos)}</strong>
                      <small>{money(o.withdrawals_centavos)} withdrawn</small>
                      <small className={o.pending_topups > 0 ? "br-attention" : ""}>
                        {count(o.pending_topups)} pending · {money(o.pending_topups_centavos)}
                      </small>
                    </td>
                    <td>
                      <span className="br-key-label">
                        <Link2 size={13} />
                        {count(o.live_keys)} Live keys
                      </span>
                      <small>{count(o.sandbox_keys)} Sandbox keys</small>
                      <small>{count(o.sandbox_events)} Sandbox events · uncharged</small>
                    </td>
                    <td>
                      <time dateTime={o.last_transaction_at || undefined}>
                        {date(o.last_transaction_at)}
                      </time>
                      <small>Received by NUERS</small>
                    </td>
                  </>
                ) : (
                  <td colSpan={7}>Financial overview unavailable</td>
                )}
                <td className="br-actions-cell">{actions(b)}</td>
              </tr>
            );
          })}
          {!rows.length && (
            <tr>
              <td colSpan={9} className="ow-empty">
                {loading ? "Loading merchant overview…" : "No merchants match these filters."}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

import { BanknoteArrowDown, PhilippinePeso, ReceiptText, Wallet } from "lucide-react";
import "./financial-summary.css";

export type FinancialSummary = {
  currency: "PHP";
  environment: "live";
  period: "today" | "all_time";
  transaction_amount_centavos: number;
  nuers_charged_centavos: number;
  charged_transactions: number;
  merchant_wallet_centavos?: number;
  merchant_deposit_centavos?: number;
};
const peso = new Intl.NumberFormat("en-PH", {
  style: "currency",
  currency: "PHP",
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

export function AdminFinancialSummary({ summary }: { summary?: FinancialSummary | null }) {
  if (!summary) return null;
  const today = summary.period === "today";
  const period = today ? "Today (PHT)" : "All time";
  const cards = [
    {
      label: "Total Transaction Amount",
      value: summary.transaction_amount_centavos,
      icon: PhilippinePeso,
      color: "blue",
      note: `${period} · all recorded Live transactions`,
    },
    {
      label: "Total NUERS Charged",
      value: summary.nuers_charged_centavos,
      icon: ReceiptText,
      color: "purple",
      note: `${period} · ${summary.charged_transactions.toLocaleString("en-PH")} posted fee ${summary.charged_transactions === 1 ? "debit" : "debits"}`,
    },
    ...(summary.merchant_wallet_centavos === undefined
      ? []
      : [
          {
            label: "Total Merchant Wallet",
            value: summary.merchant_wallet_centavos,
            icon: Wallet,
            color: "green",
            note: "Current available balance · all merchant wallets",
          },
        ]),
    ...(summary.merchant_deposit_centavos === undefined
      ? []
      : [
          {
            label: "Total Merchant Deposit",
            value: summary.merchant_deposit_centavos,
            icon: BanknoteArrowDown,
            color: "blue",
            note: "All time · credited deposits only",
          },
        ]),
  ];
  return (
    <section
      className={`af-summary ${cards.length === 2 ? "af-summary-pair" : ""}`}
      aria-label={`Live financial totals · ${period}`}
    >
      {cards.map((card) => (
        <article className="af-card" key={card.label}>
          <span className={`af-icon af-${card.color}`}>
            <card.icon size={27} strokeWidth={1.8} />
          </span>
          <div>
            <h2>{card.label}</h2>
            <strong>{peso.format(card.value / 100)}</strong>
            <p>{card.note}</p>
          </div>
        </article>
      ))}
    </section>
  );
}

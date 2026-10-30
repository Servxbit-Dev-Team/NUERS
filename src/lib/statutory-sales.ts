import type { LineItem } from "./invoice-utils";
export const SALE_CATEGORIES = {
  regular: "Regular sale",
  senior_citizen: "Senior citizen",
  pwd: "PWD",
  national_athlete_coach: "National athlete / coach",
  solo_parent: "Solo parent",
};
export type SaleCategory = keyof typeof SALE_CATEGORIES;
export type SalesBenefit = {
  category: Exclude<SaleCategory, "regular">;
  scope: string;
  beneficiary_name: string;
  id_number: string;
  evidence_reference: string;
  eligibility_confirmed: boolean;
  child_birth_date?: string;
  id_valid_until?: string;
  income_below_250000?: boolean;
  booklet_reference?: string;
};
export const BENEFIT_SCOPES: Record<Exclude<SaleCategory, "regular">, string[]> = {
  senior_citizen: [
    "restaurant",
    "hotel",
    "recreation",
    "admission",
    "medicine",
    "medical_dental",
    "domestic_transport",
    "funeral",
  ],
  pwd: [
    "restaurant",
    "hotel",
    "recreation",
    "admission",
    "medicine",
    "medical_dental",
    "domestic_transport",
    "funeral",
  ],
  national_athlete_coach: [
    "restaurant",
    "hotel",
    "recreation",
    "admission",
    "medicine",
    "sports_equipment",
    "domestic_transport",
  ],
  solo_parent: [
    "baby_milk",
    "baby_food",
    "micronutrient_supplements",
    "diapers",
    "medicine",
    "vaccines",
    "medical_supplements",
  ],
};
export const saleLabel = (v?: string) => SALE_CATEGORIES[v as SaleCategory] || "Regular sale";
export function benefitLine(
  line: LineItem,
  benefit: SalesBenefit | null,
  inclusive: boolean,
): LineItem {
  if (!benefit || !line.benefit_eligible) return line;
  const gross = Math.round(line.unit_price * line.quantity),
    original = line.tax_type;
  const base = original === "vatable" && inclusive ? Math.round((gross * 100) / 112) : gross;
  const originalVat =
    original === "vatable" ? (inclusive ? gross - base : Math.round(base * 0.12)) : 0;
  const rate = benefit.category === "solo_parent" ? 10 : 20,
    discount = Math.round((base * rate) / 100),
    net = base - discount;
  const tax =
    original === "vatable" && benefit.category !== "national_athlete_coach"
      ? "vat_exempt"
      : original;
  const vat = tax === "vatable" ? Math.round(net * 0.12) : 0;
  return {
    ...line,
    discount_amount: discount,
    taxable_amount: tax === "vatable" ? net : 0,
    vat_amount: vat,
    vat_rate: tax === "vatable" ? 12 : 0,
    line_total: net + vat,
    tax_type: tax,
    benefit_details: {
      eligible: true,
      original_tax_type: original,
      rate,
      discount_centavos: discount,
      vat_adjustment_centavos: inclusive ? originalVat - vat : 0,
      base_centavos: base,
    },
  };
}
export function benefitError(
  b: SalesBenefit | null,
  lines: LineItem[],
  day: string,
): string | null {
  if (!b) return null;
  if (
    !b.beneficiary_name.trim() ||
    !b.id_number.trim() ||
    b.evidence_reference.trim().length < 5 ||
    !b.eligibility_confirmed
  )
    return "Complete beneficiary name, identification, evidence reference and eligibility confirmation.";
  if (!lines.some((l) => l.benefit_eligible))
    return "Mark at least one eligible item. Split shared or partly eligible purchases into separate lines.";
  if (
    lines.some((l) => l.benefit_eligible && (l.discount_pct !== 0 || l.tax_type === "zero_rated"))
  )
    return "Eligible items cannot also have a promotional discount or zero-rated export classification.";
  if (b.id_valid_until && b.id_valid_until < day)
    return "Identification was expired on the invoice date.";
  if (b.category === "solo_parent") {
    if (
      !b.child_birth_date ||
      !b.id_valid_until ||
      !b.booklet_reference?.trim() ||
      !b.income_below_250000
    )
      return "Provide the child birth date, SPIC expiry, booklet reference and income eligibility.";
    const seventh = new Date(b.child_birth_date + "T12:00:00Z");
    seventh.setUTCFullYear(seventh.getUTCFullYear() + 7);
    if (b.child_birth_date > day || seventh.toISOString().slice(0, 10) <= day)
      return "The child must be six years old or younger on the invoice date.";
  }
  return null;
}

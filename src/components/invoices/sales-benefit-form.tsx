import {
  SALE_CATEGORIES,
  BENEFIT_SCOPES,
  type SaleCategory,
  type SalesBenefit,
} from "@/lib/statutory-sales";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
export function SalesBenefitForm({
  value,
  onChange,
}: {
  value: SalesBenefit | null;
  onChange: (v: SalesBenefit | null) => void;
}) {
  const category = value?.category || "regular";
  const update = (p: Partial<SalesBenefit>) => value && onChange({ ...value, ...p });
  return (
    <section className="rounded-lg border bg-blue-50/40 p-4 mb-4 space-y-3">
      <div>
        <h3 className="font-semibold">Sale classification & statutory benefit</h3>
        <p className="text-sm text-muted-foreground">
          Verify eligibility, then mark only the beneficiary’s eligible items below. Separate shared
          portions and unrelated products.
        </p>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        <Label className="flex-col items-stretch gap-2">
          Sale category
          <Select
            value={category}
            onValueChange={(v) => {
              const c = v as SaleCategory;
              onChange(
                c === "regular"
                  ? null
                  : {
                      category: c,
                      scope: BENEFIT_SCOPES[c][0],
                      beneficiary_name: "",
                      id_number: "",
                      evidence_reference: "",
                      eligibility_confirmed: false,
                    },
              );
            }}
          >
            <SelectTrigger className="w-full" aria-label="Sale category">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {Object.entries(SALE_CATEGORIES).map(([k, l]) => (
                <SelectItem key={k} value={k}>
                  {l}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Label>
        {value && (
          <>
            <Label className="flex-col items-stretch gap-2">
              Eligible goods / service
              <Select value={value.scope} onValueChange={(v) => update({ scope: v })}>
                <SelectTrigger className="w-full" aria-label="Eligible goods or service">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {BENEFIT_SCOPES[value.category].map((s) => (
                    <SelectItem key={s} value={s}>
                      {s.replaceAll("_", " ")}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Label>
            <Label className="flex-col items-stretch gap-2">
              Beneficiary full name
              <Input
                value={value.beneficiary_name}
                maxLength={200}
                onChange={(e) => update({ beneficiary_name: e.target.value })}
              />
            </Label>
            <Label className="flex-col items-stretch gap-2">
              {value.category === "solo_parent"
                ? "SPIC"
                : value.category === "national_athlete_coach"
                  ? "PSC national team ID"
                  : "Senior / PWD identification"}{" "}
              number
              <Input
                value={value.id_number}
                maxLength={100}
                onChange={(e) => update({ id_number: e.target.value })}
              />
            </Label>
            <Label className="flex-col items-stretch gap-2 md:col-span-2">
              Evidence / verification reference
              <Input
                value={value.evidence_reference}
                maxLength={500}
                placeholder="Reference to verified ID, qualifying purchase, prescription or signed record as applicable"
                onChange={(e) => update({ evidence_reference: e.target.value })}
              />
            </Label>
            {value.category === "solo_parent" && (
              <>
                <Label className="flex-col items-stretch gap-2">
                  Child date of birth
                  <Input
                    type="date"
                    value={value.child_birth_date || ""}
                    onChange={(e) => update({ child_birth_date: e.target.value })}
                  />
                </Label>
                <Label className="flex-col items-stretch gap-2">
                  SPIC valid until
                  <Input
                    type="date"
                    value={value.id_valid_until || ""}
                    onChange={(e) => update({ id_valid_until: e.target.value })}
                  />
                </Label>
                <Label className="flex-col items-stretch gap-2">
                  Purchase booklet reference
                  <Input
                    value={value.booklet_reference || ""}
                    onChange={(e) => update({ booklet_reference: e.target.value })}
                  />
                </Label>
                <label className="flex gap-2 items-center text-sm">
                  <input
                    type="checkbox"
                    checked={!!value.income_below_250000}
                    onChange={(e) => update({ income_below_250000: e.target.checked })}
                  />
                  Verified annual income is below ₱250,000
                </label>
              </>
            )}
          </>
        )}
      </div>
      {value && (
        <>
          <p className="text-sm text-blue-900">
            {value.category === "national_athlete_coach"
              ? "20% discount on the eligible price excluding VAT; ordinary VAT remains applicable."
              : value.category === "solo_parent"
                ? "10% discount and applicable VAT exemption for qualifying child necessities, age six or younger."
                : "20% discount and applicable VAT exemption on covered goods/services only. The separate 5% basic-necessity and utility programs are not included in this selection."}
          </p>
          <label className="flex items-start gap-2 text-sm">
            <input
              className="mt-1"
              type="checkbox"
              checked={value.eligibility_confirmed}
              onChange={(e) => update({ eligibility_confirmed: e.target.checked })}
            />
            I verified the identification, qualifying goods/services, beneficiary’s exclusive share
            and required supporting evidence. No duplicate or stacked statutory/promotional benefit
            is applied.
          </label>
        </>
      )}
    </section>
  );
}

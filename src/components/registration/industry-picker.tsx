import { SearchableSelect } from "./searchable-select";

export function IndustryPicker({
  value,
  onChange,
  items,
  disabled,
  error,
}: {
  value: string;
  onChange: (value: string) => void;
  items: Record<string, string>;
  disabled: boolean;
  error?: string;
}) {
  return (
    <SearchableSelect
      label="Industry Classification"
      placeholder="Select Industry"
      searchPlaceholder="Search industry or PSIC code…"
      searchLabel="Search industries"
      value={value}
      onChange={onChange}
      items={Object.entries(items).map(([value, label]) => ({ value, label }))}
      loading={disabled}
      error={error}
      catalogLabel={`${Math.max(0, Object.keys(items).length - 1)} PSIC activities`}
      emptyMessage="No matching industry. Try another keyword or choose Other business activity."
      footerAction={{ label: "Other business activity", onSelect: () => onChange("other") }}
    />
  );
}

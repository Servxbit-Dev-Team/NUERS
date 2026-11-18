import { nice, type ProviderCatalog, type ProviderChoice, type Tier } from "./types";

export function ProviderFields({ catalog, value, onChange, allowUnassigned = false }: {
  catalog: ProviderCatalog; value: ProviderChoice; onChange: (value: ProviderChoice) => void; allowUnassigned?: boolean;
}) {
  return <>
    <label>Provider<select value={value.provider} onChange={e => onChange({ ...value, provider: e.target.value, resource_type: "virtual_machine" })}>
      {Object.entries(catalog.providers).filter(([key]) => allowUnassigned || key !== "unassigned").map(([key, label]) => <option key={key} value={key}>{label}</option>)}
    </select></label>
    <label>Resource type<select value={value.resource_type} onChange={e => {
      const type = catalog.resource_types[e.target.value];
      onChange({ ...value, resource_type: e.target.value, tier: type.tiers.includes(value.tier) ? value.tier : type.tiers[0] });
    }}>
      {Object.entries(catalog.resource_types).filter(([, type]) => type.providers.includes(value.provider)).map(([key, type]) => <option key={key} value={key}>{type.label}</option>)}
    </select></label>
    <label>Server tier<select value={value.tier} onChange={e => onChange({ ...value, tier: e.target.value as Tier })}>
      {(catalog.resource_types[value.resource_type]?.tiers || []).map(tier => <option key={tier} value={tier}>{nice(tier)}</option>)}
    </select></label>
  </>;
}

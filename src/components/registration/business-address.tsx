import { useEffect, useState } from "react";
import { SearchableSelect } from "./searchable-select";
import { apiJson } from "@/lib/api-url";

type Location = { code: string; name: string; parent?: string; is_group?: boolean };
type Office = { rdo_code: string; rdo_name: string };
export type AddressValue = {
  region_code: string;
  province_code: string;
  city_code: string;
  barangay_code: string;
  street_address: string;
  zip_code: string;
  address: string;
  rdo_code: string;
};
type Props = {
  value: AddressValue;
  onChange: (change: Partial<AddressValue>) => void;
  offices: Office[];
  errors: Record<string, string>;
  disabled?: boolean;
  legend?: string;
  rdoSearchableWhenMapped?: boolean;
};
export function BusinessAddress({
  value,
  onChange,
  offices,
  errors,
  disabled = false,
  legend = "Registered Business Address",
  rdoSearchableWhenMapped = false,
}: Props) {
  const [regions, setRegions] = useState<Location[]>([]),
    [provinces, setProvinces] = useState<Location[]>([]),
    [cities, setCities] = useState<Location[]>([]),
    [barangays, setBarangays] = useState<Location[]>([]);
  const [loading, setLoading] = useState<Record<string, boolean>>({}),
    [loadErrors, setLoadErrors] = useState<Record<string, string>>({}),
    [retry, setRetry] = useState(0);
  const [resolution, setResolution] = useState<{ automatic: boolean; message: string }>({
    automatic: false,
    message: "Select your city and barangay to find your RDO.",
  });
  function change(
    key: "region_code" | "province_code" | "city_code" | "barangay_code",
    next: string,
  ) {
    const resets = {
      region_code: { province_code: "", city_code: "", barangay_code: "" },
      province_code: { city_code: "", barangay_code: "" },
      city_code: { barangay_code: "" },
      barangay_code: {},
    };
    onChange({ [key]: next, ...resets[key], rdo_code: "", address: "" });
    setResolution({ automatic: false, message: "Checking address jurisdiction…" });
  }
  useEffect(() => {
    const controller = new AbortController();
    setLoading((x) => ({ ...x, regions: true }));
    apiJson<{ data: Location[] }>("/api/registration/locations?level=regions", {
      signal: controller.signal,
    })
      .then((r) => {
        setRegions(r.data);
        setLoadErrors((x) => ({ ...x, regions: "" }));
      })
      .catch((e) => {
        if (e.name !== "AbortError")
          setLoadErrors((x) => ({ ...x, regions: "Could not load regions." }));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading((x) => ({ ...x, regions: false }));
      });
    return () => controller.abort();
  }, [retry]);
  useEffect(() => {
    setProvinces([]);
    if (!value.region_code) return;
    const controller = new AbortController();
    setLoading((x) => ({ ...x, provinces: true }));
    apiJson<{ data: Location[] }>(
      `/api/registration/locations?level=provinces&parent=${value.region_code}`,
      { signal: controller.signal },
    )
      .then((r) => {
        setProvinces(r.data);
        setLoadErrors((x) => ({ ...x, provinces: "" }));
        if (r.data.length === 1 && !value.province_code)
          onChange({ province_code: r.data[0].code });
      })
      .catch((e) => {
        if (e.name !== "AbortError")
          setLoadErrors((x) => ({ ...x, provinces: "Could not load provinces / areas." }));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading((x) => ({ ...x, provinces: false }));
      });
    return () => controller.abort();
  }, [value.region_code, retry]);
  useEffect(() => {
    setCities([]);
    if (!value.province_code) return;
    const controller = new AbortController();
    setLoading((x) => ({ ...x, cities: true }));
    apiJson<{ data: Location[] }>(
      `/api/registration/locations?level=cities&parent=${value.province_code}`,
      { signal: controller.signal },
    )
      .then((r) => {
        setCities(r.data);
        setLoadErrors((x) => ({ ...x, cities: "" }));
      })
      .catch((e) => {
        if (e.name !== "AbortError")
          setLoadErrors((x) => ({ ...x, cities: "Could not load cities / municipalities." }));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading((x) => ({ ...x, cities: false }));
      });
    return () => controller.abort();
  }, [value.province_code, retry]);
  useEffect(() => {
    setBarangays([]);
    if (!value.city_code) return;
    const controller = new AbortController();
    setLoading((x) => ({ ...x, barangays: true }));
    apiJson<{ data: Location[] }>(
      `/api/registration/locations?level=barangays&parent=${value.city_code}`,
      { signal: controller.signal },
    )
      .then((r) => {
        setBarangays(r.data);
        setLoadErrors((x) => ({ ...x, barangays: "" }));
      })
      .catch((e) => {
        if (e.name !== "AbortError")
          setLoadErrors((x) => ({ ...x, barangays: "Could not load barangays." }));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading((x) => ({ ...x, barangays: false }));
      });
    return () => controller.abort();
  }, [value.city_code, retry]);
  useEffect(() => {
    if (!value.city_code) {
      setResolution({
        automatic: false,
        message: "Select your city and barangay to find your RDO.",
      });
      return;
    }
    const controller = new AbortController();
    setLoading((x) => ({ ...x, rdo: true }));
    setResolution({ automatic: false, message: "Checking address jurisdiction…" });
    apiJson<{ rdo: Office | null; automatic: boolean; message: string }>(
      `/api/registration/rdo?city_code=${value.city_code}${value.barangay_code ? `&barangay_code=${value.barangay_code}` : ""}`,
      { signal: controller.signal },
    )
      .then((r) => {
        setResolution(r);
        onChange({ rdo_code: r.rdo?.rdo_code || "" });
        setLoadErrors((x) => ({ ...x, rdo: "" }));
      })
      .catch((e) => {
        if (e.name !== "AbortError") {
          onChange({ rdo_code: "" });
          setLoadErrors((x) => ({
            ...x,
            rdo: "Could not check RDO coverage. Retry before continuing.",
          }));
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading((x) => ({ ...x, rdo: false }));
      });
    return () => controller.abort();
  }, [value.city_code, value.barangay_code, retry]);
  const selectedRegion = regions.find((x) => x.code === value.region_code),
    selectedProvince = provinces.find((x) => x.code === value.province_code),
    selectedCity = cities.find((x) => x.code === value.city_code),
    selectedBarangay = barangays.find((x) => x.code === value.barangay_code);
  const address = selectedRegion
    ? [
        value.street_address,
        selectedBarangay?.name,
        selectedCity?.name,
        !selectedProvince?.is_group ? selectedProvince?.name : null,
        selectedRegion?.name,
        value.zip_code,
        "Philippines",
      ]
        .filter(Boolean)
        .join(", ")
    : "";
  useEffect(() => {
    onChange({ address });
  }, [address]);
  const lists = [
    { key: "region_code", label: "Region", items: regions, busy: "regions", enabled: true },
    {
      key: "province_code",
      label: "Province / Area",
      items: provinces,
      busy: "provinces",
      enabled: !!value.region_code,
    },
    {
      key: "city_code",
      label: "City / Municipality",
      items: cities,
      busy: "cities",
      enabled: !!value.province_code,
    },
    {
      key: "barangay_code",
      label: "Barangay",
      items: barangays,
      busy: "barangays",
      enabled: !!value.city_code,
    },
  ] as const;
  return (
    <fieldset className="rg-address-section" disabled={disabled}>
      <legend>
        {legend} <b>*</b>
      </legend>
      <p>Select your location, then enter your building and street details.</p>
      <div className="rg-address-grid">
        {lists.map(({ key, label, items, busy, enabled }) => (
          <SearchableSelect
            key={key}
            label={label}
            value={value[key]}
            items={items.map((x) => ({ value: x.code, label: x.name }))}
            disabled={disabled || !enabled}
            loading={!!loading[busy] && enabled}
            onChange={(next) => change(key, next)}
            error={errors[key]}
          />
        ))}
        <label className="rg-field">
          <span>
            Unit / Building / Street<b> *</b>
          </span>
          <input
            name="street_address"
            autoComplete="address-line1"
            value={value.street_address}
            placeholder="Unit, building, lot/block and street"
            maxLength={500}
            onChange={(e) => onChange({ street_address: e.target.value })}
            aria-invalid={!!errors.street_address}
          />
          {errors.street_address && (
            <small className="rg-field-error">{errors.street_address}</small>
          )}
        </label>
        <label className="rg-field">
          <span>
            ZIP Code<b> *</b>
          </span>
          <input
            name="zip_code"
            autoComplete="postal-code"
            inputMode="numeric"
            maxLength={4}
            placeholder="4-digit ZIP code"
            value={value.zip_code}
            onChange={(e) => onChange({ zip_code: e.target.value.replace(/\D/g, "").slice(0, 4) })}
            aria-invalid={!!errors.zip_code}
          />
          {errors.zip_code && <small className="rg-field-error">{errors.zip_code}</small>}
        </label>
        <SearchableSelect
          label="BIR RDO"
          value={value.rdo_code}
          items={(resolution.automatic && rdoSearchableWhenMapped
            ? offices.filter((r) => r.rdo_code === value.rdo_code)
            : offices
          ).map((r) => ({ value: r.rdo_code, label: `${r.rdo_code} — ${r.rdo_name}` }))}
          placeholder="Confirm your registered RDO"
          searchPlaceholder="Search RDO code or office…"
          disabled={
            disabled ||
            (resolution.automatic && !rdoSearchableWhenMapped) ||
            !value.barangay_code ||
            !!loadErrors.rdo
          }
          loading={!!loading.rdo}
          onChange={(next) => onChange({ rdo_code: next })}
          badge={resolution.automatic ? "Auto-selected" : undefined}
          help={resolution.message}
          error={errors.rdo_code}
        />
        <div className="rg-address-preview">
          <strong>Complete address</strong>
          <p>{selectedCity ? address : "Philippines · Choose your location above."}</p>
        </div>
      </div>
      {Object.values(loadErrors).filter(Boolean).length > 0 && (
        <div className="rg-alert" role="alert">
          {Object.values(loadErrors).filter(Boolean).join(" ")}
          <button type="button" onClick={() => setRetry((x) => x + 1)}>
            Retry location lookup
          </button>
        </div>
      )}
      {errors.address && (
        <p className="rg-field-error" role="alert">
          {errors.address}
        </p>
      )}
      <small className="rg-address-source">
        Philippine locations: PSGC Q1 2026 · RDO is checked against mapped city / barangay coverage.
      </small>
    </fieldset>
  );
}

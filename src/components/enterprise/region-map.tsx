import shapes from "./philippine-regions.json";

export type RegionCount = { name: string; count: number; percentage: number };
const normalize = (s: string) => s.toLowerCase().replace(/[^a-z0-9]/g, "");
export function regionCode(name: string): string | undefined {
  const key = normalize(name);
  const aliases: Record<string, string> = {
    ncr: "1300000000",
    metromanila: "1300000000",
    nationalcapitalregion: "1300000000",
    car: "1400000000",
    cordilleraadministrativeregion: "1400000000",
    barmm: "1900000000",
    armm: "1900000000",
    autonomousregioninmuslimmindanao: "1900000000",
    bangsamoroautonomousregioninmuslimmindanao: "1900000000",
    nir: "1800000000",
  };
  return (
    aliases[key] ||
    shapes.find((r) => {
      const n = normalize(r.name);
      const short = normalize(r.name.match(/\(([^)]+)\)/)?.[1] || r.name);
      return (
        key === r.code || key === n || key === short || key === normalize(r.name.split(" (")[0])
      );
    })?.code
  );
}
export function RegionMap({ rows }: { rows: RegionCount[] }) {
  const counts = new Map<string, number>();
  for (const row of rows) {
    const code = regionCode(row.name);
    if (code) counts.set(code, (counts.get(code) || 0) + row.count);
  }
  const max = Math.max(1, ...counts.values());
  const unknown = rows
    .filter((r) => r.count > 0 && !regionCode(r.name))
    .reduce((n, r) => n + r.count, 0);
  return (
    <div className="ne-region-map">
      <svg
        viewBox="0 0 300 440"
        className="ne-philippines"
        role="img"
        aria-label="Transactions by recorded region. Dark blue indicates the highest transaction count; gray indicates no matching transactions."
      >
        {shapes.map((region) => {
          const count = counts.get(region.code) || 0;
          return (
            <path
              key={region.code}
              d={region.path}
              fill={count ? `hsl(216 85% ${65 - (count / max) * 37}%)` : "#e2e8f0"}
              stroke="#8ea6c3"
              strokeWidth="0.45"
              fillRule="evenodd"
              tabIndex={0}
              aria-label={`${region.name}: ${count.toLocaleString()} transactions`}
            >
              <title>{`${region.name}: ${count.toLocaleString()} transactions`}</title>
            </path>
          );
        })}
        {!!counts.get("1300000000") && (
          <g aria-hidden="true">
            <circle cx="124" cy="185" r="6" fill="none" stroke="#0056d6" strokeWidth="1.5" />
            <path d="M130 184L166 170" stroke="#0056d6" />
            <text x="168" y="173" fill="#0f2d5b" fontSize="13" fontWeight="700">
              NCR
            </text>
          </g>
        )}
      </svg>
      <small>
        Gray: no recorded transactions{unknown > 0 ? ` · ${unknown.toLocaleString()} unmapped` : ""}
      </small>
      <a
        className="ne-map-source"
        href="https://www.geoboundaries.org/api/current/gbOpen/PHL/ADM2/"
        target="_blank"
        rel="noreferrer"
        title="NAMRIA / PSA / OCHA via geoBoundaries, CC BY 3.0 IGO. Simplified province geometry; current PSGC region assignments."
      >
        Map source
      </a>
    </div>
  );
}

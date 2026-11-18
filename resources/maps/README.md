# Philippine dashboard regions

The dashboard SVG paths are simplified from geoBoundaries PHL ADM2 (2020 geometry; 2023 release), sourced from NAMRIA, PSA and OCHA Philippines under CC BY 3.0 IGO. Attribution: https://www.geoboundaries.org/api/current/gbOpen/PHL/ADM2/

Province assignments follow the application's PSGC Q1 2026 address catalog, including Negros Island Region and Sulu. Former Compostela Valley is Davao de Oro; the former Maguindanao geometry is grouped under BARMM. This is a small-scale statistical visualization, not a legal boundary map; later barangay-level boundary changes are not represented.

Rebuild with `scripts/build-region-map.py` after downloading the source simplified GeoJSON to `/private/tmp/nuers-ph-provinces.geojson`. No customer information is sent to a map provider. Only regions matched to recorded transaction-region labels are shaded. Unmatched labels remain in the ranked list and are disclosed as unmapped.

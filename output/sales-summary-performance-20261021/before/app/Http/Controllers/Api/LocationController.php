<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LocationController extends Controller
{
    public function countries(): array
    {
        return [
            'data' => [
                ['code' => 'PH', 'name' => 'Philippines'],
            ],
        ];
    }

    public function cities(Request $request): array
    {
        $country = strtoupper((string) $request->query('country', 'PH'));

        if ($country !== 'PH') {
            return ['data' => []];
        }

        $cities = Cache::remember('locations.psgc.cities.v3', now()->addDay(), function () {
            try {
                $response = Http::timeout(4)->acceptJson()->get('https://psgc.gitlab.io/api/cities-municipalities/');

                if (!$response->successful()) {
                    throw new \RuntimeException('PSGC city API unavailable.');
                }

                return collect($response->json())
                    ->map(fn (array $item) => $this->normalizeCityRecord($item))
                    ->filter(fn (array $item) => $item['code'] && $item['name'])
                    ->sortBy('name')
                    ->values()
                    ->all();
            } catch (\Throwable) {
                $legacyCities = Cache::get('locations.psgc.cities');

                return is_array($legacyCities) ? $this->normalizeCachedCities($legacyCities) : null;
            }
        });

        return ['data' => $cities ? $this->ensureFallbackCities($cities) : $this->fallbackCities()];
    }

    public function barangays(Request $request): array
    {
        $cityCode = (string) $request->query('cityCode', '');
        $city = (string) $request->query('city', '');

        if ($cityCode && !Str::startsWith($cityCode, 'LOCAL-')) {
            $barangays = Cache::remember("locations.psgc.barangays.{$cityCode}", now()->addDay(), function () use ($cityCode) {
                try {
                    $response = Http::timeout(4)->acceptJson()->get("https://psgc.gitlab.io/api/cities-municipalities/{$cityCode}/barangays/");

                    if (!$response->successful()) {
                        return null;
                    }

                    return collect($response->json())
                        ->map(fn (array $item) => [
                            'code' => (string) ($item['code'] ?? Str::slug((string) ($item['name'] ?? ''))),
                            'name' => (string) ($item['name'] ?? ''),
                            'city_code' => $cityCode,
                        ])
                        ->filter(fn (array $item) => $item['code'] && $item['name'])
                        ->sortBy('name')
                        ->values()
                        ->all();
                } catch (\Throwable) {
                    return null;
                }
            });

            if ($barangays) {
                return ['data' => $barangays];
            }
        }

        return ['data' => $this->fallbackBarangays($cityCode, $city)];
    }

    public function rdo(Request $request): array
    {
        $city = (string) $request->query('city', '');
        $region = (string) $request->query('region', '');
        $province = (string) $request->query('province', '');
        $barangay = (string) $request->query('barangay', '');

        return ['data' => $this->detectRdo($city, $region, $province, $barangay)];
    }

    private function fallbackCities(): array
    {
        return collect([
            ['Caloocan City', 'NCR'],
            ['Las Piñas City', 'NCR'],
            ['Makati City', 'NCR'],
            ['Malabon City', 'NCR'],
            ['Mandaluyong City', 'NCR'],
            ['City of Manila', 'NCR'],
            ['Marikina City', 'NCR'],
            ['Muntinlupa City', 'NCR'],
            ['Navotas City', 'NCR'],
            ['Parañaque City', 'NCR'],
            ['Pasay City', 'NCR'],
            ['Pasig City', 'NCR'],
            ['Pateros', 'NCR'],
            ['Quezon City', 'NCR'],
            ['San Juan City', 'NCR'],
            ['Taguig City', 'NCR'],
            ['Valenzuela City', 'NCR'],
            ['Cebu City', 'Region VII'],
            ['Mandaue City', 'Region VII'],
            ['Lapu-Lapu City', 'Region VII'],
            ['Davao City', 'Region XI'],
            ['Cagayan de Oro City', 'Region X'],
            ['Iloilo City', 'Region VI'],
            ['Bacolod City', 'Region VI'],
            ['Baguio City', 'CAR'],
            ['City of San Fernando', 'Region III'],
            ['Angeles City', 'Region III'],
            ['General Santos City', 'Region XII'],
            ['Zamboanga City', 'Region IX'],
        ])->map(function (array $row) {
            [$city, $region] = $row;
            $rdo = $this->detectRdo($city, $region);

            return [
                'code' => 'LOCAL-'.Str::upper(Str::slug($city)),
                'name' => $city,
                'region' => $region,
                'province' => null,
                'rdo_code' => $rdo['code'],
                'rdo_name' => $rdo['name'],
            ];
        })->all();
    }

    private function fallbackBarangays(string $cityCode, string $city): array
    {
        $normalized = Str::of($city ?: $cityCode)->lower()->toString();

        $sets = [
            'makati' => ['Bel-Air', 'Poblacion', 'San Lorenzo', 'Urdaneta', 'Ayala Center', 'San Antonio', 'Pio del Pilar'],
            'quezon' => ['Batasan Hills', 'Commonwealth', 'Cubao', 'Diliman', 'Novaliches Proper', 'Project 4', 'South Triangle'],
            'manila' => ['Binondo', 'Ermita', 'Intramuros', 'Malate', 'Paco', 'Sampaloc', 'Santa Cruz', 'Tondo'],
            'taguig' => ['Bagumbayan', 'Bonifacio Global City', 'Fort Bonifacio', 'Pinagsama', 'Ususan', 'Western Bicutan'],
            'pasig' => ['Bagong Ilog', 'Kapitolyo', 'Ortigas Center', 'San Antonio', 'Ugong', 'Rosario'],
            'pasay' => ['Barangay 76', 'Barangay 183', 'Mall of Asia Complex', 'San Rafael', 'Vitalez'],
            'cebu' => ['Apas', 'Capitol Site', 'Guadalupe', 'Lahug', 'Mabolo', 'Sambag I'],
            'davao' => ['Agdao', 'Buhangin', 'Poblacion', 'Talomo', 'Toril'],
            'iloilo' => ['City Proper', 'Jaro', 'La Paz', 'Mandurriao', 'Molo'],
            'bacolod' => ['Alijis', 'Bata', 'Mandalagan', 'Punta Taytay', 'Singcang-Airport'],
        ];

        $barangays = ['Poblacion', 'San Jose', 'San Isidro', 'San Roque', 'Santa Cruz'];

        foreach ($sets as $key => $values) {
            if (Str::contains($normalized, $key)) {
                $barangays = $values;
                break;
            }
        }

        return collect($barangays)
            ->map(fn (string $name) => [
                'code' => 'LOCAL-'.Str::upper(Str::slug(($city ?: $cityCode).'-'.$name)),
                'name' => $name,
                'city_code' => $cityCode,
            ])
            ->values()
            ->all();
    }

    private function normalizeCachedCities(array $cities): array
    {
        return collect($cities)
            ->map(fn (array $item) => $this->normalizeCityRecord($item))
            ->filter(fn (array $item) => $item['code'] && $item['name'])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function normalizeCityRecord(array $item): array
    {
        $cityName = (string) ($item['name'] ?? '');
        $region = (string) ($item['region'] ?? $this->regionLabel((string) ($item['regionCode'] ?? '')));
        $provinceValue = (string) ($item['province'] ?? $item['provinceCode'] ?? '');
        $province = ctype_digit($provinceValue) ? $this->provinceLabel($provinceValue) : $provinceValue;
        $rdo = $this->detectRdo($cityName, $region, $province);

        return [
            'code' => (string) ($item['code'] ?? Str::slug($cityName)),
            'name' => $cityName,
            'region' => $region,
            'province' => $province ?: null,
            'rdo_code' => $rdo['code'],
            'rdo_name' => $rdo['name'],
        ];
    }

    private function ensureFallbackCities(array $cities): array
    {
        $merged = collect($cities);
        $existing = $merged
            ->map(fn (array $city) => $this->canonicalCityName((string) ($city['name'] ?? '')))
            ->filter()
            ->flip();

        foreach ($this->fallbackCities() as $fallback) {
            $canonical = $this->canonicalCityName((string) $fallback['name']);

            if (!$existing->has($canonical)) {
                $merged->push($fallback);
                $existing->put($canonical, true);
            }
        }

        return $merged
            ->sortBy(fn (array $city) => $this->canonicalCityName((string) ($city['name'] ?? '')))
            ->values()
            ->all();
    }

    private function canonicalCityName(string $city): string
    {
        $normalized = Str::of($city)->lower()->ascii()->toString();
        $normalized = preg_replace('/\b(city|municipality|of)\b/', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?: $normalized;

        return trim($normalized);
    }

    private function detectRdo(string $city, string $region = '', string $province = '', string $barangay = ''): array
    {
        $rawCity = Str::of($city)->lower()->ascii()->toString();
        $normalized = $this->normalizeLocationText($city);
        $normalizedBarangay = $this->normalizeLocationText($barangay);
        $normalizedProvince = Str::of($province)->lower()->ascii()->toString();
        $normalizedProvince = preg_replace('/[^a-z0-9]+/', ' ', $normalizedProvince) ?: $normalizedProvince;
        $searchText = trim($normalized.' '.$normalizedProvince);

        $barangayRdo = $this->detectBarangayRdo($normalized, $normalizedBarangay, $rawCity, $region, $province);

        if ($barangayRdo) {
            return $barangayRdo;
        }

        if ($this->requiresBarangayRdo($normalized, $rawCity, $region, $province)) {
            return ['code' => '000', 'name' => 'Select barangay to detect exact RDO'];
        }

        $rules = [
            'palawan' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'puerto princesa' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'aborlan' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'bataraza' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'brooke s point' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'busuanga' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'coron' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'culion' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'cuyo' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'dumaran' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'el nido' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'narra' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'roxas palawan' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'san vicente palawan' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'taytay palawan' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'occidental mindoro' => ['037', 'RDO 037 - San Jose, Occidental Mindoro'],
            'san jose occidental' => ['037', 'RDO 037 - San Jose, Occidental Mindoro'],
            'mamburao' => ['037', 'RDO 037 - San Jose, Occidental Mindoro'],
            'sablayan' => ['037', 'RDO 037 - San Jose, Occidental Mindoro'],
            'oriental mindoro' => ['063', 'RDO 063 - Calapan, Oriental Mindoro'],
            'calapan' => ['063', 'RDO 063 - Calapan, Oriental Mindoro'],
            'pinamalayan' => ['063', 'RDO 063 - Calapan, Oriental Mindoro'],
            'puerto galera' => ['063', 'RDO 063 - Calapan, Oriental Mindoro'],
            'marinduque' => ['062', 'RDO 062 - Boac, Marinduque'],
            'boac' => ['062', 'RDO 062 - Boac, Marinduque'],
            'romblon' => ['035', 'RDO 035 - Odiongan, Romblon'],
            'odiongan' => ['035', 'RDO 035 - Odiongan, Romblon'],
            'makati' => ['000', 'Select barangay to detect exact RDO'],
            'quezon city' => ['000', 'Select barangay to detect exact RDO'],
            'manila' => ['033', 'RDO 033 - Intramuros-Ermita-Malate'],
            'taguig' => ['044', 'RDO 044 - Taguig-Pateros'],
            'pateros' => ['044', 'RDO 044 - Taguig-Pateros'],
            'pasig' => ['000', 'Select barangay to detect exact RDO'],
            'mandaluyong' => ['041', 'RDO 041 - Mandaluyong City'],
            'san juan' => ['042', 'RDO 042 - San Juan City'],
            'pasay' => ['051', 'RDO 051 - Pasay City'],
            'paranaque' => ['052', 'RDO 052 - Parañaque City'],
            'caloocan' => ['027', 'RDO 027 - Caloocan City'],
            'las pinas' => ['053A', 'RDO 053A - Las Piñas City'],
            'malabon' => ['026', 'RDO 026 - Malabon-Navotas'],
            'marikina' => ['045', 'RDO 045 - Marikina City'],
            'muntinlupa' => ['053B', 'RDO 053B - Muntinlupa City'],
            'navotas' => ['026', 'RDO 026 - Malabon-Navotas'],
            'valenzuela' => ['024', 'RDO 024 - Valenzuela City'],
            'cebu' => ['081', 'RDO 081 - Cebu City North'],
            'mandaue' => ['080', 'RDO 080 - Mandaue City'],
            'lapu lapu' => ['080', 'RDO 080 - Mandaue City'],
            'davao' => ['113A', 'RDO 113A - West Davao City'],
            'cagayan de oro' => ['098', 'RDO 098 - Cagayan de Oro City'],
            'iloilo' => ['073', 'RDO 073 - Iloilo City'],
            'bacolod' => ['077', 'RDO 077 - Bacolod City'],
            'baguio' => ['008', 'RDO 008 - Baguio City'],
            'san fernando' => ['021', 'RDO 021 - San Fernando, Pampanga'],
            'angeles' => ['021C', 'RDO 021C - Clark Freeport Zone'],
            'general santos' => ['110', 'RDO 110 - General Santos City'],
            'zamboanga' => ['093A', 'RDO 093A - Zamboanga City'],
        ];

        foreach ($rules as $needle => $rdo) {
            if (Str::contains($searchText, $needle)) {
                return ['code' => $rdo[0], 'name' => $rdo[1]];
            }
        }

        $regionDefaults = [
            'Region I' => ['004', 'RDO 004 - Calasiao, West Pangasinan'],
            'Region II' => ['014', 'RDO 014 - Bayombong, Nueva Vizcaya'],
            'Region III' => ['021', 'RDO 021 - San Fernando, Pampanga'],
            'Region IV-A' => ['057', 'RDO 057 - Biñan, Laguna'],
            'Region IV-B' => ['036', 'RDO 036 - Puerto Princesa City, Palawan'],
            'Region V' => ['067', 'RDO 067 - Legazpi City, Albay'],
            'Region VI' => ['073', 'RDO 073 - Iloilo City'],
            'Region VII' => ['081', 'RDO 081 - Cebu City North'],
            'Region VIII' => ['088', 'RDO 088 - Tacloban City'],
            'Region IX' => ['093A', 'RDO 093A - Zamboanga City'],
            'Region X' => ['098', 'RDO 098 - Cagayan de Oro City'],
            'Region XI' => ['113A', 'RDO 113A - West Davao City'],
            'Region XII' => ['110', 'RDO 110 - General Santos City'],
            'NCR' => ['040', 'RDO 040 - Cubao, Quezon City'],
            'CAR' => ['008', 'RDO 008 - Baguio City'],
            'BARMM' => ['107', 'RDO 107 - Cotabato City'],
            'CARAGA' => ['103', 'RDO 103 - Butuan City'],
        ];

        $fallback = $regionDefaults[$region] ?? ['000', 'RDO auto-detection pending'];

        return ['code' => $fallback[0], 'name' => $fallback[1]];
    }

    private function normalizeLocationText(string $value): string
    {
        $normalized = Str::of($value)->lower()->ascii()->toString();
        $normalized = str_replace(['ñ'], ['n'], $normalized);
        $normalized = preg_replace('/\b(brgy|barangay|bgy|city|municipality|of)\b/', ' ', $normalized) ?: $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?: $normalized;

        return trim($normalized);
    }

    private function requiresBarangayRdo(string $normalizedCity, string $rawCity, string $region = '', string $province = ''): bool
    {
        if ($this->isQuezonCityContext($normalizedCity, $rawCity, $region, $province)) {
            return true;
        }

        foreach (['makati', 'pasig'] as $city) {
            if (Str::contains($normalizedCity, $city)) {
                return true;
            }
        }

        return false;
    }

    private function isQuezonCityContext(string $normalizedCity, string $rawCity, string $region = '', string $province = ''): bool
    {
        $normalizedRegion = $this->normalizeLocationText($region);
        $normalizedProvince = $this->normalizeLocationText($province);

        return Str::contains($rawCity, 'quezon city')
            || ($normalizedCity === 'quezon' && ($normalizedRegion === 'ncr' || Str::contains($normalizedProvince, 'metro manila')));
    }

    private function detectBarangayRdo(string $normalizedCity, string $normalizedBarangay, string $rawCity, string $region = '', string $province = ''): ?array
    {
        if ($normalizedBarangay === '') {
            return null;
        }

        $sets = $this->barangayRdoSets();

        foreach ($sets as $cityNeedle => $rdoGroups) {
            if ($cityNeedle === 'quezon') {
                if (! $this->isQuezonCityContext($normalizedCity, $rawCity, $region, $province)) {
                    continue;
                }
            } elseif (! Str::contains($normalizedCity, $cityNeedle)) {
                continue;
            }

            foreach ($rdoGroups as $rdo) {
                if (in_array($normalizedBarangay, $rdo['barangays'], true)) {
                    return ['code' => $rdo['code'], 'name' => $rdo['name']];
                }
            }

            return ['code' => '000', 'name' => 'Barangay RDO mapping pending verification'];
        }

        return null;
    }

    private function barangayRdoSets(): array
    {
        $normalize = fn (array $barangays): array => collect($barangays)
            ->map(fn (string $barangay) => $this->normalizeLocationText($barangay))
            ->values()
            ->all();

        return [
            'quezon' => [
                [
                    'code' => '038',
                    'name' => 'RDO 038 - North Quezon City',
                    'barangays' => $normalize([
                        'Alicia', 'Apolonio Samson', 'Baesa', 'Bagong Pag-Asa', 'Bahay Toro', 'Balingasa',
                        'Balong Bato', 'Bungad', 'Damar', 'Damayan', 'Del Monte', 'Katipunan', 'Lourdes',
                        'Maharlika', 'Manresa', 'Mariblo', 'Masambong', 'N.S. Amoranto', 'Nayong Kanluran',
                        'Paang Bundok', 'Pag-Ibig sa Nayon', 'Paltok', 'Paraiso', 'Phil-Am', 'Project 6',
                        'Ramon Magsaysay', 'Saint Peter', 'Salvacion', 'San Antonio', 'San Isidro Labrador',
                        'San Jose', 'Sangandaan', 'Santa Cruz', 'Santa Teresita', 'Santo Cristo',
                        'Santo Domingo', 'Sienna', 'Talayan', 'Unang Sigaw', 'Vasra', 'Veterans Village',
                        'West Triangle',
                    ]),
                ],
                [
                    'code' => '040',
                    'name' => 'RDO 040 - Cubao',
                    'barangays' => $normalize([
                        'Amihan', 'Bagong Lipunan ng Crame', 'Bagumbayan', 'Bagumbuhay', 'Bayanihan',
                        'Blue Ridge A', 'Blue Ridge B', 'Camp Aguinaldo', 'Claro', 'Dioquino Zobel',
                        'Duyan-Duyan', 'E. Rodriguez', 'East Kamias', 'Escopa I', 'Horseshoe',
                        'Immaculate Concepcion', 'Immaculate Conception', 'Kaunlaran', 'Libis', 'Mangga',
                        'Marilag', 'Masagana', 'Milagrosa', 'Pinagkaisahan', 'Quirino 2-A', 'Quirino 2-B',
                        'Quirino 2-C', 'Quirino 3-A', 'Saint Ignatius', 'San Martin de Porres', 'San Roque',
                        'Silangan', 'Socorro', 'Tagumpay', 'Ugong Norte', 'Valencia', 'Villa Maria Clara',
                        'West Kamias', 'White Plains',
                    ]),
                ],
                [
                    'code' => '039',
                    'name' => 'RDO 039 - South Quezon City',
                    'barangays' => $normalize([
                        'Aurora', 'Botocan', 'Central', 'Damayang Lagi', 'Don Manuel', 'Doña Imelda',
                        'Dona Imelda', 'Doña Josefa', 'Dona Josefa', 'Kalusugan', 'Kamuning', 'Kristong Hari',
                        'Krus na Ligas', 'Laging Handa', 'Loyola Heights', 'Malaya', 'Mariana', 'Obrero',
                        'Old Capitol Site', 'Paligsahan', 'Pansol', 'Pinyahan', 'Roxas', 'Sacred Heart',
                        'San Isidro', 'San Vicente', 'Santo Niño', 'Santo Nino', 'Santol', 'Sikatuna Village',
                        'South Triangle', 'Tatalon', 'Teachers Village East', 'Teachers Village West',
                        'U.P. Campus', 'UP Campus', 'U.P. Village', 'UP Village',
                    ]),
                ],
                [
                    'code' => '028',
                    'name' => 'RDO 028 - Novaliches',
                    'barangays' => $normalize([
                        'Bagbag', 'Bagong Silangan', 'Batasan Hills', 'Capri', 'Commonwealth', 'Culiat',
                        'Fairview', 'Greater Lagro', 'Gulod', 'Holy Spirit', 'Kaligayahan', 'Matandang Balara',
                        'Nagkaisang Nayon', 'New Era', 'North Fairview', 'Novaliches Proper',
                        'Pasong Putik Proper', 'Pasong Tamo', 'Payatas', 'San Agustin', 'San Bartolome',
                        'Santa Lucia', 'Santa Monica', 'Sauyo', 'Talipapa', 'Tandang Sora',
                    ]),
                ],
            ],
            'makati' => [
                [
                    'code' => '047',
                    'name' => 'RDO 047 - East Makati',
                    'barangays' => $normalize(['San Lorenzo']),
                ],
                [
                    'code' => '048',
                    'name' => 'RDO 048 - West Makati',
                    'barangays' => $normalize(['Bangkal', 'Magallanes', 'Palanan', 'Pio del Pilar', 'San Isidro']),
                ],
                [
                    'code' => '049',
                    'name' => 'RDO 049 - North Makati City',
                    'barangays' => $normalize([
                        'Carmona', 'Guadalupe Viejo', 'Kasilawan', 'La Paz', 'Olympia', 'Poblacion',
                        'San Antonio', 'Santa Cruz', 'Singkamas', 'Tejeros', 'Valenzuela',
                    ]),
                ],
                [
                    'code' => '050',
                    'name' => 'RDO 050 - South Makati',
                    'barangays' => $normalize([
                        'Bel-Air', 'Dasmariñas', 'Dasmarinas', 'Forbes Park', 'Guadalupe Nuevo',
                        'Pinagkaisahan', 'Urdaneta',
                    ]),
                ],
            ],
            'pasig' => [
                [
                    'code' => '043A',
                    'name' => 'RDO 043A - East Pasig',
                    'barangays' => $normalize(['San Antonio', 'Bagong Ilog', 'Pineda', 'Kapitolyo', 'Oranbo', 'Orambo']),
                ],
                [
                    'code' => '043B',
                    'name' => 'RDO 043B - West Pasig',
                    'barangays' => $normalize([
                        'Ugong', 'Manggahan', 'Dela Paz', 'Rosario', 'Kalawaan', 'Sta. Lucia',
                        'Santa Lucia', 'Bambang', 'San Nicolas', 'Buting', 'Sagad', 'Caniogan',
                        'Kapasigan', 'Malinao', 'Maybunga', 'Santolan', 'San Miguel', 'San Joaquin',
                        'Sto. Tomas', 'Santo Tomas', 'Sta. Cruz', 'Santa Cruz', 'Pinagbuhatan',
                        'Palatiw', 'San Jose', 'Sumilang',
                    ]),
                ],
            ],
        ];
    }

    private function regionLabel(string $regionCode): string
    {
        return [
            '010000000' => 'Region I',
            '020000000' => 'Region II',
            '030000000' => 'Region III',
            '040000000' => 'Region IV-A',
            '170000000' => 'Region IV-B',
            '050000000' => 'Region V',
            '060000000' => 'Region VI',
            '070000000' => 'Region VII',
            '080000000' => 'Region VIII',
            '090000000' => 'Region IX',
            '100000000' => 'Region X',
            '110000000' => 'Region XI',
            '120000000' => 'Region XII',
            '130000000' => 'NCR',
            '140000000' => 'CAR',
            '150000000' => 'BARMM',
            '160000000' => 'CARAGA',
        ][$regionCode] ?? 'NCR';
    }

    private function provinceLabel(string $provinceCode): string
    {
        return [
            '012800000' => 'Ilocos Norte',
            '012900000' => 'Ilocos Sur',
            '013300000' => 'La Union',
            '015500000' => 'Pangasinan',
            '020900000' => 'Batanes',
            '021500000' => 'Cagayan',
            '023100000' => 'Isabela',
            '025000000' => 'Nueva Vizcaya',
            '025700000' => 'Quirino',
            '030800000' => 'Bataan',
            '031400000' => 'Bulacan',
            '034900000' => 'Nueva Ecija',
            '035400000' => 'Pampanga',
            '036900000' => 'Tarlac',
            '037100000' => 'Zambales',
            '037700000' => 'Aurora',
            '041000000' => 'Batangas',
            '042100000' => 'Cavite',
            '043400000' => 'Laguna',
            '045600000' => 'Quezon',
            '045800000' => 'Rizal',
            '174000000' => 'Marinduque',
            '175100000' => 'Occidental Mindoro',
            '175200000' => 'Oriental Mindoro',
            '175300000' => 'Palawan',
            '175900000' => 'Romblon',
            '050500000' => 'Albay',
            '051600000' => 'Camarines Norte',
            '051700000' => 'Camarines Sur',
            '052000000' => 'Catanduanes',
            '054100000' => 'Masbate',
            '056200000' => 'Sorsogon',
        ][$provinceCode] ?? '';
    }
}

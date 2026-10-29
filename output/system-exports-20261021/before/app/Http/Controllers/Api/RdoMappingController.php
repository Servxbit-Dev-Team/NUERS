<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BirCompliance;
use App\Services\RegistrationAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RdoMappingController extends Controller
{
    public function index(Request $r)
    {
        $u = app(BirCompliance::class)->administrativeUser($r);
        $geo = app(RegistrationAddress::class)->geography();
        $ref = json_decode(file_get_contents(resource_path('data/registration-rdos.json')), true);
        $managed = DB::table('registration_rdo_mappings')->get()->keyBy('scope');
        $rules = [];
        foreach ($ref['city_rules'] as $code => $rdo) {
            $city = $geo['cities'][$code];
            $rules['city:'.$code] = ['scope' => 'city:'.$code, 'city_code' => $code, 'city' => $city['name'], 'barangay_code' => null, 'barangay' => null, 'rdo_code' => $rdo, 'source_reference' => $ref['city_rule_sources'][$code]['sources'][0] ?? $ref['source'], 'active' => true, 'revision' => 0, 'source' => 'Reference directory'];
        }foreach ($ref['barangay_rules'] as $code => $rdo) {
            $b = $geo['barangays'][$code];
            $rules['barangay:'.$code] = ['scope' => 'barangay:'.$code, 'city_code' => $b['parent'], 'city' => $geo['cities'][$b['parent']]['name'], 'barangay_code' => $code, 'barangay' => $b['name'], 'rdo_code' => $rdo, 'source_reference' => $ref['barangay_source'] ?? $ref['source'], 'active' => true, 'revision' => 0, 'source' => 'Published barangay rule'];
        }foreach ($managed as $scope => $m) {
            $m = (array) $m;
            $m['city'] = $geo['cities'][$m['city_code']]['name'] ?? $m['city_code'];
            $m['barangay'] = $m['barangay_code'] ? ($geo['barangays'][$m['barangay_code']]['name'] ?? $m['barangay_code']) : null;
            $m['source'] = 'Administrator-maintained';
            $rules[$scope] = $m;
        }foreach ($rules as &$rule) {
            $city = $geo['cities'][$rule['city_code']];
            $rule['region_code'] = $city['region_code'];
            $rule['province_code'] = $city['parent'];
        }unset($rule);
        $mapped = count(array_filter($rules, fn ($x) => $x['active'] && ! $x['barangay_code']));

        return response()->json(['offices' => app(RegistrationAddress::class)->offices(), 'legacy_offices' => DB::table('rdo_offices')->whereNotIn('rdo_code', array_keys($ref['offices']))->get(['rdo_code', 'rdo_name']), 'rules' => array_values($rules), 'summary' => ['offices' => count(app(RegistrationAddress::class)->offices()), 'cities_total' => count(array_filter($geo['cities'], fn ($x) => ! ($x['is_district'] ?? false))), 'cities_mapped' => $mapped, 'barangay_rules' => count(array_filter($rules, fn ($x) => $x['active'] && $x['barangay_code']))], 'can_write' => in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']), 'notice' => 'The complete RDO directory is available. Office names are not a complete jurisdiction dataset; unmapped or conflicting locations require confirmation from the taxpayer registration.']);
    }

    public function store(Request $r)
    {
        $u = app(BirCompliance::class)->administrativeUser($r);
        abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin', 'super_admin']), 403);
        $v = $r->validate(['city_code' => 'required|digits:10', 'barangay_code' => 'nullable|digits:10', 'rdo_code' => ['required', Rule::in(array_column(app(RegistrationAddress::class)->offices(), 'rdo_code'))], 'source_reference' => 'required|string|min:10|max:2000', 'active' => 'required|boolean', 'revision' => 'required|integer|min:0']);
        $geo = app(RegistrationAddress::class)->geography();
        $city = $geo['cities'][$v['city_code']] ?? null;
        abort_unless($city && ! ($city['is_district'] ?? false), 422, 'Choose a city / municipality.');
        if (! empty($v['barangay_code'])) {
            abort_unless(($geo['barangays'][$v['barangay_code']]['parent'] ?? null) === $v['city_code'], 422, 'Barangay must belong to the selected city.');
        }$scope = ! empty($v['barangay_code']) ? 'barangay:'.$v['barangay_code'] : 'city:'.$v['city_code'];

        return DB::transaction(function () use ($v, $scope, $u) {
            DB::table('business_registry_lock')->where('id', 1)->lockForUpdate()->first();
            $old = DB::table('registration_rdo_mappings')->where('scope', $scope)->lockForUpdate()->first();
            abort_unless(($old?->revision ?? 0) === $v['revision'], 409, 'Mapping changed. Refresh before saving.');
            $data = ['scope' => $scope, 'city_code' => $v['city_code'], 'barangay_code' => $v['barangay_code'] ?? null, 'rdo_code' => $v['rdo_code'], 'source_reference' => $v['source_reference'], 'active' => $v['active'], 'revision' => $v['revision'] + 1, 'updated_by' => $u->id, 'updated_at' => now()];
            if ($old) {
                DB::table('registration_rdo_mappings')->where('scope', $scope)->update($data);
            } else {
                DB::table('registration_rdo_mappings')->insert($data + ['created_at' => now()]);
            }app(BirCompliance::class)->audit('rdo_mapping.updated', $scope, (string) $u->id, ['before' => $old, 'after' => $data]);

            return response()->json(['mapping' => $data]);
        });
    }
}

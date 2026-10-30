<?php

namespace App\Http\Controllers\Api;

use App\Services\{ApiAccess,BirCompliance,BusinessProfileChanges,RegistrationAddress,RegistrationIndustry};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt,DB};

class BusinessProfileChangeController
{
    private function owner(Request $r): object
    {
        $u = app(ApiAccess::class)->require($r, ['merchant']);
        $m = DB::table('merchants')->where('merchant_account_id', $u->id)->orderBy('id')->first();
        abort_unless($m, 403, 'Only the linked business owner can request profile changes.');
        return $m;
    }
    private function admin(Request $r): object
    {
        $u = app(BirCompliance::class)->administrativeUser($r);
        abort_unless(in_array(DB::table('profiles')->where('id', $u->id)->value('role'), ['admin','super_admin'], true), 403);
        return $u;
    }
    public function index(Request $r)
    {
        $m = $this->owner($r);
        return response()->json(['current' => app(BusinessProfileChanges::class)->current($m),'requests' => $this->rows($r, $m->id),'options' => ['types' => BusinessRegistrationController::BUSINESS_TYPES,'industries' => app(RegistrationIndustry::class)->options(),'rdos' => app(RegistrationAddress::class)->offices()]])->header('Cache-Control', 'private, no-store');
    }
    private function rows(Request $r, ?string $merchant = null): array
    {
        $r->validate(['page' => 'nullable|integer|min:1','status' => 'nullable|in:pending,all']);
        $q = DB::table('business_registry_requests as q')->join('merchants as m', 'm.id', '=', 'q.merchant_id')->where('q.purpose', 'profile_change')->select('q.*', 'm.business_name');
        if ($merchant) {
            $q->where('q.merchant_id', $merchant);
        }
        if ($r->query('status', $merchant ? 'all' : 'pending') === 'pending') {
            $q->whereNull('q.completed_at');
        }
        $total = $q->count();
        $page = max(1, min($r->integer('page', 1), max(1, (int)ceil($total / 10))));
        return ['data' => $q->orderByDesc('q.created_at')->orderByDesc('q.id')->offset(($page - 1) * 10)->limit(10)->get()->map(fn ($row) => app(BusinessProfileChanges::class)->present($row))->all(),'total' => $total,'page' => $page,'last_page' => max(1, (int)ceil($total / 10))];
    }
    public function adminIndex(Request $r)
    {
        $this->admin($r);
        return response()->json($this->rows($r))->header('Cache-Control', 'private, no-store');
    }
    public function store(Request $r)
    {
        $m = $this->owner($r);
        $r->merge(['reason' => trim((string)$r->input('reason'))]);
        $v = $r->validate(['request_id' => 'required|uuid','changes' => 'required|array|min:1','reason' => 'required|string|min:10|max:2000']);
        return DB::transaction(function () use ($m, $r, $v) {
            $m = DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            abort_unless((string)$m->merchant_account_id === (string)$r->attributes->get('api_actor')->id && !in_array($m->status, ['suspended','inactive']), 403);
            $result = app(BusinessProfileChanges::class)->submit($m, (string)$m->merchant_account_id, $v['request_id'], $v['changes'], trim($v['reason']));
            return response()->json($result, 201)->header('Cache-Control', 'private, no-store');
        });
    }
    public function withdraw(Request $r, string $id)
    {
        $m = $this->owner($r);
        return DB::transaction(function () use ($m, $id) {
            $fresh = DB::table('merchants')->where('id', $m->id)->lockForUpdate()->first();
            abort_unless((string)$fresh->merchant_account_id === (string)$m->merchant_account_id && !in_array($fresh->status, ['suspended','inactive']), 403, 'Business ownership or access changed.');
            $row = DB::table('business_registry_requests')->where('id', $id)->where('merchant_id', $m->id)->where('purpose', 'profile_change')->lockForUpdate()->first();
            abort_unless($row, 404);
            $p = json_decode(Crypt::decryptString($row->payload), true);
            abort_unless($p['status'] === 'pending', 409, 'This request already has a decision.');
            $p['status'] = 'withdrawn';
            DB::table('business_registry_requests')->where('id', $id)->update(['payload' => Crypt::encryptString(json_encode($p)),'completed_at' => now(),'revoked_at' => now(),'updated_at' => now()]);
            app(BirCompliance::class)->audit('business.profile.change_withdrawn', $m->id, (string)$m->merchant_account_id, ['request_id' => $id]);
            return response()->json(['status' => 'withdrawn']);
        });
    }
    public function review(Request $r, string $id)
    {
        $u = $this->admin($r);
        $r->merge(['reason' => trim((string)$r->input('reason'))]);
        $v = $r->validate(['decision' => 'required|in:approved,rejected','reason' => 'required|string|min:10|max:2000','evidence_confirmed' => 'accepted_if:decision,approved']);
        return DB::transaction(function () use ($r, $id, $u, $v) {
            DB::table('business_registry_lock')->where('id', 1)->lockForUpdate()->first();
            $candidate = DB::table('business_registry_requests')->where('id', $id)->where('purpose', 'profile_change')->first();
            abort_unless($candidate, 404);
            $m = DB::table('merchants')->where('id', $candidate->merchant_id)->lockForUpdate()->first();
            abort_unless($m, 404);
            $row = DB::table('business_registry_requests')->where('id', $id)->lockForUpdate()->first();
            abort_if((string)$u->id === (string)$row->actor_id || (string)$u->id === (string)$m->merchant_account_id, 403, 'An independent administrator must review this request.');
            $service = app(BusinessProfileChanges::class);
            $p = json_decode(Crypt::decryptString($row->payload), true, flags:JSON_THROW_ON_ERROR);
            if ($p['status'] !== 'pending') {
                abort_unless($p['status'] === $v['decision'] && ($p['review_reason'] ?? '') === trim($v['reason']), 409, 'This request already has a decision.');
                return response()->json($service->present($row));
            }
            if ($v['decision'] === 'approved') {
                abort_if(in_array($m->status, ['inactive','suspended']), 409, 'Restore business access before approving profile changes.');
                $current = $service->current($m);
                foreach ($p['before'] as $field => $old) {
                    abort_unless((string)($current[$field] ?? '') === (string)($old ?? ''), 409, 'Registered information changed after this request. Reject it and ask the merchant to submit updated values.');
                }
                $service->validate(array_diff_key($p['changes'], ['logo_url' => true]), $m);
                $service->apply($m, $p['changes'], (string)$u->id, trim($v['reason']));
            }
            $p['status'] = $v['decision'];
            $p['review_reason'] = trim($v['reason']);
            $p['reviewed_by'] = (string)$u->id;
            DB::table('business_registry_requests')->where('id', $id)->update(['payload' => Crypt::encryptString(json_encode($p, JSON_THROW_ON_ERROR)),'completed_at' => now(),'updated_at' => now()]);
            app(BirCompliance::class)->audit('business.profile.change_'.$v['decision'], $m->id, (string)$u->id, ['request_id' => $id,'fields' => array_keys($p['changes']),'reason' => $v['reason']]);
            return response()->json($service->present(DB::table('business_registry_requests')->where('id',$id)->first()))->header('Cache-Control','private, no-store');
        });
    }
}

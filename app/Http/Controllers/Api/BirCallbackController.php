<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Eis\Protocol;
use App\Services\Eis\ResultVerifier;
use App\Services\Eis\WireClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BirCallbackController extends Controller
{
    private function failure(string $code, int $http)
    {
        return response()->json(['status' => '0', 'errorDetails' => ['errorCode' => $code, 'errorMessage' => 'Callback could not be verified.']], $http)->header('Cache-Control', 'no-store');
    }

    public function receive(Request $r, string $id)
    {
        if (! config('bir_eis.callback_enabled')) {
            return $this->failure('E17', 503);
        }
        if (strlen($r->getContent()) > 262144 || $r->getQueryString()) {
            return $this->failure('E11', 400);
        }
        $p = DB::table('bir_eis_profiles')->find($id);
        if (! $p || ! $p->enabled || ! $p->encrypted_credentials) {
            return $this->failure('E04', 401);
        }
        try {
            $c = app(WireClient::class)->credentials($p);
            $datetime = (string) $r->header('datetime');
            if (! preg_match('/^\d{14}$/D', $datetime)) {
                return $this->failure('E05', 401);
            }
            $at = CarbonImmutable::createFromFormat('!YmdHis', $datetime, 'Asia/Manila');
            if (! $at || $at->format('YmdHis') !== $datetime || abs($at->timestamp - now()->timestamp) > 600) {
                return $this->failure('E05', 401);
            }
            $expected = Protocol::authorization($datetime, 'PUT', $r->getPathInfo(), $c['application_key']);
            if (! hash_equals($expected, (string) $r->header('authorization')) || $r->header('accreditationId') !== $p->accreditation_id || $r->header('applicationId') !== $p->application_id) {
                return $this->failure('E04', 401);
            }
            $data = json_decode($r->getContent(), true, 32, JSON_THROW_ON_ERROR);
            $b = DB::table('bir_eis_batches')->where('profile_id', $id)->where('submit_id', $data['refSubmitId'] ?? '')->first();
            if (! $b || ! $b->submitted_at) {
                return $this->failure('E15', 404);
            }
            app(ResultVerifier::class)->verify($data, (object) ['accreditation_id' => $p->accreditation_id, 'user_id' => $c['user_id']], $b->submit_id, DB::table('bir_eis_documents')->where('batch_id', $b->id)->pluck('invoice_uid')->all(), $b->ack_id);
            $hash = hash('sha256', $r->getContent());
            $ack = DB::transaction(function () use ($b, $p, $hash) {
                $fresh = DB::table('bir_eis_batches')->where('id', $b->id)->lockForUpdate()->first();
                $existing = DB::table('bir_eis_callbacks')->where('batch_id', $b->id)->where('body_hash', $hash)->first();
                if ($existing) {
                    return $existing->ack_id;
                }
                $ack = $p->accreditation_id.'-'.now('Asia/Manila')->format('YmdHis').'-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
                DB::table('bir_eis_callbacks')->insert(['id' => (string) Str::uuid(), 'batch_id' => $b->id, 'body_hash' => $hash, 'ack_id' => $ack, 'created_at' => now()]);
                // BIR's HMAC signs datetime/method/path, not the callback body. Only a fresh authenticated GET may publish final results.
                if (in_array($fresh->state, ['processing', 'uncertain', 'reconciliation'], true) && (! $fresh->lease_until || now()->gt($fresh->lease_until))) {
                    DB::table('bir_eis_batches')->where('id', $b->id)->update(['state' => 'uncertain', 'available_at' => now(), 'updated_at' => now()]);
                }

                return $ack;
            }, 3);

            // The specification's worked example uses an object; the conflicting encryption note is listed in the certification checklist.
            return response()->json(['status' => '1', 'data' => ['accreditationId' => $p->accreditation_id, 'userId' => $c['user_id'], 'refSubmitId' => $b->submit_id, 'taxpayerAckId' => $ack, 'description' => 'Callback received. Final status is reconciled through the BIR inquiry API.']])->header('Cache-Control', 'no-store');
        } catch (\Throwable) {
            return $this->failure('E31', 400);
        }
    }
}

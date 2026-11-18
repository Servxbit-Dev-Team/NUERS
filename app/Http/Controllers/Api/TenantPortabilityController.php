<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApiAccess;
use App\Services\BirCompliance;
use App\Services\TenantPortability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TenantPortabilityController extends Controller
{
    public function export(Request $r, string $merchant)
    {
        $u = app(ApiAccess::class)->require($r, ['merchant', 'admin', 'super_admin', 'bir']);
        abort_unless(DB::table('merchants')->where('id', $merchant)->exists(), 404);
        if ($r->attributes->get('api_role') === 'merchant') {
            abort_unless(app(ApiAccess::class)->merchants($u)->where('id', $merchant)->exists(), 404);
        }
        app(BirCompliance::class)->audit('tenant.portability.exported', $merchant, (string) $u->id, ['format' => 'NUERS-portability-1', 'includes_internal_preparations' => true]);

        $includeWallet = $r->attributes->get('api_role') === 'super_admin' || app(ApiAccess::class)->merchants($u)->where('id', $merchant)->exists();

        return response()->streamDownload(function () use ($merchant, $includeWallet) {
            DB::transaction(function () use ($merchant, $includeWallet) {
                echo json_encode(['type' => 'header', 'schema' => 'NUERS-portability-1', 'merchant_id' => $merchant, 'exported_at' => now()->toIso8601String(), 'note' => 'Original source records, delivery evidence and decrypted internal preparations. Preserve this confidential export.'])."\n";
                $manifest = [];
                foreach (app(TenantPortability::class)->queries($merchant) as $table => $query) {
                    if (str_starts_with($table, 'merchant_wallet') && ! $includeWallet) {
                        continue;
                    }
                    $hash = hash_init('sha256');
                    $count = 0;
                    foreach ($query->orderBy('id')->cursor() as $record) {
                        $row = (array) $record;
                        // Include usable internal snapshots and retain their original encrypted representation and hashes.
                        if ($table === 'eis_document_outbox') {
                            try {
                                $row['canonical_document'] = Crypt::decryptString($row['encrypted_document']);
                                $row['hash_verified'] = hash_equals($row['document_hash'], hash('sha256', $row['canonical_document']));
                            } catch (\Throwable) {
                                $row['decryption_error'] = 'Key custody/source review required';
                            }
                        }
                        if ($table === 'merchant_customers') {
                            $row['profile'] = json_decode(Crypt::decryptString($row['profile_encrypted']), true, flags: JSON_THROW_ON_ERROR);
                            $row['review_reference'] = $row['review_reference_encrypted'] ? Crypt::decryptString($row['review_reference_encrypted']) : null;
                        }
                        if ($table === 'merchant_wallet_topups') {
                            $row['payment_reference'] = Crypt::decryptString($row['payment_reference_encrypted']);
                            $row['review_note'] = $row['review_encrypted'] ? Crypt::decryptString($row['review_encrypted']) : null;
                        }
                        if ($table === 'merchant_customer_history') {
                            $row['change'] = json_decode(Crypt::decryptString($row['change_encrypted']), true, flags: JSON_THROW_ON_ERROR);
                        }
                        if ($table === 'merchant_customer_documents') {
                            $row['reason'] = Crypt::decryptString($row['reason_encrypted']);
                        }
                        $line = json_encode(['type' => 'record', 'table' => $table, 'record' => $row], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
                        hash_update($hash, $line);
                        echo $line;
                        $count++;
                    }
                    $manifest[$table] = ['records' => $count, 'sha256' => hash_final($hash)];
                }
                echo json_encode(['type' => 'manifest', 'tables' => $manifest, 'manifest_sha256' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR)."\n";
            });
        }, 'NUERS-'.$merchant.'-'.now()->format('YmdHis').'.ndjson', ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'private, no-store']);
    }
}

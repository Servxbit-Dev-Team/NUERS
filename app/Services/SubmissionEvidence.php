<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** All production acceptance readers share the same fail-closed evidence predicate. */
class SubmissionEvidence
{
    public function verified(string $transmission): bool
    {
        if (! config('bir_compliance.transport_implemented') || ! config('bir_compliance.official_interface_verified') || ! config('bir_compliance.production_response_verification_implemented') || ! Schema::hasColumn('eis_acknowledgements', 'verified_at')) {
            return false;
        }
        $t = DB::table('eis_transmissions')->where('id', $transmission)->where('environment', 'production')->whereIn('status', ['accepted', 'acknowledged', 'success'])->first();
        if (! $t || ! $t->submitted_at || ! $t->bir_reference_number || str_starts_with($t->bir_reference_number, 'CERT-')) {
            return false;
        }
        foreach (DB::table('eis_acknowledgements')->where('transmission_id', $transmission)->whereNotNull('verified_at')->cursor() as $a) {
            if (! $a->acknowledgement_number || ! $a->raw_response || ! $a->verified_response_hash || $a->bir_reference_number !== $t->bir_reference_number) {
                continue;
            }
            if (hash_equals($a->verified_response_hash, hash('sha256', $a->raw_response))) {
                return true;
            }
        }

        return false;
    }

    public function count($query): int
    {
        if (! config('bir_compliance.production_response_verification_implemented')) {
            return 0;
        }
        $count = 0;
        foreach ($query->select('id')->cursor() as $row) {
            if ($this->verified($row->id)) {
                $count++;
            }
        }

        return $count;
    }
}

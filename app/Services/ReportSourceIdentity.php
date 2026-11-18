<?php
namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Display metadata only: never substitutes for a verified BIR branch code. */
class ReportSourceIdentity
{
    public function decorate(Builder $query): Builder
    {
        $q = DB::query()->fromSub($query, 'source_record')->select('source_record.*');
        $snapshot = Schema::hasColumn('merchant_transactions', 'source_api_key_name') ? 'st.source_api_key_name' : 'NULL';
        $base = DB::table('merchant_transactions as st')
            ->leftJoin('merchant_api_keys as sk', fn ($j) => $j->on('sk.id', '=', 'st.source_api_key_id')->on('sk.merchant_id', '=', 'st.merchant_id'))
            ->whereColumn('st.id', 'source_record.transaction_id')->whereColumn('st.merchant_id', 'source_record.merchant_id');
        $q->selectSub((clone $base)->selectRaw("COALESCE(NULLIF($snapshot,''),sk.name)"), 'api_key_name');
        $q->selectSub((clone $base)->selectRaw("CASE WHEN NULLIF($snapshot,'') IS NULL AND sk.name IS NOT NULL THEN 1 ELSE 0 END"), 'api_key_name_current');
        $q->selectSub((clone $base)->select('st.branch'), 'source_branch_name');
        return $q;
    }
}

<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Group PHT chart buckets in SQL without requiring MySQL timezone tables. */
class ReportingTime
{
    public static function bucket(string $column, string $format): string
    {
        $offset = CarbonImmutable::now('Asia/Manila')->utcOffset() - CarbonImmutable::now(config('app.timezone', 'UTC'))->utcOffset();
        $sqlFormat = match ($format) { 'H' => '%H', 'm' => '%m', 'Y-m-d H:i' => '%Y-%m-%d %H:%i', default => '%Y-%m-%d' };
        if (DB::getDriverName() === 'sqlite') {
            $sqlFormat = str_replace('%i', '%M', $sqlFormat);
            return "strftime('$sqlFormat',$column,'$offset minutes')";
        }
        return "DATE_FORMAT(DATE_ADD($column,INTERVAL $offset MINUTE),'$sqlFormat')";
    }
}

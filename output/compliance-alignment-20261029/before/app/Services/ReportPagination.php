<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

/** Select a narrow page before loading wide records; deterministic ties in both directions. */
class ReportPagination
{
    public function page(Builder $query, int $total, int $page, int $size, string $column, string $direction = 'desc', string $key = 'id', array $tieColumns = []): array
    {
        $size = max(1, min(100, $size));
        $last = max(1, (int) ceil($total / $size));
        $page = max(1, min($page, $last));
        $offset = ($page - 1) * $size;
        $take = min($size, max(0, $total - $offset));
        $reverse = $offset > $total / 2;
        $readDirection = $reverse ? ($direction === 'desc' ? 'asc' : 'desc') : $direction;
        $keys = (clone $query)->reorder()->select($key)->orderBy($column, $readDirection);
        foreach ($tieColumns as $tie) $keys->orderBy($tie, $readDirection);
        $ids = $keys->orderBy($key, $readDirection)->offset($reverse ? max(0, $total - $offset - $take) : $offset)->limit($take)->pluck($key);
        return ['ids' => $reverse ? $ids->reverse()->values() : $ids, 'page' => $page, 'last_page' => $last, 'total' => $total];
    }
}

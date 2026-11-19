<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Finance\Money\MoneyMath;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;

final class Sql
{
    /** SUM done by PostgreSQL and returned as text: money never passes through a float. Global scopes apply. */
    public static function sum(Builder $query, string $column = 'amount'): BigDecimal
    {
        $total = $query->toBase()->selectRaw("COALESCE(SUM({$column}), 0)::text AS total")->value('total');

        return MoneyMath::exact2((string) $total);
    }
}
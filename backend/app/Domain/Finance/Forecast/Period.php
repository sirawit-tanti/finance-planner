<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use DateTimeImmutable;

/** One financial month. key = Y-m of the START date (period 25 Nov - 24 Dec has key 2026-11). */
final class Period
{
    public function __construct(
        public readonly string $key,
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
    ) {
    }
}
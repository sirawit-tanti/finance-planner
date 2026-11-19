<?php

declare(strict_types=1);

namespace App\Domain\Finance\WhatIf;

use Brick\Math\BigDecimal;

final class MonthDiff
{
    public function __construct(
        public readonly string $key,
        public readonly BigDecimal $closingBefore,
        public readonly BigDecimal $closingAfter,
        public readonly BigDecimal $closingDelta,
        public readonly BigDecimal $minBefore,
        public readonly BigDecimal $minAfter,
        public readonly string $riskBefore,
        public readonly string $riskAfter,
    ) {
    }
}
<?php

declare(strict_types=1);

namespace App\Application\Forecast;

use App\Domain\Finance\Forecast\CardCharge;
use App\Domain\Finance\Forecast\KnownStatement;

/** Everything the forecast needs to know about one active card. */
final class CardContext
{
    /**
     * @param list<KnownStatement> $known
     * @param list<CardCharge>     $charges future charges not yet inside a statement
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $statementDay,
        public readonly int $dueDay,
        public readonly array $known,
        public readonly array $charges,
    ) {
    }
}
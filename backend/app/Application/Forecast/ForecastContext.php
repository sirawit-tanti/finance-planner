<?php

declare(strict_types=1);

namespace App\Application\Forecast;

use App\Domain\Finance\Forecast\CashEvent;
use App\Domain\Finance\Forecast\PeriodCalendar;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** All forecast inputs for one user, loaded from the database. Immutable. */
final class ForecastContext
{
    /**
     * @param list<CashEvent>  $baseEvents every event except credit-card payments
     * @param list<CardContext> $cards
     */
    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
        public readonly int $months,
        public readonly PeriodCalendar $calendar,
        public readonly BigDecimal $openingCash,
        public readonly BigDecimal $emergencyFund,
        public readonly BigDecimal $reservedAtStart,
        public readonly array $baseEvents,
        public readonly array $cards,
        public readonly int $dataVersion,
    ) {
    }

    public function hasCard(string $cardId): bool
    {
        foreach ($this->cards as $card) {
            if ($card->id === $cardId) {
                return true;
            }
        }

        return false;
    }
}
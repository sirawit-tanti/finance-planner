<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\CreditCards\StatementAssigner;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Cash going out to a credit card = ONE payment per statement, on its due date.
 * (Card purchases themselves are never cash events: that would count the money twice.)
 *
 *   payment = outstanding of the existing statement (if any) + future charges in that cycle
 *
 * Assumptions (Phase 4.4a): future statements are paid in full; a statement that is
 * already past due and unpaid is placed on $from (it has to be paid now).
 * Charges dated before $from are ignored: they are already recorded in real statements.
 */
final class CardProjector
{
    /**
     * @param list<KnownStatement> $known
     * @param list<CardCharge>     $charges
     * @return list<CashEvent>
     */
    public function project(
        string $cardId,
        string $cardName,
        int $statementDay,
        int $dueDay,
        array $known,
        array $charges,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $assigner = new StatementAssigner();
        $zero = BigDecimal::of('0.00');
        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');

        /** @var array<string, array{due: DateTimeImmutable, amount: BigDecimal}> $groups keyed by statement date */
        $groups = [];

        foreach ($known as $statement) {
            $key = $statement->statementDate->format('Y-m-d');
            $groups[$key] ??= ['due' => $statement->dueDate, 'amount' => $zero];
            $groups[$key]['amount'] = $groups[$key]['amount']->plus($statement->outstanding);
        }

        foreach ($charges as $charge) {
            if ($charge->date->format('Y-m-d') < $fromDay) {
                continue;
            }
            $cycle = $assigner->cycleFor($statementDay, $dueDay, $charge->date);
            $key = $cycle->statementDate->format('Y-m-d');
            $groups[$key] ??= ['due' => $cycle->dueDate, 'amount' => $zero];
            $groups[$key]['amount'] = $groups[$key]['amount']->plus($charge->amount);
        }

        ksort($groups);

        $events = [];
        foreach ($groups as $group) {
            if (! $group['amount']->isPositive()) {
                continue;
            }

            $overdue = $group['due']->format('Y-m-d') < $fromDay;
            $date = $overdue ? $from : $group['due'];
            if ($date->format('Y-m-d') > $toDay) {
                continue;
            }

            $events[] = new CashEvent(
                $date,
                $group['amount'],
                Direction::Out,
                EventKind::CardPayment,
                'ชำระบัตร ' . $cardName . ($overdue ? ' (เลยกำหนด)' : ''),
                'credit_card',
                $cardId,
            );
        }

        return $events;
    }
}
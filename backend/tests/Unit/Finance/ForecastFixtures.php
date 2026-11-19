<?php

declare(strict_types=1);

namespace Tests\Unit\Finance;

use App\Domain\Finance\Forecast\CashEvent;
use App\Domain\Finance\Forecast\Direction;
use App\Domain\Finance\Forecast\EventKind;
use App\Domain\Finance\Forecast\ForecastEngine;
use App\Domain\Finance\Forecast\ForecastResult;
use App\Domain\Finance\Forecast\PeriodCalendar;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DateTimeZone;

final class ForecastFixtures
{
    public static function d(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public static function event(
        string $date,
        string $amount,
        Direction $direction,
        EventKind $kind,
        string $label = 'x',
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $principal = null,
        ?string $interest = null,
        ?string $fee = null,
    ): CashEvent {
        return new CashEvent(
            self::d($date), $amount, $direction, $kind, $label, $sourceType, $sourceId,
            $principal === null ? null : BigDecimal::of($principal),
            $interest === null ? null : BigDecimal::of($interest),
            $fee === null ? null : BigDecimal::of($fee),
        );
    }

    /**
     * Forecast from 2026-11-19. Includes one event before the start and one after the
     * 3-month horizon; the engine must ignore both.
     *
     * @return list<CashEvent>
     */
    public static function baseEvents(): array
    {
        $out = Direction::Out;
        $in = Direction::In;

        return [
            self::event('2026-11-10', '999', $out, EventKind::ExpensePlanned, 'before start'),
            self::event('2026-11-20', '7700', $out, EventKind::ExpenseRecurring, 'ค่าผ่อนบ้าน', 'recurring_expense', 'mortgage'),
            self::event('2026-11-25', '32000', $in, EventKind::IncomeRecurring, 'เงินเดือน', 'income_source', 'salary'),
            self::event('2026-11-25', '5000', $out, EventKind::CardPayment, 'ชำระบัตร A', 'credit_card', 'card-a'),
            self::event('2026-12-01', '5000', Direction::Reserve, EventKind::SavingsReserve, 'ออม', 'savings_goal', 'goal-1'),
            self::event('2026-12-10', '2800', $out, EventKind::Installment, 'ผ่อนโทรศัพท์', 'installment_plan', 'plan-1', '2500', '300', '0'),
            self::event('2026-12-20', '7700', $out, EventKind::ExpenseRecurring, 'ค่าผ่อนบ้าน', 'recurring_expense', 'mortgage'),
            self::event('2026-12-25', '32000', $in, EventKind::IncomeRecurring, 'เงินเดือน', 'income_source', 'salary'),
            self::event('2026-12-25', '5000', $out, EventKind::CardPayment, 'ชำระบัตร A', 'credit_card', 'card-a'),
            self::event('2027-01-20', '7700', $out, EventKind::ExpenseRecurring, 'ค่าผ่อนบ้าน', 'recurring_expense', 'mortgage'),
            self::event('2027-01-25', '32000', $in, EventKind::IncomeRecurring, 'เงินเดือน', 'income_source', 'salary'),
            self::event('2027-02-01', '888', $out, EventKind::ExpensePlanned, 'after horizon'),
        ];
    }

    /** @param list<CashEvent> $events */
    public static function run(
        array $events,
        int $months = 3,
        string $opening = '10000',
        string $emergency = '3000',
        string $from = '2026-11-19',
        int $startDay = 1,
    ): ForecastResult {
        return (new ForecastEngine())->run(
            self::d($from), $months, new PeriodCalendar($startDay), $opening, $events, $emergency
        );
    }
}
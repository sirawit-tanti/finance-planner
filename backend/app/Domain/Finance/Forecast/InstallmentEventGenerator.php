<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use App\Domain\Finance\Installments\PayoffQuote;
use App\Domain\Finance\Installments\ScheduleItem;
use DateTimeImmutable;

/**
 * Installment rows => forecast data.
 *  - plans paid from an ACCOUNT become cash events here (interest/principal/fee kept apart)
 *  - plans charged to a CARD become CardCharges: the money leaves when the statement is paid
 *  - an unpaid row that is already past due is placed on $from
 */
final class InstallmentEventGenerator
{
    /**
     * @param list<ScheduleItem> $pending rows not yet paid
     * @return list<CashEvent>
     */
    public function accountEvents(
        string $planId,
        string $name,
        array $pending,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');
        $events = [];

        foreach ($pending as $item) {
            if (! $item->totalDue->isPositive()) {
                continue;
            }

            $overdue = $item->dueDate->format('Y-m-d') < $fromDay;
            $date = $overdue ? $from : $item->dueDate;
            if ($date->format('Y-m-d') > $toDay) {
                continue;
            }

            $events[] = new CashEvent(
                $date,
                $item->totalDue,
                Direction::Out,
                EventKind::Installment,
                $this->label($name, $item) . ($overdue ? ' (เลยกำหนด)' : ''),
                'installment_plan',
                $planId,
                $item->principalPart,
                $item->interestPart,
                $item->feePart,
            );
        }

        return $events;
    }

    /**
     * @param list<ScheduleItem> $pending
     * @return list<CardCharge>
     */
    public function cardCharges(string $planId, string $name, array $pending): array
    {
        $charges = [];
        foreach ($pending as $item) {
            if ($item->totalDue->isPositive()) {
                $charges[] = new CardCharge($item->dueDate, $item->totalDue, $this->label($name, $item), 'installment_plan', $planId);
            }
        }

        return $charges;
    }

    /**
     * One event that pays the whole plan off. The parts add up to the amount:
     *   interest = remaining interest - discount, fee = remaining fees + early fee.
     */
    public function payoffEvent(string $planId, string $name, DateTimeImmutable $date, PayoffQuote $quote): CashEvent
    {
        return new CashEvent(
            $date,
            $quote->payoffAmount,
            Direction::Out,
            EventKind::Installment,
            'ปิดบัญชี ' . $name,
            'installment_plan',
            $planId,
            $quote->remainingPrincipal,
            $quote->remainingInterest->minus($quote->interestDiscount),
            $quote->remainingFees->plus($quote->earlyFee),
        );
    }

    private function label(string $name, ScheduleItem $item): string
    {
        return $item->installmentNo === 0
            ? $name . ' (ค่าธรรมเนียมแรกเริ่ม)'
            : $name . ' งวด ' . $item->installmentNo;
    }
}
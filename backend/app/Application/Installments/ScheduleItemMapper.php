<?php

declare(strict_types=1);

namespace App\Application\Installments;

use App\Domain\Finance\Installments\ScheduleItem;
use App\Domain\Finance\Money\MoneyMath;
use App\Models\InstallmentScheduleItem;
use App\Support\Day;
use DateTimeImmutable;

final class ScheduleItemMapper
{
    /** Database row => domain row. $clampTo moves an earlier due date up to that day (card charges). */
    public static function fromRow(InstallmentScheduleItem $row, ?DateTimeImmutable $clampTo = null): ScheduleItem
    {
        $due = Day::of($row->due_date);
        if ($clampTo !== null && $due->format('Y-m-d') < $clampTo->format('Y-m-d')) {
            $due = $clampTo;
        }

        return new ScheduleItem(
            (int) $row->installment_no,
            $due,
            MoneyMath::exact2((string) $row->principal_part),
            MoneyMath::exact2((string) $row->interest_part),
            MoneyMath::exact2((string) $row->fee_part),
            MoneyMath::exact2((string) $row->total_due),
            MoneyMath::exact2((string) $row->remaining_principal_after),
        );
    }
}
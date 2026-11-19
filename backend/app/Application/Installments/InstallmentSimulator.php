<?php

declare(strict_types=1);

namespace App\Application\Installments;

use App\Application\Forecast\ForecastContext;
use App\Application\Forecast\ForecastRunner;
use App\Application\Forecast\ForecastSerializer;
use App\Domain\Finance\Forecast\InstallmentEventGenerator;
use App\Domain\Finance\Installments\RateSolver;
use App\Domain\Finance\Installments\Schedule;
use App\Domain\Finance\Money\MoneyMath;
use App\Domain\Finance\WhatIf\AddEvents;
use App\Domain\Finance\WhatIf\ScenarioComparator;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** "What if I buy this on installments?" Nothing is saved. */
final class InstallmentSimulator
{
    public function __construct(
        private readonly InstallmentService $installments,
        private readonly ForecastRunner $runner,
        private readonly ForecastSerializer $serializer,
    ) {
    }

    /** @return array<string, mixed> */
    public function simulate(InstallmentInput $input, ForecastContext $ctx): array
    {
        $schedule = $this->installments->schedule($input);
        $generator = new InstallmentEventGenerator();
        $events = [];
        $extraCharges = [];

        if ($input->fundingType === 'account') {
            $events = $generator->accountEvents('simulation', $input->name, $schedule->items, $ctx->from, $ctx->to);
        } else {
            if (! $ctx->hasCard((string) $input->cardId)) {
                throw ValidationException::withMessages(['credit_card_id' => ['บัตรนี้ไม่ได้อยู่ในสถานะใช้งาน']]);
            }
            $extraCharges[$input->cardId] = $generator->cardCharges('simulation', $input->name, $schedule->items);
        }

        $before = $this->runner->run($ctx);
        $after = $this->runner->run($ctx, [new AddEvents($events)], $extraCharges);

        $regular = array_values(array_filter($schedule->items, fn ($i) => $i->installmentNo >= 1));

        return [
            'schedule' => array_map(fn ($i) => [
                'installment_no' => $i->installmentNo,
                'due_date' => $i->dueDate->format('Y-m-d'),
                'principal' => (string) $i->principalPart,
                'interest' => (string) $i->interestPart,
                'fee' => (string) $i->feePart,
                'total_due' => (string) $i->totalDue,
                'remaining_principal' => (string) $i->remainingPrincipalAfter,
            ], $schedule->items),
            'totals' => [
                'financed_amount' => (string) $input->terms->financedAmount,
                'principal' => (string) $schedule->totalPrincipal(),
                'interest' => (string) $schedule->totalInterest(),
                'fees' => (string) $schedule->totalFees(),
                'payable' => (string) $schedule->totalPayable(),
                'monthly_payment' => (string) $regular[0]->totalDue,
                'effective_annual_rate_percent' => $this->effectiveRatePercent($input, $schedule),
            ],
            'impact' => $this->serializer->comparison((new ScenarioComparator())->compare($before, $after)),
        ];
    }

    /** The REAL yearly cost of the deal (e.g. a flat 12% is far more than 12% effective). Null if it cannot be solved. */
    private function effectiveRatePercent(InstallmentInput $input, Schedule $schedule): ?string
    {
        $upfrontFee = BigDecimal::of('0.00');
        foreach ($schedule->items as $item) {
            if ($item->installmentNo === 0) {
                $upfrontFee = $upfrontFee->plus($item->feePart);
            }
        }

        try {
            $periodic = RateSolver::irr($input->terms->financedAmount->minus($upfrontFee), $schedule->installmentAmounts());
        } catch (InvalidArgumentException) {
            return null;
        }

        $annual = RateSolver::effectiveFromPeriodic($periodic, $input->terms->frequency->periodsPerYear());

        return (string) MoneyMath::div($annual->multipliedBy(100), 1, 4);
    }
}
<?php

declare(strict_types=1);

namespace App\Application\Statements;

use App\Domain\Finance\CreditCards\StatementAssigner;
use App\Models\CreditCard;
use App\Models\CreditCardStatement;
use App\Support\Day;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Finds (and creates) the statement a date belongs to. Missing statements between the oldest,
 * the newest and the requested one are created too, so the carry-over chain never has gaps.
 */
final class StatementService
{
    private const MAX_SPAN_MONTHS = 60;

    private readonly StatementAssigner $assigner;

    public function __construct(private readonly StatementRecalculator $recalculator)
    {
        $this->assigner = new StatementAssigner();
    }

    public function statementFor(CreditCard $card, DateTimeImmutable $date): CreditCardStatement
    {
        $statementDay = (int) $card->statement_day;
        $dueDay = (int) $card->due_day;
        $target = $this->assigner->cycleFor($statementDay, $dueDay, $date);

        $existing = CreditCardStatement::query()
            ->where('credit_card_id', $card->id)->orderBy('statement_date')->get();
        $haveMonths = [];
        foreach ($existing as $statement) {
            $haveMonths[$statement->statement_date->format('Y-m')] = true;
        }

        $low = $high = $this->monthIndex($target->statementDate);
        if ($existing->isNotEmpty()) {
            $low = min($low, $this->monthIndex(Day::of($existing->first()->statement_date)));
            $high = max($high, $this->monthIndex(Day::of($existing->last()->statement_date)));
        }
        if ($high - $low > self::MAX_SPAN_MONTHS) {
            throw ValidationException::withMessages(['txn_date' => ['วันที่ห่างจากรอบบิลที่มีอยู่เกิน 5 ปี']]);
        }

        for ($i = $low; $i <= $high; $i++) {
            $year = intdiv($i, 12);
            $month = $i % 12 + 1;
            if (isset($haveMonths[sprintf('%04d-%02d', $year, $month)])) {
                continue;
            }
            $cycle = $this->assigner->cycleForStatementMonth($statementDay, $dueDay, $year, $month);
            (new CreditCardStatement())->fill([
                'credit_card_id' => $card->id,
                'cycle_start' => $cycle->cycleStart->format('Y-m-d'),
                'statement_date' => $cycle->statementDate->format('Y-m-d'),
                'due_date' => $cycle->dueDate->format('Y-m-d'),
                'status' => 'unpaid',
            ])->save();
        }

        $this->recalculator->recalculateCard($card);

        $month = Day::of($target->statementDate);

        return CreditCardStatement::query()
            ->where('credit_card_id', $card->id)
            ->whereBetween('statement_date', [$month->format('Y-m-01'), $month->format('Y-m-t')])
            ->firstOrFail();
    }

    private function monthIndex(DateTimeImmutable $date): int
    {
        return ((int) $date->format('Y')) * 12 + ((int) $date->format('n') - 1);
    }
}
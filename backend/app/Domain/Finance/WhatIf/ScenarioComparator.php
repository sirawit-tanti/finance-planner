<?php

declare(strict_types=1);

namespace App\Domain\Finance\WhatIf;

use App\Domain\Finance\Forecast\ForecastResult;
use App\Domain\Finance\Forecast\MonthForecast;
use InvalidArgumentException;

/** Before / After. Both forecasts must cover exactly the same months. */
final class ScenarioComparator
{
    public function compare(ForecastResult $before, ForecastResult $after): ScenarioComparison
    {
        if (count($before->months) !== count($after->months)) {
            throw new InvalidArgumentException('The two forecasts cover a different number of months.');
        }

        $diffs = [];
        $newRisk = [];
        $resolved = [];

        foreach ($before->months as $i => $b) {
            $a = $after->months[$i];
            if ($a->key !== $b->key) {
                throw new InvalidArgumentException("Month mismatch: {$b->key} vs {$a->key}.");
            }

            $diffs[] = new MonthDiff(
                $b->key,
                $b->closingCash,
                $a->closingCash,
                $a->closingCash->minus($b->closingCash),
                $b->minBalance,
                $a->minBalance,
                $b->risk,
                $a->risk,
            );

            if (self::rank($a->risk) > self::rank($b->risk)) {
                $newRisk[] = $b->key;
            } elseif (self::rank($a->risk) < self::rank($b->risk)) {
                $resolved[] = $b->key;
            }
        }

        $last = count($before->months) - 1;

        return new ScenarioComparison(
            $diffs,
            $after->finalClosing()->minus($before->finalClosing()),
            $before->lowestBalance(),
            $after->lowestBalance(),
            $after->totalInstallments()->minus($before->totalInstallments()),
            $after->totalInterest()->minus($before->totalInterest()),
            $after->months[$last]->reservedCumulative->minus($before->months[$last]->reservedCumulative),
            $newRisk,
            $resolved,
        );
    }

    private static function rank(string $risk): int
    {
        return match ($risk) {
            MonthForecast::RISK_OK => 0,
            MonthForecast::RISK_LOW => 1,
            MonthForecast::RISK_NEGATIVE => 2,
            default => throw new InvalidArgumentException("Unknown risk level: {$risk}"),
        };
    }
}
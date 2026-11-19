<?php

declare(strict_types=1);

namespace App\Domain\Finance\Forecast;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class ForecastResult
{
    /** @param list<MonthForecast> $months */
    public function __construct(public readonly array $months)
    {
        if ($months === []) {
            throw new InvalidArgumentException('A forecast needs at least one month.');
        }
    }

    public function month(string $key): ?MonthForecast
    {
        foreach ($this->months as $month) {
            if ($month->key === $key) {
                return $month;
            }
        }

        return null;
    }

    /** Closing cash after the first $n months (1-based): used for the 1 / 3 / 6 / 12 month views. */
    public function closingAfter(int $n): BigDecimal
    {
        if ($n < 1 || $n > count($this->months)) {
            throw new InvalidArgumentException("Month {$n} is outside the forecast.");
        }

        return $this->months[$n - 1]->closingCash;
    }

    public function finalClosing(): BigDecimal
    {
        return $this->months[count($this->months) - 1]->closingCash;
    }

    public function lowestBalance(): BigDecimal
    {
        $lowest = $this->months[0]->minBalance;
        foreach ($this->months as $month) {
            if ($month->minBalance->isLessThan($lowest)) {
                $lowest = $month->minBalance;
            }
        }

        return $lowest;
    }

    /** @return list<MonthForecast> months whose risk is not "ok" */
    public function riskMonths(): array
    {
        return array_values(array_filter(
            $this->months,
            fn (MonthForecast $m) => $m->risk !== MonthForecast::RISK_OK
        ));
    }

    public function totalInstallments(): BigDecimal
    {
        return $this->sum(fn (MonthForecast $m) => $m->installmentsTotal);
    }

    public function totalInterest(): BigDecimal
    {
        return $this->sum(fn (MonthForecast $m) => $m->installmentInterest);
    }

    /** @param callable(MonthForecast): BigDecimal $pick */
    private function sum(callable $pick): BigDecimal
    {
        $total = BigDecimal::of('0.00');
        foreach ($this->months as $month) {
            $total = $total->plus($pick($month));
        }

        return $total;
    }
}
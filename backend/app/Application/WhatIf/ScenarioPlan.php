<?php

declare(strict_types=1);

namespace App\Application\WhatIf;

use App\Domain\Finance\Forecast\CardCharge;
use App\Domain\Finance\WhatIf\ScenarioChange;

final class ScenarioPlan
{
    /**
     * @param list<ScenarioChange>            $changes
     * @param array<string, list<CardCharge>> $extraCharges card id => hypothetical charges
     */
    public function __construct(
        public readonly array $changes,
        public readonly array $extraCharges,
    ) {
    }
}
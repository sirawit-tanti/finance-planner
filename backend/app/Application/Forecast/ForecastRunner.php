<?php

declare(strict_types=1);

namespace App\Application\Forecast;

use App\Domain\Finance\Forecast\CardCharge;
use App\Domain\Finance\Forecast\CardProjector;
use App\Domain\Finance\Forecast\ForecastEngine;
use App\Domain\Finance\Forecast\ForecastResult;
use App\Domain\Finance\WhatIf\ScenarioApplier;
use App\Domain\Finance\WhatIf\ScenarioChange;

/** Context (+ optional what-if changes) => forecast. Does not touch the database. */
final class ForecastRunner
{
    /**
     * @param list<ScenarioChange>             $changes
     * @param array<string, list<CardCharge>>  $extraCharges card id => hypothetical extra charges
     */
    public function run(ForecastContext $ctx, array $changes = [], array $extraCharges = []): ForecastResult
    {
        $events = $ctx->baseEvents;
        $projector = new CardProjector();

        foreach ($ctx->cards as $card) {
            array_push($events, ...$projector->project(
                $card->id, $card->name, $card->statementDay, $card->dueDay, $card->known,
                array_merge($card->charges, $extraCharges[$card->id] ?? []),
                $ctx->from, $ctx->to,
            ));
        }

        $events = (new ScenarioApplier())->apply($events, $changes);

        return (new ForecastEngine())->run(
            $ctx->from, $ctx->months, $ctx->calendar, $ctx->openingCash, $events,
            $ctx->emergencyFund, $ctx->reservedAtStart,
        );
    }
}
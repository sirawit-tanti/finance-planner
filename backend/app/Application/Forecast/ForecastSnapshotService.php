<?php

declare(strict_types=1);

namespace App\Application\Forecast;

use App\Models\ForecastSnapshot;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Returns the stored forecast when nothing changed since it was calculated
 * (same data_version and same start date), otherwise calculates and stores a new one.
 */
final class ForecastSnapshotService
{
    public function __construct(
        private readonly ForecastContextLoader $loader,
        private readonly ForecastRunner $runner,
        private readonly ForecastSerializer $serializer,
    ) {
    }

    /** @return array{0: array<string, mixed>, 1: bool} [payload, wasCached] */
    public function get(DateTimeImmutable $from, ?int $months, bool $refresh = false): array
    {
        $months ??= $this->loader->defaultMonths();
        $fromKey = $from->format('Y-m-d');
        $currentVersion = (int) User::query()->whereKey(Auth::id())->value('data_version');

        if (! $refresh) {
            $snapshot = ForecastSnapshot::query()->where('horizon_months', $months)->first();
            if ($snapshot !== null
                && $snapshot->data_version === $currentVersion
                && ($snapshot->payload['from'] ?? null) === $fromKey) {
                return [$snapshot->payload, true];
            }
        }

        $context = $this->loader->load($from, $months);
        $payload = $this->serializer->forecast($context, $this->runner->run($context));

        ForecastSnapshot::query()->updateOrCreate(
            ['horizon_months' => $months],
            ['data_version' => $context->dataVersion, 'generated_at' => now(), 'payload' => $payload],
        );

        return [$payload, false];
    }
}
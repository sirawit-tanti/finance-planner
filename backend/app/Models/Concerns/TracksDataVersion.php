<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks the user's forecast as stale whenever their financial data is written or deleted.
 *
 * @phpstan-require-extends Model
 * @mixin Model
 */
trait TracksDataVersion
{
    public static function bootTracksDataVersion(): void
    {
        $bump = function (Model $model): void {
            $userId = $model->getAttribute('user_id');

            if (! empty($userId)) {
                User::query()->whereKey($userId)->increment('data_version');
            }
        };

        static::saved($bump);
        static::deleted($bump);
    }
}
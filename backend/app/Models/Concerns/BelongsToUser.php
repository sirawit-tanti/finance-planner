<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\UserScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * UUID primary key (a client may supply its own while offline; HasUuids only
 * generates one when empty) + automatic per-user isolation.
 * user_id is deliberately NOT mass-assignable: it comes from the logged-in user.
 *
 * @phpstan-require-extends Model
 * @mixin Model
 */
trait BelongsToUser
{
    use HasUuids;

    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope(new UserScope());

        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('user_id')) && Auth::id() !== null) {
                $model->setAttribute('user_id', Auth::id());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
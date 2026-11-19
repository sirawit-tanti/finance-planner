<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Everything a record needs to take part in offline sync:
 * owner isolation, soft delete (tombstone), optimistic-lock version, sync cursor.
 *
 * @phpstan-require-extends Model
 * @mixin Model
 */
trait Syncable
{
    use BelongsToUser, SoftDeletes, TracksDataVersion;

    public function initializeSyncable(): void
    {
        $this->mergeCasts(['version' => 'integer', 'server_seq' => 'integer']);
    }

    public static function bootSyncable(): void
    {
        static::creating(function (Model $model): void {
            $model->setAttribute('version', 1);
            $model->setAttribute('server_seq', static::nextServerSeq());
        });

        static::updating(function (Model $model): void {
            $model->setAttribute('version', ((int) $model->getOriginal('version')) + 1);
            $model->setAttribute('server_seq', static::nextServerSeq());
        });

        // Soft delete issues its own UPDATE without firing "updating", so bump here.
        static::deleted(function (Model $model): void {
            // A hard delete leaves no row to mark. SoftDeletes sets deleted_at on a soft delete.
            if ($model->getAttribute('deleted_at') === null) {
                return;
            }

            $version = ((int) $model->getAttribute('version')) + 1;
            $seq = static::nextServerSeq();

            $model->newQueryWithoutScopes()
                ->whereKey($model->getKey())
                ->update(['version' => $version, 'server_seq' => $seq]);

            $model->setAttribute('version', $version);
            $model->setAttribute('server_seq', $seq);
            $model->syncOriginalAttributes(['version', 'server_seq']);
        });
    }

    public static function nextServerSeq(): int
    {
        return (int) DB::selectOne("select nextval('sync_server_seq') as seq")->seq;
    }
}
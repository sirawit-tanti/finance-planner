<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Validation helpers shared by the form requests. */
final class Rules
{
    public const MONEY = 'regex:/^\d{1,12}(\.\d{1,2})?$/';

    /** The id must exist in $table, belong to the logged-in user and not be deleted. */
    public static function owned(string $table): Exists
    {
        return Rule::exists($table, 'id')->where(
            fn ($query) => $query->where('user_id', Auth::id())->whereNull('deleted_at')
        );
    }

    /** Real records cannot be dated in the future (the forecast only counts cash up to its start date). */
    public static function notFuture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value > Day::today()->format('Y-m-d')) {
                $fail('วันที่ต้องไม่เป็นอนาคต');
            }
        };
    }
}
<?php

declare(strict_types=1);

namespace App\Application\Installments;

use Illuminate\Validation\Rule;

/** Validation rules shared by the installment endpoints and the what-if "add_installment" change. */
final class InstallmentRules
{
    public const MONEY = 'regex:/^\d{1,12}(\.\d{1,2})?$/';
    public const RATE = 'regex:/^\d{1,3}(\.\d{1,6})?$/';

    /** @return array<string, array<int, mixed>> */
    public static function for(string $userId): array
    {
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(
            fn ($query) => $query->where('user_id', $userId)->whereNull('deleted_at')
        );

        return [
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'interest_method' => ['required', Rule::in(['zero', 'flat', 'reducing', 'effective'])],
            'annual_rate_percent' => ['required_unless:interest_method,zero', 'nullable', self::RATE],
            'price' => ['required', self::MONEY, 'gt:0'],
            'down_payment' => ['nullable', self::MONEY],
            'term_count' => ['required', 'integer', 'between:1,360'],
            'frequency' => ['required', Rule::in(['monthly', 'biweekly', 'weekly', 'yearly'])],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'first_due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'fee_amount' => ['nullable', self::MONEY],
            'fee_mode' => ['nullable', Rule::in(['none', 'upfront', 'spread'])],
            'funding_type' => ['required', Rule::in(['credit_card', 'account'])],
            'credit_card_id' => ['required_if:funding_type,credit_card', 'nullable', 'uuid', $owned('credit_cards')],
            'account_id' => ['required_if:funding_type,account', 'nullable', 'uuid', $owned('accounts')],
        ];
    }
}
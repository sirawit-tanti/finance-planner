<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecurringExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $monthly = fn () => in_array($this->input('frequency'), ['monthly', 'yearly'], true);
        $weekly = fn () => in_array($this->input('frequency'), ['weekly', 'biweekly'], true);
        $card = fn () => $this->input('payment_method') === 'credit_card';

        return [
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'uuid', Rules::owned('categories')],
            'amount' => ['required', Rules::MONEY],
            'is_variable' => ['sometimes', 'boolean'],
            'frequency' => ['required', Rule::in(['monthly', 'weekly', 'biweekly', 'yearly'])],
            'due_day' => ['nullable', 'integer', 'between:1,31', Rule::requiredIf($monthly)],
            'weekday' => ['nullable', 'integer', 'between:1,7', Rule::requiredIf($weekly)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'is_active' => ['sometimes', 'boolean'],
            'payment_method' => ['required', Rule::in(['cash', 'transfer', 'credit_card', 'auto_debit'])],
            'account_id' => ['nullable', 'uuid', Rules::owned('accounts'), Rule::requiredIf(fn () => ! $card())],
            'credit_card_id' => ['nullable', 'uuid', Rules::owned('credit_cards'), Rule::requiredIf($card)],
            'shift_rule' => ['sometimes', Rule::in(['none', 'previous_weekday', 'next_weekday'])],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncomeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $monthly = fn () => in_array($this->input('frequency'), ['monthly', 'yearly'], true);
        $weekly = fn () => in_array($this->input('frequency'), ['weekly', 'biweekly'], true);

        return [
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', Rules::MONEY, 'gt:0'],
            'frequency' => ['required', Rule::in(['monthly', 'weekly', 'biweekly', 'yearly'])],
            'pay_day' => ['nullable', 'integer', 'between:1,31', Rule::requiredIf($monthly)],
            'weekday' => ['nullable', 'integer', 'between:1,7', Rule::requiredIf($weekly)],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'shift_rule' => ['sometimes', Rule::in(['none', 'previous_weekday', 'next_weekday'])],
            'account_id' => ['required', 'uuid', Rules::owned('accounts')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
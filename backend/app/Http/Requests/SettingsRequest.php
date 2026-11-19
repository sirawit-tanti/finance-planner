<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency' => ['sometimes', Rule::in(['THB'])],
            'month_start_day' => ['sometimes', 'integer', 'between:1,28'],
            'default_salary_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'emergency_fund_amount' => ['sometimes', Rules::MONEY],
            'forecast_default_months' => ['sometimes', 'integer', 'between:1,36'],
            'locale_year' => ['sometimes', Rule::in(['BE', 'CE'])],
        ];
    }
}
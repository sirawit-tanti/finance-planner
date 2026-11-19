<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;

class BudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'uuid', Rules::owned('categories')],
            // NULL = default budget for every month; otherwise the FIRST day of one month.
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])-01$/'],
            'amount' => ['required', Rules::MONEY],
            'warn_percent' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
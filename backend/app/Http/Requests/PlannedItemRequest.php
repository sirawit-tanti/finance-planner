<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlannedItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['income', 'expense'])],
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', Rules::MONEY, 'gt:0'],
            'planned_date' => ['required', 'date_format:Y-m-d'],
            'category_id' => ['nullable', 'uuid', Rules::owned('categories')],
            'account_id' => ['required', 'uuid', Rules::owned('accounts')],
            'status' => ['sometimes', Rule::in(['planned', 'done', 'cancelled'])],
        ];
    }
}
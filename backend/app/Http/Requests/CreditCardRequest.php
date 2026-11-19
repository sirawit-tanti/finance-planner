<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreditCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bank' => ['required', 'string', 'max:255'],
            'credit_limit' => ['sometimes', Rules::MONEY],
            'statement_day' => ['required', 'integer', 'between:1,31'],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'pay_account_id' => ['nullable', 'uuid', Rules::owned('accounts')],
            'opening_balance' => ['sometimes', Rules::MONEY],
            'status' => ['sometimes', Rule::in(['active', 'closed'])],
        ];
    }
}
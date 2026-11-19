<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;

/** Used by the quote (GET) and the payoff (POST). The payment date is required only when paying. */
class PayoffQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interest_discount' => ['nullable', Rules::MONEY],
            'early_fee' => ['nullable', Rules::MONEY],
            'payment_date' => [$this->isMethod('post') ? 'required' : 'nullable', 'date_format:Y-m-d', Rules::notFuture()],
        ];
    }
}
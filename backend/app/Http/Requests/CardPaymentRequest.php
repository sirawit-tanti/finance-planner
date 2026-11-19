<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;

class CardPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid', Rules::owned('accounts')],
            'payment_date' => ['required', 'date_format:Y-m-d', Rules::notFuture()],
            'amount' => ['required', Rules::MONEY, 'gt:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
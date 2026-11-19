<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCard = in_array($this->input('type'), ['card_purchase', 'card_refund'], true);

        return [
            'type' => ['required', Rule::in(['income', 'expense', 'card_purchase', 'card_refund'])],
            'txn_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', Rules::notFuture()],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'uuid', Rules::owned('categories')],
            'amount' => ['required', Rules::MONEY, 'gt:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'payment_method' => $isCard
                ? ['nullable']
                : ['required', Rule::in(['cash', 'transfer', 'debit'])],
            // Card transactions touch a card only; cash transactions touch an account only.
            'account_id' => $isCard ? ['prohibited'] : ['required', 'uuid', Rules::owned('accounts')],
            'credit_card_id' => $isCard ? ['required', 'uuid', Rules::owned('credit_cards')] : ['prohibited'],
        ];
    }
}
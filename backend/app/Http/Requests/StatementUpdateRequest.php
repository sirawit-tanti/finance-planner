<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;

/** Only the figures the user copies from the bank's bill. Everything else is calculated. */
class StatementUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'interest_charged' => ['sometimes', Rules::MONEY],
            'fees_charged' => ['sometimes', Rules::MONEY],
            'min_payment' => ['sometimes', Rules::MONEY],
        ];
    }
}
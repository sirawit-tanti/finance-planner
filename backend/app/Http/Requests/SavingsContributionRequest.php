<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavingsContributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contribution_date' => [
                'required', 'date_format:Y-m-d',
                ...($this->input('status') === 'actual' ? [Rules::notFuture()] : []),
            ],
            'amount' => ['required', Rules::MONEY, 'gt:0'],
            'status' => ['sometimes', Rule::in(['planned', 'actual'])],
        ];
    }
}
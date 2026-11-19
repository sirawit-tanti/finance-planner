<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;

class InstallmentPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'schedule_item_id' => ['required', 'uuid'],
            'payment_date' => ['required', 'date_format:Y-m-d', Rules::notFuture()],
        ];
    }
}
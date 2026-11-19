<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForecastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'months' => ['nullable', 'integer', 'between:1,36'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'refresh' => ['nullable', 'boolean'],
        ];
    }
}
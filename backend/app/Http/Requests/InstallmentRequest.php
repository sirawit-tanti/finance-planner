<?php

namespace App\Http\Requests;

use App\Application\Installments\InstallmentRules;
use Illuminate\Foundation\Http\FormRequest;

/** Used by both "simulate" and "store". from/months only matter for simulate. */
class InstallmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return InstallmentRules::for((string) $this->user()->id) + [
            'months' => ['nullable', 'integer', 'between:1,36'],
            'from' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
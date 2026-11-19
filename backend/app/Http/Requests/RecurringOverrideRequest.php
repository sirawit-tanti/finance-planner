<?php

namespace App\Http\Requests;

use App\Support\Rules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Changes ONE occurrence: another amount (e.g. the real electricity bill), another date, or skip it. */
class RecurringOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $table = $this->input('source_type') === 'income' ? 'income_sources' : 'recurring_expenses';

        return [
            'source_type' => ['required', Rule::in(['income', 'expense'])],
            'source_id' => ['required', 'uuid', Rules::owned($table)],
            'occurrence_date' => ['required', 'date_format:Y-m-d'],
            'override_amount' => ['nullable', Rules::MONEY],
            'override_date' => ['nullable', 'date_format:Y-m-d'],
            'is_skipped' => ['sometimes', 'boolean'],
        ];
    }
}
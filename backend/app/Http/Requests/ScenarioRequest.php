<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A saved what-if: the "changes" are validated again when the scenario is run. */
class ScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'changes' => ['required', 'array', 'min:1', 'max:20'],
            'changes.*' => ['array'],
        ];
    }
}
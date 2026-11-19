<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100',
                Rule::unique('categories', 'name')
                    ->where(fn ($q) => $q->where('user_id', Auth::id())->where('kind', $this->input('kind'))->whereNull('deleted_at'))
                    ->ignore($this->route('category')),
            ],
            'kind' => ['required', Rule::in(['expense', 'income'])],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
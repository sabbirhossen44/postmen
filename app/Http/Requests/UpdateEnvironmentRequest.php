<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'variables' => ['nullable', 'array'],
            'variables.*.key' => ['required_with:variables', 'string'],
            'variables.*.value' => ['nullable'],
            'variables.*.initial_value' => ['nullable'],
            'variables.*.current_value' => ['nullable'],
            'variables.*.type' => ['nullable', 'string', 'in:text,secret'],
            'variables.*.enabled' => ['nullable', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEnvironmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'variables' => ['nullable', 'array'],
            'variables.*.key' => ['required_with:variables', 'string'],
            'variables.*.value' => ['nullable'],
            'variables.*.enabled' => ['nullable', 'boolean'],
        ];
    }
}

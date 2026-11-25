<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCollectionRequest extends FormRequest
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
            'description' => ['nullable', 'string'],
            'media_id' => ['nullable', 'integer', 'exists:media,id'],
            'variables' => ['nullable', 'array'],
            'variables.*.key' => ['required_with:variables', 'string'],
            'variables.*.value' => ['nullable'],
            'variables.*.initial_value' => ['nullable'],
            'variables.*.current_value' => ['nullable'],
            'variables.*.type' => ['nullable', 'string', 'in:text,secret'],
            'variables.*.enabled' => ['nullable', 'boolean'],
            'auth' => ['nullable', 'array'],
        ];
    }
}

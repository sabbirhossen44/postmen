<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGlobalVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'initial_value' => ['nullable', 'string'],
            'current_value' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:text,secret'],
        ];
    }
}

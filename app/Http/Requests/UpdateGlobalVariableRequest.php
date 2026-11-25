<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGlobalVariableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'key' => ['sometimes', 'required', 'string', 'max:255'],
            'initial_value' => ['nullable', 'string'],
            'current_value' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:text,secret'],
        ];
    }
}

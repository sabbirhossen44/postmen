<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreExampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', 'integer', 'min:0', 'max:599'],
            'status_text' => ['nullable', 'string', 'max:255'],
            'headers' => ['nullable', 'array'],
            'body' => ['nullable'],
            'content_type' => ['nullable', 'string', 'max:255'],
            'time_ms' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

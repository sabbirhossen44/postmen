<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'media_id' => ['nullable', 'integer', 'exists:media,id'],
            'variables' => ['nullable', 'array'],
            'auth' => ['nullable', 'array'],
        ];
    }
}

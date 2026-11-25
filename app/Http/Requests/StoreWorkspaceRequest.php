<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('workspaces')->where('user_id', $this->user()->id)],
            'description' => ['nullable', 'string'],
            'media_id' => ['nullable', 'integer', 'exists:media,id'],
        ];
    }
}

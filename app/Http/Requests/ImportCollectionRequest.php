<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'file' => ['nullable', 'file', 'mimes:json', 'max:10240'],
            'json' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->hasFile('file') && ! $this->filled('json')) {
                $validator->errors()->add('file', 'Provide either a collection JSON file or a json payload.');
            }
        });
    }
}

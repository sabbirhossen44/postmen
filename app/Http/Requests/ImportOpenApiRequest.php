<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportOpenApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'file' => ['nullable', 'file', 'mimes:json,yaml,yml,txt', 'max:10240'],
            'json' => ['nullable', 'array'],
            'content' => ['nullable', 'string', 'max:1000000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->hasFile('file') && ! $this->filled('json') && ! $this->filled('content')) {
                $validator->errors()->add('file', 'Provide an OpenAPI file, a json payload, or raw content.');
            }
        });
    }
}

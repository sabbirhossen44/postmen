<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportCurlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', 'exists:workspaces,id'],
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'curl' => ['required', 'string', 'max:100000'],
        ];
    }
}

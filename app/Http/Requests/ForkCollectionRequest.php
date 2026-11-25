<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForkCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // Target workspace. Defaults to the source collection's own
            // workspace when omitted — the UI sends the selected one.
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ];
    }
}

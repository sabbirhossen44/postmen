<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // The collection comes from the URL (POST /collections/{id}/run).
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
            'environment_id' => ['nullable', 'integer', 'exists:environments,id'],
            'iterations' => ['nullable', 'integer', 'min:1', 'max:100'],
            // The Runner UI sends `delay`; `delay_ms` is accepted too.
            'delay_ms' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'delay' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'stop_on_error' => ['nullable', 'boolean'],
            // Data rows: one object per iteration, each row is local
            // variables for that iteration (highest precedence).
            'data' => ['nullable', 'array'],
            'data.*' => ['nullable', 'array'],
        ];
    }
}

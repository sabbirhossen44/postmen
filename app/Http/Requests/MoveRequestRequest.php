<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'method' => ['sometimes', 'required', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'url' => ['sometimes', 'required', 'string'],
            'params' => ['nullable', 'array'],
            'headers' => ['nullable', 'array'],
            'auth_type' => ['nullable', 'string', 'in:none,bearer,basic,api_key'],
            'auth' => ['nullable', 'array'],
            'body_type' => ['nullable', 'string', 'in:none,form-data,urlencoded,raw,binary'],
            'body' => ['nullable'],
            'body_file_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}

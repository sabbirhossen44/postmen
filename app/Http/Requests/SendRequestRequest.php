<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'url' => ['required', 'string'],
            'params' => ['nullable', 'array'],
            'headers' => ['nullable', 'array'],
            'auth_type' => ['nullable', 'string', 'in:none,bearer,basic,api_key'],
            'auth' => ['nullable', 'array'],
            'body_type' => ['nullable', 'string', 'in:none,form-data,urlencoded,raw,binary,graphql'],
            'body' => ['nullable'],
            'body_file_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'scripts' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'environment_id' => ['nullable', 'integer', 'exists:environments,id'],
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'request_id' => ['nullable', 'integer', 'exists:requests,id'],
            'save_history' => ['nullable', 'boolean'],
        ];
    }
}

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
            'auth_type' => ['nullable', 'string', 'in:none,bearer,basic,api_key,inherit,digest,oauth2,aws'],
            'auth' => ['nullable', 'array'],
            'auth.access_token' => ['nullable', 'string'],
            'auth.token_type' => ['nullable', 'string'],
            'auth.token_url' => ['nullable', 'string'],
            'auth.client_id' => ['nullable', 'string'],
            'auth.username' => ['nullable', 'string'],
            'auth.access_key' => ['nullable', 'string'],
            'auth.region' => ['nullable', 'string'],
            'auth.service' => ['nullable', 'string'],
            'body_type' => ['nullable', 'string', 'in:none,form-data,urlencoded,raw,binary,graphql'],
            'body' => ['nullable'],
            'body_file_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'scripts' => ['nullable', 'array'],
            'scripts.pre_request' => ['nullable', 'string'],
            'scripts.tests' => ['nullable', 'string'],
            // Flat aliases — override the matching `scripts` entry for
            // this send only; the saved request is not changed.
            'pre_request_script' => ['nullable', 'string'],
            'test_script' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
            'settings.follow_redirects' => ['nullable', 'boolean'],
            'settings.timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'settings.verify_ssl' => ['nullable', 'boolean'],
            'settings.max_redirects' => ['nullable', 'integer', 'min:0', 'max:20'],
            'settings.send_cookies' => ['nullable', 'boolean'],
            'variables' => ['nullable', 'array'],
            'environment_id' => ['nullable', 'integer', 'exists:environments,id'],
            'collection_id' => ['nullable', 'integer', 'exists:collections,id'],
            'request_id' => ['nullable', 'integer', 'exists:requests,id'],
            'save_history' => ['nullable', 'boolean'],
        ];
    }
}

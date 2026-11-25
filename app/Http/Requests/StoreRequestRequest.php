<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        // Nested route POST /collections/{collection}/requests carries the
        // collection in the URL, so fill collection_id from the route.
        $collection = $this->route('collection');

        if ($collection) {
            $this->merge([
                'collection_id' => $collection instanceof \App\Models\Collection
                    ? $collection->id
                    : $collection,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'collection_id' => ['required', 'integer', 'exists:collections,id'],
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
            'name' => ['required', 'string', 'max:255'],
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
            // Flat aliases for the same scripts — applied after `scripts`
            // on the model, so they win when both are sent.
            'pre_request_script' => ['nullable', 'string'],
            'test_script' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
            'settings.follow_redirects' => ['nullable', 'boolean'],
            'settings.timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'settings.verify_ssl' => ['nullable', 'boolean'],
            'settings.max_redirects' => ['nullable', 'integer', 'min:0', 'max:20'],
            'settings.send_cookies' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}

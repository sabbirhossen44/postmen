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

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    protected function prepareForValidation(): void
    {
        // Nested route POST /collections/{collection}/folders carries the
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
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}

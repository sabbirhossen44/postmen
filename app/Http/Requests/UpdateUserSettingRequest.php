<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'theme' => ['nullable', 'string', 'in:dark,light'],
            'layout_density' => ['nullable', 'string', 'in:comfortable,compact'],
            'default_body_type' => ['nullable', 'string', 'in:none,form-data,urlencoded,raw,binary,graphql'],
            'default_timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'sidebar_width' => ['nullable', 'integer', 'min:200', 'max:560'],
        ];
    }
}

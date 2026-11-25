<?php

namespace App\Http\Requests;

use App\Services\CodeGenerator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'language' => ['required', 'string', Rule::in(CodeGenerator::LANGUAGES)],
            'method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS'],
            'url' => ['required', 'string'],
            'params' => ['nullable', 'array'],
            'headers' => ['nullable', 'array'],
            'auth_type' => ['nullable', 'string', 'in:none,bearer,basic,api_key,inherit,digest,oauth2,aws'],
            'auth' => ['nullable', 'array'],
            'body_type' => ['nullable', 'string', 'in:none,form-data,urlencoded,raw,binary,graphql'],
            'body' => ['nullable'],
        ];
    }
}

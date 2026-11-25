<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeOAuthTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'grant_type' => ['required', 'string', 'in:authorization_code,client_credentials,refresh_token'],
            'code' => ['required_if:grant_type,authorization_code', 'nullable', 'string'],
            'redirect_uri' => ['nullable', 'string'],
            'refresh_token' => ['required_if:grant_type,refresh_token', 'nullable', 'string'],
            'scope' => ['nullable', 'string'],
        ];
    }
}

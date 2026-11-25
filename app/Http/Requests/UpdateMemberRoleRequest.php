<?php

namespace App\Http\Requests;

use App\Models\WorkspaceMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(WorkspaceMember::GRANTABLE_ROLES)],
        ];
    }
}

<?php

namespace App\Http\Requests\Rbac;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assign', Role::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles' => ['present', 'array', 'min:1'],
            'roles.*' => ['string', Rule::exists('roles', 'slug')],
        ];
    }
}

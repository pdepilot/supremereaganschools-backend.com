<?php

namespace App\Http\Requests\Admins;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ResetAdminPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $actor */
        $actor = $this->user();
        /** @var User $admin */
        $admin = $this->route('admin');

        return $actor !== null && $actor->can('resetPassword', $admin);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}

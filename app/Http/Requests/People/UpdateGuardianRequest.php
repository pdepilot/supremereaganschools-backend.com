<?php

namespace App\Http\Requests\People;

use App\Models\GuardianProfile;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('guardian_profile')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var GuardianProfile $guardian */
        $guardian = $this->route('guardian_profile');

        return [
            'full_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['sometimes', 'string', 'min:8'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}

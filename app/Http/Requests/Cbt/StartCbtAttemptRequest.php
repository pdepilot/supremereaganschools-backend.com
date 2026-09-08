<?php

namespace App\Http\Requests\Cbt;

use App\Enums\CbtAttemptMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartCbtAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'uuid' => ['sometimes', 'nullable', 'uuid'],
            'mode' => ['sometimes', 'nullable', Rule::enum(CbtAttemptMode::class)],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}

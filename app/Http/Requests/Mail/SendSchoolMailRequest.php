<?php

namespace App\Http\Requests\Mail;

use App\Enums\EmailAudience;
use App\Models\EmailTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendSchoolMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmailTemplate::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $ids = $this->input('user_ids');

        if ($ids === null || $ids === '') {
            return;
        }

        if (! is_array($ids)) {
            $this->merge(['user_ids' => [(int) $ids]]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $audience = $this->input('audience');
        $picked = in_array($audience, [EmailAudience::User->value, EmailAudience::Users->value], true);

        $userIds = [
            Rule::requiredIf($picked),
            'nullable',
            'array',
        ];

        if ($audience === EmailAudience::User->value) {
            $userIds[] = 'size:1';
        }

        if ($audience === EmailAudience::Users->value) {
            $userIds[] = 'min:1';
        }

        return [
            'template_id' => ['nullable', 'integer', 'exists:email_templates,id'],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string'],
            'audience' => ['required', Rule::enum(EmailAudience::class)],
            'user_ids' => $userIds,
            'user_ids.*' => ['integer', 'exists:users,id'],
            'recipients' => [
                Rule::requiredIf($audience === EmailAudience::Custom->value),
                'nullable',
                'string',
            ],
        ];
    }
}

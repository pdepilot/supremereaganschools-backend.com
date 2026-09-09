<?php

namespace App\Http\Requests\Cbt;

use App\Enums\CbtSubmissionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitCbtAttemptRequest extends FormRequest
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
            'client_submitted_at' => ['sometimes', 'nullable', 'date'],
            'reason' => ['sometimes', 'string', Rule::in([
                CbtSubmissionReason::StudentManual->value,
                CbtSubmissionReason::TimerExpired->value,
                CbtSubmissionReason::AutoSubmittedExamExit->value,
                CbtSubmissionReason::System->value,
            ])],
            'integrity_event_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'trigger' => ['sometimes', 'nullable', 'string', 'in:tab_hidden,window_blur,fullscreen_exit'],
        ];
    }
}

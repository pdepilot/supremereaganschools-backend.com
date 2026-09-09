<?php

namespace App\Http\Requests\Cbt;

use App\Services\Cbt\CbtOfflineSyncService;
use Illuminate\Foundation\Http\FormRequest;

class SyncCbtOfflineAnswersRequest extends FormRequest
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
            'protocol' => ['sometimes', 'string', 'in:'.CbtOfflineSyncService::PROTOCOL],
            'batch_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'events' => ['required', 'array', 'min:1', 'max:'.CbtOfflineSyncService::MAX_EVENTS_PER_BATCH],
            'events.*.event_id' => ['required', 'string', 'max:64'],
            'events.*.type' => ['sometimes', 'string', 'in:answer'],
            'events.*.attempt_uuid' => ['sometimes', 'nullable', 'uuid'],
            'events.*.exam_question_id' => ['required', 'integer', 'min:1'],
            'events.*.selected_exam_option_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'events.*.selected_option_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'events.*.client_answered_at' => ['sometimes', 'nullable', 'date'],
            'events.*.local_sequence' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

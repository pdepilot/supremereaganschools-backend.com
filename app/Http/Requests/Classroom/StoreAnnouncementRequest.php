<?php

namespace App\Http\Requests\Classroom;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Announcement::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'audience' => $this->normalizeAudience((string) $this->input('audience')),
            'category' => $this->normalizeCategory($this->input('category')),
            'status' => $this->input('status', AnnouncementStatus::Published->value),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'category' => ['nullable', Rule::enum(AnnouncementCategory::class)],
            'audience' => ['required', Rule::enum(AnnouncementAudience::class)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['required', Rule::enum(AnnouncementStatus::class)],
        ];
    }

    private function normalizeAudience(string $value): string
    {
        $map = [
            'whole school' => AnnouncementAudience::WholeSchool->value,
            'parents' => AnnouncementAudience::Parents->value,
            'staff' => AnnouncementAudience::Staff->value,
            'all staff' => AnnouncementAudience::Staff->value,
            'secondary only' => AnnouncementAudience::Secondary->value,
            'teaching staff' => AnnouncementAudience::TeachingStaff->value,
            'non-teaching staff' => AnnouncementAudience::NonTeachingStaff->value,
            'specific department' => AnnouncementAudience::Department->value,
            'students' => AnnouncementAudience::Students->value,
        ];

        $key = strtolower(trim($value));

        return $map[$key] ?? $value;
    }

    private function normalizeCategory(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $map = [
            'academic' => AnnouncementCategory::Academic->value,
            'event' => AnnouncementCategory::Event->value,
            'general' => AnnouncementCategory::General->value,
            'urgent' => AnnouncementCategory::Urgent->value,
        ];

        $key = strtolower(trim((string) $value));

        return $map[$key] ?? (string) $value;
    }
}

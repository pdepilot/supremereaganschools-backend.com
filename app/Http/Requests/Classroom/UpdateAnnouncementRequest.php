<?php

namespace App\Http\Requests\Classroom;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $announcement = $this->route('announcement');

        return $announcement instanceof Announcement
            && ($this->user()?->can('update', $announcement) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'category' => ['nullable', Rule::enum(AnnouncementCategory::class)],
            'audience' => ['sometimes', Rule::enum(AnnouncementAudience::class)],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'status' => ['sometimes', Rule::enum(AnnouncementStatus::class)],
        ];
    }
}

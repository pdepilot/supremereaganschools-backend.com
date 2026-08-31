<?php

namespace App\Http\Requests\News;

use App\Enums\ContentAudience;
use App\Enums\ContentIntent;
use App\Enums\CtaDestination;
use App\Enums\CtaStrength;
use App\Enums\EducationalLevel;
use App\Enums\PostContentType;
use Illuminate\Validation\Rule;

class EditorialPostRules
{
    /**
     * @return array<string, mixed>
     */
    public static function extra(): array
    {
        return [
            'content_type' => ['nullable', Rule::enum(PostContentType::class)],
            'cta_type' => ['nullable', 'string', Rule::in(array_merge(['auto'], array_column(CtaDestination::cases(), 'value')))],
            'cta_strength' => ['nullable', Rule::enum(CtaStrength::class)],
            'pillar_topic' => ['nullable', 'string', 'max:120'],
            'supporting_topic' => ['nullable', 'string', 'max:120'],
            'audience' => ['nullable', Rule::enum(ContentAudience::class)],
            'educational_level' => ['nullable', Rule::enum(EducationalLevel::class)],
            'intent' => ['nullable', Rule::enum(ContentIntent::class)],
            'last_reviewed_at' => ['nullable', 'date'],
            'review_due_at' => ['nullable', 'date'],
            'is_parent_resource' => ['nullable', 'boolean'],
            'child_directed' => ['nullable', 'boolean'],
            'resource_file' => ['nullable', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }
}

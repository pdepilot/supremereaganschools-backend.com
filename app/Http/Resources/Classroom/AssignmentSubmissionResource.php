<?php

namespace App\Http\Resources\Classroom;

use App\Models\AssignmentSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssignmentSubmission
 */
class AssignmentSubmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['document', 'assignment']);

        return [
            'id' => $this->id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'late' => $this->isLate(),
            'notes' => $this->notes,
            'original_name' => $this->document?->original_name,
            'document_id' => $this->document_id,
            'download_url' => $this->document_id
                ? '/api/v1/documents/'.$this->document_id.'/download'
                : null,
        ];
    }
}

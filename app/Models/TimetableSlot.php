<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'class_section_offering_id',
    'term_id',
    'day_of_week',
    'starts_at',
    'ends_at',
    'subject_id',
    'staff_profile_id',
    'label',
])]
class TimetableSlot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
        ];
    }

    public function classSectionOffering(): BelongsTo
    {
        return $this->belongsTo(ClassSectionOffering::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class, 'staff_profile_id');
    }
}

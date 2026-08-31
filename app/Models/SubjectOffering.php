<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['class_section_offering_id', 'subject_id'])]
class SubjectOffering extends Model
{
    use HasFactory;

    public function classSectionOffering(): BelongsTo
    {
        return $this->belongsTo(ClassSectionOffering::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacherAssignments(): HasMany
    {
        return $this->hasMany(SubjectTeacherAssignment::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['department_id', 'name', 'code', 'description', 'is_active'])]
class Subject extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function subjectOfferings(): HasMany
    {
        return $this->hasMany(SubjectOffering::class);
    }

    public function classSectionOfferings(): BelongsToMany
    {
        return $this->belongsToMany(ClassSectionOffering::class, 'subject_offerings')->withTimestamps();
    }

    public function assessmentScores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    public function termResults(): HasMany
    {
        return $this->hasMany(TermResult::class);
    }
}

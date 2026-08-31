<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['academic_session_id', 'name', 'term_number', 'starts_on', 'ends_on', 'status'])]
class Term extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'term_number' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => SessionStatus::class,
        ];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function assessmentScores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class);
    }

    public function termResults(): HasMany
    {
        return $this->hasMany(TermResult::class);
    }

    public function termSummaries(): HasMany
    {
        return $this->hasMany(TermSummary::class);
    }
}

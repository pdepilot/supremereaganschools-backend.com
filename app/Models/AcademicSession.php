<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'starts_on', 'ends_on', 'term_count', 'status', 'created_by'])]
class AcademicSession extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'term_count' => 'integer',
            'status' => SessionStatus::class,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class)->orderBy('term_number');
    }

    public function classSectionOfferings(): HasMany
    {
        return $this->hasMany(ClassSectionOffering::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }

    public function admissionApplications(): HasMany
    {
        return $this->hasMany(AdmissionApplication::class);
    }
}

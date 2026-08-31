<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number',
    'student_profile_id',
    'enrollment_id',
    'academic_session_id',
    'term_id',
    'status',
    'total_kobo',
    'paid_kobo',
    'due_on',
])]
class Invoice extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'total_kobo' => 'integer',
            'paid_kobo' => 'integer',
            'due_on' => 'date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function remainingKobo(): int
    {
        return max(0, $this->total_kobo - $this->paid_kobo);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [InvoiceStatus::Unpaid, InvoiceStatus::Partial], true);
    }
}

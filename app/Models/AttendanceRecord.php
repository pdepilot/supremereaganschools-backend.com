<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'enrollment_id',
    'class_section_offering_id',
    'marked_on',
    'status',
    'remark',
    'marked_by',
])]
class AttendanceRecord extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'marked_on' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function classSectionOffering(): BelongsTo
    {
        return $this->belongsTo(ClassSectionOffering::class);
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class)->orderByDesc('id');
    }
}

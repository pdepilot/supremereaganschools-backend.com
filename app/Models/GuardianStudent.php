<?php

namespace App\Models;

use App\Enums\GuardianRelationship;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'guardian_profile_id',
    'student_profile_id',
    'relationship',
    'is_primary',
    'can_login',
])]
class GuardianStudent extends Model
{
    use HasFactory;

    protected $table = 'guardian_student';

    protected function casts(): array
    {
        return [
            'relationship' => GuardianRelationship::class,
            'is_primary' => 'boolean',
            'can_login' => 'boolean',
        ];
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(GuardianProfile::class, 'guardian_profile_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }
}

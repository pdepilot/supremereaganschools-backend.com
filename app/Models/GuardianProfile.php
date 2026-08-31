<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'full_name',
    'phone',
    'alternate_phone',
    'email',
    'occupation',
    'address',
])]
class GuardianProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(StudentProfile::class, 'guardian_student')
            ->withPivot(['id', 'relationship', 'is_primary', 'can_login'])
            ->withTimestamps();
    }

    public function studentLinks(): HasMany
    {
        return $this->hasMany(GuardianStudent::class);
    }
}

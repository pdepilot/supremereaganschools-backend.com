<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;
use App\Models\User;

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
    use Notifiable;
    use SoftDeletes;

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    public function householdEmail(): string
    {
        $email = is_string($this->email) ? strtolower(trim($this->email)) : '';

        if ($email !== '' && ! User::query()->whereRaw('LOWER(email) = ?', [$email])->where('id', '!=', $this->user_id ?? 0)->exists()) {
            return $email;
        }

        return 'parent-'.$this->id.'@household.srs.invalid';
    }

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

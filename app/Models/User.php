<?php

namespace App\Models;

use App\Enums\AuthPortal;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\Concerns\HasPermissions;
use App\Models\Concerns\HasRoles;
use App\Notifications\ResetDeskPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPermissions, HasRoles, Notifiable;

    public ?AuthPortal $passwordResetPortal = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function guardianProfile(): HasOne
    {
        return $this->hasOne(GuardianProfile::class);
    }

    public function markedAttendance(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'marked_by');
    }

    public function recordedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'recorded_by');
    }

    public function enteredScores(): HasMany
    {
        return $this->hasMany(AssessmentScore::class, 'entered_by');
    }

    public function uploadedDocuments(): HasMany
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function assignedEnquiries(): HasMany
    {
        return $this->hasMany(ContactEnquiry::class, 'assigned_to');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    public function authorProfile(): HasOne
    {
        return $this->hasOne(AuthorProfile::class);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $portal = $this->passwordResetPortal ?? AuthPortal::forUser($this) ?? AuthPortal::Portal;

        $this->notify(new ResetDeskPassword((string) $token, $portal));
    }
}

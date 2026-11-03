<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\StaffStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'staff_number',
    'department_id',
    'gender',
    'job_title',
    'phone',
    'employed_on',
    'status',
    'photo_path',
])]
class StaffProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'employed_on' => 'date',
            'status' => StaffStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function classTeacherAssignments(): HasMany
    {
        return $this->hasMany(ClassTeacherAssignment::class);
    }

    public function subjectTeacherAssignments(): HasMany
    {
        return $this->hasMany(SubjectTeacherAssignment::class);
    }

    public function campusAssignments(): HasMany
    {
        return $this->hasMany(StaffCampusAssignment::class);
    }

    public function campuses(): BelongsToMany
    {
        return $this->belongsToMany(Campus::class, 'staff_campus_assignments')
            ->withPivot(['id', 'is_primary', 'start_date', 'end_date', 'is_active', 'assigned_by', 'notes'])
            ->withTimestamps();
    }

    public function activeCampuses(): BelongsToMany
    {
        $today = now()->toDateString();

        return $this->campuses()
            ->wherePivot('is_active', true)
            ->where(function ($query) use ($today) {
                $query->whereNull('staff_campus_assignments.start_date')
                    ->orWhere('staff_campus_assignments.start_date', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('staff_campus_assignments.end_date')
                    ->orWhere('staff_campus_assignments.end_date', '>=', $today);
            });
    }

    public function staffAttendanceRecords(): HasMany
    {
        return $this->hasMany(StaffAttendanceRecord::class);
    }

    public function isAssignableTeacher(): bool
    {
        return $this->status === StaffStatus::Active
            && $this->user?->isActive()
            && $this->user->hasAnyRole(
                \App\Enums\RoleSlug::Teacher,
                \App\Enums\RoleSlug::Staff,
                \App\Enums\RoleSlug::Principal,
                \App\Enums\RoleSlug::VicePrincipal,
            );
    }
}

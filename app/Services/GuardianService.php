<?php

namespace App\Services;

use App\Enums\GuardianRelationship;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\GuardianProfile;
use App\Models\GuardianStudent;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuardianService
{
    public function findExisting(?string $email = null, ?string $phone = null): ?GuardianProfile
    {
        $email = strtolower(trim((string) $email));
        $phone = trim((string) $phone);

        if ($email !== '') {
            $byEmail = GuardianProfile::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->orderBy('id')
                ->first();
            if ($byEmail) {
                return $byEmail;
            }

            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user) {
                $byUser = GuardianProfile::query()->where('user_id', $user->id)->orderBy('id')->first();
                if ($byUser) {
                    return $byUser;
                }
            }
        }

        if ($phone !== '') {
            return GuardianProfile::query()
                ->where('phone', $phone)
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): GuardianProfile
    {
        return DB::transaction(function () use ($attributes) {
            $userId = null;
            $email = isset($attributes['email']) ? strtolower(trim((string) $attributes['email'])) : null;

            if (! empty($attributes['password']) && filled($email)) {
                $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

                if ($user === null) {
                    $user = User::query()->create([
                        'name' => $attributes['full_name'],
                        'email' => $email,
                        'password' => $attributes['password'],
                        'status' => UserStatus::Active,
                    ]);
                    $user->assignRole(RoleSlug::Parent);
                } elseif (! $user->hasRole(RoleSlug::Parent)) {
                    throw ValidationException::withMessages([
                        'email' => 'This email belongs to another school account and cannot be used for a parent login.',
                    ]);
                }

                $userId = $user->id;
            }

            $guardian = GuardianProfile::query()->create([
                'user_id' => $userId,
                'full_name' => $attributes['full_name'],
                'phone' => $attributes['phone'] ?? null,
                'alternate_phone' => $attributes['alternate_phone'] ?? null,
                'email' => $email ?: ($attributes['email'] ?? null),
                'occupation' => $attributes['occupation'] ?? null,
                'address' => $attributes['address'] ?? null,
            ]);

            if (! empty($attributes['student_profile_id'])) {
                $this->link($guardian, [
                    'student_profile_id' => $attributes['student_profile_id'],
                    'relationship' => $attributes['relationship'] ?? GuardianRelationship::Guardian->value,
                    'is_primary' => $attributes['is_primary'] ?? false,
                    'can_login' => $attributes['can_login'] ?? true,
                ]);
            }

            return $guardian->fresh(['user', 'students']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(GuardianProfile $guardian, array $attributes): GuardianProfile
    {
        return DB::transaction(function () use ($guardian, $attributes) {
            if (! empty($attributes['password']) && ! empty($attributes['email']) && ! $guardian->user) {
                $user = User::query()->create([
                    'name' => $attributes['full_name'] ?? $guardian->full_name,
                    'email' => $attributes['email'],
                    'password' => $attributes['password'],
                    'status' => UserStatus::Active,
                ]);
                $user->assignRole(RoleSlug::Parent);
                $guardian->user_id = $user->id;
                $guardian->save();
            } elseif (isset($attributes['password']) && $guardian->user) {
                $guardian->user->update([
                    'password' => $attributes['password'],
                    'name' => $attributes['full_name'] ?? $guardian->full_name,
                    'email' => $attributes['email'] ?? $guardian->user->email,
                ]);
            } elseif (isset($attributes['full_name']) || isset($attributes['email'])) {
                $guardian->user?->update(array_filter([
                    'name' => $attributes['full_name'] ?? null,
                    'email' => $attributes['email'] ?? null,
                ]));
            }

            unset($attributes['password'], $attributes['student_profile_id'], $attributes['relationship'], $attributes['is_primary'], $attributes['can_login']);
            $guardian->update($attributes);

            return $guardian->fresh(['user', 'students']);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function link(GuardianProfile $guardian, array $attributes): GuardianStudent
    {
        $student = StudentProfile::query()->find($attributes['student_profile_id']);

        if ($student === null) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'The selected pupil does not exist.',
            ]);
        }

        $exists = GuardianStudent::query()
            ->where('guardian_profile_id', $guardian->id)
            ->where('student_profile_id', $student->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'This guardian is already linked to that pupil.',
            ]);
        }

        return DB::transaction(function () use ($guardian, $student, $attributes) {
            $isPrimary = (bool) ($attributes['is_primary'] ?? false);

            if ($isPrimary) {
                GuardianStudent::query()
                    ->where('student_profile_id', $student->id)
                    ->update(['is_primary' => false]);
            }

            return GuardianStudent::query()->create([
                'guardian_profile_id' => $guardian->id,
                'student_profile_id' => $student->id,
                'relationship' => $attributes['relationship'] ?? GuardianRelationship::Guardian->value,
                'is_primary' => $isPrimary,
                'can_login' => $attributes['can_login'] ?? true,
            ])->load(['guardian', 'student']);
        });
    }

    public function unlink(GuardianStudent $link): void
    {
        $link->delete();
    }

    public function delete(GuardianProfile $guardian): void
    {
        if ($guardian->studentLinks()->exists()) {
            throw ValidationException::withMessages([
                'guardian' => 'Unlink this guardian from all pupils before deleting.',
            ]);
        }

        DB::transaction(function () use ($guardian) {
            $guardian->user?->update(['status' => UserStatus::Inactive]);
            $guardian->delete();
        });
    }
}

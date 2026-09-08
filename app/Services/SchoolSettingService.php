<?php

namespace App\Services;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\SchoolSetting;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SchoolSettingService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(SchoolSetting $settings, array $attributes, ?int $updatedBy = null): SchoolSetting
    {
        $sessionId = $attributes['current_academic_session_id'] ?? $settings->current_academic_session_id;
        $termId = $attributes['current_term_id'] ?? $settings->current_term_id;

        if ($termId) {
            $term = Term::query()->find($termId);

            if ($term === null) {
                throw ValidationException::withMessages([
                    'current_term_id' => 'The selected term does not exist.',
                ]);
            }

            if ($sessionId && (int) $term->academic_session_id !== (int) $sessionId) {
                throw ValidationException::withMessages([
                    'current_term_id' => 'The current term must belong to the current academic session.',
                ]);
            }
        }

        unset(
            $attributes['cbt_login_email'],
            $attributes['cbt_login_password'],
            $attributes['cbt_operator_user_id'],
        );

        $settings->update([
            ...$attributes,
            'updated_by' => $updatedBy,
        ]);

        return $settings->fresh(['currentAcademicSession', 'currentTerm', 'cbtOperator']);
    }

    /**
     * @param  array{cbt_login_email: string, cbt_login_password?: ?string, cbt_operator_user_id: int}  $attributes
     */
    public function updateCbtLogin(SchoolSetting $settings, array $attributes, ?int $updatedBy = null): SchoolSetting
    {
        $operator = User::query()->find($attributes['cbt_operator_user_id']);

        if ($operator === null) {
            throw ValidationException::withMessages([
                'cbt_operator_user_id' => 'Choose a valid CBT operator account.',
            ]);
        }

        $canOperate = $operator->hasRole(RoleSlug::SuperAdmin)
            || $operator->hasAnyRole(
                RoleSlug::SchoolAdmin,
                RoleSlug::Principal,
                RoleSlug::VicePrincipal,
                RoleSlug::ExaminationOfficer,
            )
            || $operator->hasAnyPermission(
                PermissionSlug::CbtView,
                PermissionSlug::CbtManage,
                PermissionSlug::CbtProctor,
                PermissionSlug::CbtMark,
            );

        if (! $canOperate) {
            throw ValidationException::withMessages([
                'cbt_operator_user_id' => 'The CBT operator must have a CBT permission.',
            ]);
        }

        $password = $attributes['cbt_login_password'] ?? null;
        if (! filled($password) && ! filled($settings->getRawOriginal('cbt_login_password') ?? $settings->cbt_login_password)) {
            throw ValidationException::withMessages([
                'cbt_login_password' => 'Set a CBT desk password.',
            ]);
        }

        $payload = [
            'cbt_login_email' => strtolower(trim((string) $attributes['cbt_login_email'])),
            'cbt_operator_user_id' => $operator->id,
            'updated_by' => $updatedBy,
        ];

        if (filled($password)) {
            $payload['cbt_login_password'] = $password;
        }

        $settings->update($payload);

        return $settings->fresh(['currentAcademicSession', 'currentTerm', 'cbtOperator']);
    }

    public function authenticateCbtStaff(?string $email, string $password): ?User
    {
        $mailbox = strtolower(trim((string) $email));
        if ($mailbox === '') {
            return null;
        }

        $settings = SchoolSetting::query()->first();
        if ($settings === null || ! $settings->hasCbtLoginConfigured()) {
            return null;
        }

        if (strtolower((string) $settings->cbt_login_email) !== $mailbox) {
            return null;
        }

        $hash = $settings->getRawOriginal('cbt_login_password') ?? (string) $settings->cbt_login_password;
        if ($hash === '' || ! Hash::check($password, $hash)) {
            return null;
        }

        return $settings->cbtOperator;
    }
}

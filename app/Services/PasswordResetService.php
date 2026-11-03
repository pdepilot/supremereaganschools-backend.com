<?php

namespace App\Services;

use App\Enums\AuthPortal;
use App\Enums\RoleSlug;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\GuardianProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\ResetDeskPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public const SENT = 'If that address is on the books, a restoration letter has been sent.';

    public const RESET = 'Your password has been restored. Sign in with the new key.';

    public function scopedKey(AuthPortal $portal, string $identifier): string
    {
        return $portal->value.'|'.strtolower(trim($identifier));
    }

    public function send(string $email, AuthPortal $portal, ?string $admissionNumber = null): void
    {
        if (! $portal->supportsEmailPassword()) {
            return;
        }

        $mailbox = strtolower(trim($email));

        if ($mailbox === '' || ! $this->canReceiveMail($mailbox)) {
            return;
        }

        $target = $this->resolveTarget($mailbox, $portal, $admissionNumber);

        if ($target === null) {
            return;
        }

        $plainToken = Str::random(64);
        $studentAdmission = null;

        if ($portal === AuthPortal::Student && $target instanceof StudentProfile) {
            $studentAdmission = $target->admission_number;
            $scopedKey = $this->scopedKey($portal, $target->admission_number);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $scopedKey],
                [
                    'token' => Hash::make($plainToken),
                    'created_at' => now(),
                ],
            );

            $isGuardianEmail = GuardianProfile::query()->whereRaw('LOWER(email) = ?', [$mailbox])->exists();
            if (! $isGuardianEmail) {
                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $this->scopedKey($portal, $mailbox)],
                    [
                        'token' => Hash::make($plainToken),
                        'created_at' => now(),
                    ],
                );
            }
        } else {
            $scopedKey = $this->scopedKey($portal, $mailbox);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $scopedKey],
                [
                    'token' => Hash::make($plainToken),
                    'created_at' => now(),
                ],
            );
        }

        try {
            if ($target instanceof StudentProfile) {
                $target->recoveryMailRecipient = $mailbox;
            }

            $notifiable = ($target instanceof StudentProfile && $target->user !== null)
                ? $target->user
                : $target;

            $notification = new ResetDeskPassword($plainToken, $portal, $mailbox, $studentAdmission);

            if ($notifiable instanceof User || $notifiable instanceof GuardianProfile || $notifiable instanceof StudentProfile) {
                $notifiable->notify($notification);
            } else {
                Notification::route('mail', $mailbox)->notify($notification);
            }
        } catch (\Throwable $e) {
            $tokensToDelete = array_unique(array_filter([$scopedKey, $this->scopedKey($portal, $mailbox)]));
            DB::table('password_reset_tokens')->whereIn('email', $tokensToDelete)->delete();
            report($e);
        }
    }

    /**
     * @throws ValidationException
     */
    public function reset(
        string $email,
        string $token,
        string $password,
        AuthPortal $portal,
        ?string $admissionNumber = null,
    ): User {
        if (! $portal->supportsEmailPassword()) {
            throw ValidationException::withMessages([
                'email' => 'This desk cannot restore a password by letter.',
            ]);
        }

        $mailbox = strtolower(trim($email));

        if ($mailbox === '' || ! $this->canReceiveMail($mailbox)) {
            throw ValidationException::withMessages([
                'email' => 'This restoration link is not valid or has expired.',
            ]);
        }

        $lookupKeys = [];
        if ($portal === AuthPortal::Student && filled($admissionNumber)) {
            $lookupKeys[] = $this->scopedKey($portal, (string) $admissionNumber);
        }
        $lookupKeys[] = $this->scopedKey($portal, $mailbox);

        $record = DB::table('password_reset_tokens')
            ->whereIn('email', $lookupKeys)
            ->first();

        if ($record === null) {
            throw ValidationException::withMessages([
                'email' => 'This restoration link is not valid or has expired.',
            ]);
        }

        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        if (Carbon::parse($record->created_at)->addMinutes($expireMinutes)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $record->email)->delete();

            throw ValidationException::withMessages([
                'email' => 'This restoration link is not valid or has expired.',
            ]);
        }

        if (! Hash::check($token, $record->token)) {
            throw ValidationException::withMessages([
                'email' => 'This restoration link is not valid or has expired.',
            ]);
        }

        return DB::transaction(function () use ($mailbox, $password, $portal, $record, $admissionNumber, $lookupKeys): User {
            $account = match ($portal) {
                AuthPortal::Portal => $this->finalizePortalReset($mailbox, $password),
                AuthPortal::Staff => $this->finalizeStaffReset($mailbox, $password),
                AuthPortal::Parent => $this->finalizeParentReset($mailbox, $password),
                AuthPortal::Student => $this->finalizeStudentReset($mailbox, $password, $admissionNumber),
                default => null,
            };

            if ($account === null || $account->status !== UserStatus::Active || ! $portal->admits($account)) {
                throw ValidationException::withMessages([
                    'email' => 'This restoration link is not valid or has expired.',
                ]);
            }

            $deleteKeys = array_unique(array_filter(array_merge(
                $lookupKeys,
                [$record->email],
                filled($admissionNumber) ? [$this->scopedKey($portal, (string) $admissionNumber)] : [],
                [$this->scopedKey($portal, $mailbox)]
            )));
            DB::table('password_reset_tokens')->whereIn('email', $deleteKeys)->delete();

            if (config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $account->id)
                    ->delete();
            }

            return $account->fresh();
        });
    }

    public function resolveTarget(string $mailbox, AuthPortal $portal, ?string $admissionNumber = null): ?object
    {
        return match ($portal) {
            AuthPortal::Portal => User::query()
                ->whereRaw('LOWER(email) = ?', [$mailbox])
                ->where('status', UserStatus::Active)
                ->whereHas('roles', fn ($q) => $q->whereIn('slug', RoleSlug::portalRoles()))
                ->first(),

            AuthPortal::Staff => User::query()
                ->whereRaw('LOWER(email) = ?', [$mailbox])
                ->where('status', UserStatus::Active)
                ->whereHas('roles', fn ($q) => $q->whereIn('slug', RoleSlug::staffDeskRoles()))
                ->first(),

            AuthPortal::Parent => $this->resolveParentTarget($mailbox),

            AuthPortal::Student => $this->resolveStudentTarget($mailbox, $admissionNumber),

            default => null,
        };
    }

    private function resolveParentTarget(string $mailbox): ?object
    {
        $existingUser = User::query()->whereRaw('LOWER(email) = ?', [$mailbox])->first();

        if ($existingUser !== null && $existingUser->status === UserStatus::Active && $existingUser->hasRole(RoleSlug::Parent)) {
            return $existingUser;
        }

        $guardians = GuardianProfile::query()
            ->whereRaw('LOWER(email) = ?', [$mailbox])
            ->get();

        if ($guardians->isEmpty()) {
            return null;
        }

        $linkedUserIds = $guardians->pluck('user_id')->filter()->unique();

        if ($linkedUserIds->count() > 1) {
            return null;
        }

        if ($linkedUserIds->count() === 1) {
            $user = User::query()->find($linkedUserIds->first());
            if ($user && $user->status === UserStatus::Active && $user->hasRole(RoleSlug::Parent)) {
                return $user;
            }

            return null;
        }

        if ($existingUser !== null) {
            return null;
        }

        if ($guardians->count() === 1) {
            return $guardians->first();
        }

        return null;
    }

    private function resolveStudentTarget(string $mailbox, ?string $admissionNumber = null): ?object
    {
        if (filled($admissionNumber)) {
            $student = StudentProfile::query()
                ->whereRaw('LOWER(admission_number) = ?', [strtolower(trim((string) $admissionNumber))])
                ->where('status', StudentStatus::Active)
                ->first();

            if ($student === null || ! $this->isEmailAuthorizedForStudent($student, $mailbox)) {
                return null;
            }

            return $student;
        }

        $directStudents = StudentProfile::query()
            ->where('status', StudentStatus::Active)
            ->where(function ($q) use ($mailbox) {
                $q->whereRaw('LOWER(email) = ?', [$mailbox])
                  ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(email) = ?', [$mailbox]));
            })
            ->get();

        if ($directStudents->count() === 1) {
            $student = $directStudents->first();

            if ($student->user && User::query()->whereRaw('LOWER(email) = ?', [$mailbox])->where('id', '!=', $student->user->id)->exists()) {
                return null;
            }

            return $student;
        }

        return null;
    }

    public function isEmailAuthorizedForStudent(StudentProfile $student, string $mailbox): bool
    {
        if (is_string($student->email) && strtolower(trim($student->email)) === $mailbox) {
            return true;
        }

        if ($student->user && is_string($student->user->email) && strtolower(trim($student->user->email)) === $mailbox) {
            return true;
        }

        $student->loadMissing('guardians');

        return $student->guardians->contains(function (GuardianProfile $guardian) use ($mailbox) {
            return is_string($guardian->email) && strtolower(trim($guardian->email)) === $mailbox;
        });
    }

    private function finalizePortalReset(string $mailbox, string $password): ?User
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$mailbox])
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', RoleSlug::portalRoles()))
            ->first();

        if ($user === null) {
            return null;
        }

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
            'must_change_password' => false,
        ])->save();

        return $user;
    }

    private function finalizeStaffReset(string $mailbox, string $password): ?User
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$mailbox])
            ->whereHas('roles', fn ($q) => $q->whereIn('slug', RoleSlug::staffDeskRoles()))
            ->first();

        if ($user === null) {
            return null;
        }

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
            'must_change_password' => false,
        ])->save();

        return $user;
    }

    private function finalizeParentReset(string $mailbox, string $password): ?User
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$mailbox])
            ->whereHas('roles', fn ($q) => $q->where('slug', RoleSlug::Parent->value))
            ->first();

        if ($user !== null) {
            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'must_change_password' => false,
            ])->save();

            return $user;
        }

        $guardians = GuardianProfile::query()
            ->whereRaw('LOWER(email) = ?', [$mailbox])
            ->get();

        if ($guardians->isEmpty()) {
            return null;
        }

        $linkedUserIds = $guardians->pluck('user_id')->filter()->unique();

        if ($linkedUserIds->count() > 1) {
            return null;
        }

        if ($linkedUserIds->count() === 1) {
            $user = User::query()->find($linkedUserIds->first());

            if (! $user || ! $user->hasRole(RoleSlug::Parent)) {
                return null;
            }

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'must_change_password' => false,
            ])->save();

            return $user;
        }

        if (User::query()->whereRaw('LOWER(email) = ?', [$mailbox])->exists()) {
            return null;
        }

        if ($guardians->count() === 1) {
            $guardian = $guardians->first();

            $user = User::query()->create([
                'name' => $guardian->full_name,
                'email' => $guardian->householdEmail(),
                'password' => $password,
                'status' => UserStatus::Active,
                'remember_token' => Str::random(60),
                'must_change_password' => false,
            ]);
            $user->assignRole(RoleSlug::Parent);
            $guardian->user_id = $user->id;
            $guardian->save();

            return $user;
        }

        return null;
    }

    private function finalizeStudentReset(string $mailbox, string $password, ?string $admissionNumber = null): ?User
    {
        $student = null;
        if (filled($admissionNumber)) {
            $student = StudentProfile::query()
                ->whereRaw('LOWER(admission_number) = ?', [strtolower(trim((string) $admissionNumber))])
                ->where('status', StudentStatus::Active)
                ->first();
        }

        if ($student === null) {
            $directStudents = StudentProfile::query()
                ->where('status', StudentStatus::Active)
                ->where(function ($q) use ($mailbox) {
                    $q->whereRaw('LOWER(email) = ?', [$mailbox])
                      ->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(email) = ?', [$mailbox]));
                })
                ->get();

            if ($directStudents->count() === 1) {
                $student = $directStudents->first();
            }
        }

        if ($student === null || ! $this->isEmailAuthorizedForStudent($student, $mailbox)) {
            return null;
        }

        if ($student->user !== null) {
            $user = $student->user;

            if (is_string($student->email) && strtolower(trim($student->email)) === $mailbox) {
                $isGuardianEmail = GuardianProfile::query()->whereRaw('LOWER(email) = ?', [$mailbox])->exists();
                $isTaken = User::query()->whereRaw('LOWER(email) = ?', [$mailbox])->where('id', '!=', $user->id)->exists();

                if (! $isGuardianEmail && ! $isTaken && strtolower((string) $user->email) !== $mailbox) {
                    $user->email = $mailbox;
                }
            }

            $user->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'must_change_password' => false,
            ])->save();

            if (! $user->hasRole(RoleSlug::Student)) {
                $user->assignRole(RoleSlug::Student);
            }

            $student->forceFill([
                'passphrase_set_at' => now(),
            ])->save();

            return $user;
        }

        $loginEmail = app(SchoolNumberService::class)->studentLoginEmail($student->admission_number);

        if (is_string($student->email) && strtolower(trim($student->email)) === $mailbox) {
            $isGuardianEmail = GuardianProfile::query()->whereRaw('LOWER(email) = ?', [$mailbox])->exists();
            $isTaken = User::query()->whereRaw('LOWER(email) = ?', [$mailbox])->exists();

            if (! $isGuardianEmail && ! $isTaken && ! str_ends_with($mailbox, '.invalid')) {
                $loginEmail = $mailbox;
            }
        }

        $user = User::query()->create([
            'name' => $student->fullName(),
            'email' => $loginEmail,
            'password' => $password,
            'status' => UserStatus::Active,
            'remember_token' => Str::random(60),
            'must_change_password' => false,
        ]);
        $user->assignRole(RoleSlug::Student);

        $student->user_id = $user->id;
        $student->passphrase_set_at = now();
        $student->save();

        return $user;
    }

    public function canReceiveMail(?string $email): bool
    {
        if (! is_string($email) || trim($email) === '') {
            return false;
        }

        $email = strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && ! str_ends_with($email, '.invalid')
            && ! str_contains($email, '@students.');
    }
}

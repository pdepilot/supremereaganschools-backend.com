<?php

namespace App\Services;

use App\Enums\AuthPortal;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\GuardianProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    public const INVALID_CREDENTIALS = 'These credentials do not match our records.';

    /**
     * @throws ValidationException
     */
    public function login(
        ?string $email,
        ?string $admissionNumber,
        string $password,
        AuthPortal $portal,
        bool $remember,
        string $throttleKey,
    ): User {
        $this->ensureIsNotRateLimited($throttleKey);

        $household = $portal === AuthPortal::Student
            || ($portal === AuthPortal::Parent && filled($admissionNumber));

        $credentialField = $household ? 'admission_number' : 'email';

        $user = match (true) {
            $portal === AuthPortal::Student => $this->studentUserForLogin((string) $admissionNumber, $password),
            $portal === AuthPortal::Parent && $household => $this->parentUserForLogin((string) $admissionNumber, $password),
            $portal === AuthPortal::Parent => $this->parentUserForEmailLogin((string) $email, $password),
            default => User::query()->whereRaw('LOWER(email) = ?', [strtolower((string) $email)])->first(),
        };

        $passwordOk = match (true) {
            $household, $portal === AuthPortal::Parent => $user !== null,
            default => $user && Hash::check($password, $user->getAuthPassword()),
        };

        if (! $passwordOk) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                $credentialField => self::INVALID_CREDENTIALS,
            ]);
        }

        if ($user->status !== UserStatus::Active || ! $user->hasAnyRole(...$portal->allowedRoles())) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                $credentialField => self::INVALID_CREDENTIALS,
            ]);
        }

        Auth::login($user, $remember);
        request()->session()->regenerate();
        RateLimiter::clear($throttleKey);

        return $user;
    }

    private function studentUserForLogin(string $identifier, string $password): ?User
    {
        $matched = $this->studentProfilesForIdentifier($identifier)
            ->filter(fn (StudentProfile $student) => $student->secretMatches($password));

        if ($matched->count() !== 1) {
            return null;
        }

        return $matched->first()?->user;
    }

    /**
     * @return Collection<int, StudentProfile>
     */
    private function studentProfilesForIdentifier(string $identifier): Collection
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return collect();
        }

        $byNumber = StudentProfile::query()
            ->with(['user', 'guardians'])
            ->whereRaw('LOWER(admission_number) = ?', [strtolower($trimmed)])
            ->get();

        if ($byNumber->isNotEmpty()) {
            return $byNumber;
        }

        $parts = preg_split('/\s+/', strtolower($trimmed)) ?: [];

        if (count($parts) < 2) {
            return collect();
        }

        return StudentProfile::query()
            ->with(['user', 'guardians'])
            ->where(function ($query) use ($parts) {
                $first = $parts[0];
                $last = $parts[array_key_last($parts)];

                $query->where(function ($inner) use ($first, $last) {
                    $inner->whereRaw('LOWER(first_name) = ?', [$first])
                        ->whereRaw('LOWER(surname) = ?', [$last]);
                })->orWhere(function ($inner) use ($first, $last) {
                    $inner->whereRaw('LOWER(surname) = ?', [$first])
                        ->whereRaw('LOWER(first_name) = ?', [$last]);
                })->orWhere(function ($inner) use ($first, $parts) {
                    $inner->whereRaw('LOWER(first_name) = ?', [$first])
                        ->whereRaw('LOWER(surname) = ?', [$parts[1]]);
                });
            })
            ->get()
            ->filter(fn (StudentProfile $student) => $student->matchesLoginName($trimmed))
            ->values();
    }

    private function parentUserForLogin(string $identifier, string $password): ?User
    {
        $matched = $this->studentProfilesForIdentifier($identifier)
            ->flatMap(function (StudentProfile $student) use ($password) {
                return $student->guardians->filter(function (GuardianProfile $guardian) use ($password) {
                    return (bool) $guardian->pivot?->can_login
                        && $this->guardianPhoneMatches($guardian, $password);
                });
            })
            ->unique('id')
            ->values();

        if ($matched->count() !== 1) {
            return null;
        }

        return $this->ensureGuardianUser($matched->first());
    }

    private function parentUserForEmailLogin(string $email, string $password): ?User
    {
        $mailbox = strtolower(trim($email));
        if ($mailbox === '') {
            return null;
        }

        $guardian = GuardianProfile::query()
            ->whereRaw('LOWER(email) = ?', [$mailbox])
            ->first();

        if ($guardian && $this->guardianPhoneMatches($guardian, $password)) {
            return $this->ensureGuardianUser($guardian);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$mailbox])->first();
        if ($user && Hash::check($password, $user->getAuthPassword())) {
            return $user;
        }

        return null;
    }

    private function ensureGuardianUser(GuardianProfile $guardian): User
    {
        $user = $guardian->user;

        if ($user === null) {
            $user = User::query()->create([
                'name' => $guardian->full_name,
                'email' => $this->householdEmail($guardian),
                'password' => Str::password(32),
                'status' => UserStatus::Active,
            ]);
            $guardian->user_id = $user->id;
            $guardian->save();
        }

        if (! $user->hasRole(RoleSlug::Parent)) {
            $user->assignRole(RoleSlug::Parent);
        }

        return $user;
    }

    private function householdEmail(GuardianProfile $guardian): string
    {
        $email = is_string($guardian->email) ? strtolower(trim($guardian->email)) : '';

        if ($email !== '' && ! User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return $email;
        }

        return 'parent-'.$guardian->id.'@household.srs.invalid';
    }

    private function guardianPhoneMatches(GuardianProfile $guardian, string $attempt): bool
    {
        foreach ([$guardian->phone, $guardian->alternate_phone] as $phone) {
            if (is_string($phone) && $phone !== '' && Phone::matches($attempt, $phone)) {
                return true;
            }
        }

        return false;
    }

    public function logout(): void
    {
        Auth::logout();

        $session = request()->session();
        $session->invalidate();
        $session->regenerateToken();
    }

    public function throttleKey(string $identifier, string $ip): string
    {
        return Str::transliterate(Str::lower($identifier).'|'.$ip);
    }

    private function ensureIsNotRateLimited(string $throttleKey): void
    {
        if (! RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($throttleKey);

        throw ValidationException::withMessages([
            $this->credentialFieldFromKey($throttleKey) => 'Too many sign-in attempts. Please try again in '.$seconds.' seconds.',
        ]);
    }

    private function credentialFieldFromKey(string $throttleKey): string
    {
        return str_contains($throttleKey, '@') ? 'email' : 'admission_number';
    }
}

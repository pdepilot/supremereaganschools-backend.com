<?php

namespace App\Services;

use App\Enums\AuthPortal;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\GuardianProfile;
use App\Models\LoginActivity;
use App\Models\StaffProfile;
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

    public const CBT_DESK_SESSION_KEY = 'cbt_desk';

    public const DESK_SESSIONS_KEY = 'desk_sessions';

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
            || ($portal === AuthPortal::Parent && filled($admissionNumber))
            || ($portal === AuthPortal::Cbt && filled($admissionNumber));

        $credentialField = $household ? 'admission_number' : 'email';

        $user = match (true) {
            $portal === AuthPortal::Student => $this->studentUserForLogin((string) $admissionNumber, $password),
            $portal === AuthPortal::Cbt && $household => $this->studentUserForLogin((string) $admissionNumber, $password),
            $portal === AuthPortal::Cbt => app(SchoolSettingService::class)->authenticateCbtStaff($email, $password),
            $portal === AuthPortal::Parent && $household => $this->parentUserForLogin((string) $admissionNumber, $password),
            $portal === AuthPortal::Parent => $this->parentUserForEmailLogin((string) $email, $password),
            $portal === AuthPortal::Staff => $this->staffUserForLogin((string) $email, $password),
            default => User::query()->whereRaw('LOWER(email) = ?', [strtolower((string) $email)])->first(),
        };

        $passwordOk = match (true) {
            $household, $portal === AuthPortal::Parent, $portal === AuthPortal::Cbt, $portal === AuthPortal::Staff => $user !== null,
            default => $user && Hash::check($password, $user->getAuthPassword()),
        };

        if (! $passwordOk) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                $credentialField => self::INVALID_CREDENTIALS,
            ]);
        }

        if ($user->status !== UserStatus::Active || ! $portal->admits($user)) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                $credentialField => self::INVALID_CREDENTIALS,
            ]);
        }

        $previousDesks = $this->captureDeskSessionsFromCurrentUser();

        Auth::login($user, $remember);
        // Auth::login already regenerates the session (destroy=true). Re-apply
        // other desk accounts afterwards so database sessions keep them.
        $this->reapplyCapturedDeskSessions($previousDesks, $portal);
        $this->rememberDeskSession($portal, $user);
        RateLimiter::clear($throttleKey);

        $this->syncCbtDeskSession($portal);
        $this->recordLogin($user, $portal);

        return $user;
    }

    /**
     * Switch the active auth user back to the account last signed into this desk,
     * so office and faculty sessions can coexist in one browser.
     */
    public function activateDeskSession(AuthPortal $portal): ?User
    {
        if (! request()->hasSession()) {
            return null;
        }

        $current = Auth::user();
        if ($current instanceof User && $current->status === UserStatus::Active && $portal->admits($current)) {
            $this->rememberDeskSession($portal, $current);

            return $current;
        }

        $id = request()->session()->get(self::DESK_SESSIONS_KEY.'.'.$portal->value);
        if (! is_numeric($id)) {
            return null;
        }

        $user = User::query()->find((int) $id);
        if ($user === null || $user->status !== UserStatus::Active || ! $portal->admits($user)) {
            request()->session()->forget(self::DESK_SESSIONS_KEY.'.'.$portal->value);

            return null;
        }

        $previousDesks = $this->captureDeskSessionsFromCurrentUser();
        Auth::login($user);
        $this->reapplyCapturedDeskSessions($previousDesks, $portal);
        $this->rememberDeskSession($portal, $user);
        $this->syncCbtDeskSession($portal);

        return $user;
    }

    /**
     * On any desk URL, prefer the account last signed into that desk.
     */
    public function activateDeskForRequest(\Illuminate\Http\Request $request): ?User
    {
        if (! $request->user() instanceof User) {
            return null;
        }

        return $this->activateDeskSession(AuthPortal::matchingRequest($request));
    }

    public function clearDeskSessions(): void
    {
        if (request()->hasSession()) {
            request()->session()->forget(self::DESK_SESSIONS_KEY);
        }
    }

    /**
     * @return array<string, int>
     */
    private function captureDeskSessionsFromCurrentUser(): array
    {
        $captured = [];
        if (request()->hasSession()) {
            $existing = request()->session()->get(self::DESK_SESSIONS_KEY, []);
            if (is_array($existing)) {
                foreach ($existing as $desk => $id) {
                    if (is_numeric($id)) {
                        $captured[(string) $desk] = (int) $id;
                    }
                }
            }
        }

        $current = Auth::user();
        if (! $current instanceof User) {
            return $captured;
        }

        foreach ([AuthPortal::Portal, AuthPortal::Staff, AuthPortal::Parent, AuthPortal::Student, AuthPortal::Cbt] as $desk) {
            if ($desk->admits($current)) {
                $captured[$desk->value] = (int) $current->id;
            }
        }

        return $captured;
    }

    /**
     * @param  array<string, int>  $captured
     */
    private function reapplyCapturedDeskSessions(array $captured, AuthPortal $incoming): void
    {
        if (! request()->hasSession() || $captured === []) {
            return;
        }

        foreach ($captured as $desk => $id) {
            if ($desk === $incoming->value) {
                continue;
            }

            request()->session()->put(self::DESK_SESSIONS_KEY.'.'.$desk, $id);
        }
    }

    private function rememberDeskSession(AuthPortal $portal, User $user): void
    {
        if (! request()->hasSession()) {
            return;
        }

        request()->session()->put(self::DESK_SESSIONS_KEY.'.'.$portal->value, $user->id);
    }

    public function markCbtDeskSession(): void
    {
        if (request()->hasSession()) {
            request()->session()->put(self::CBT_DESK_SESSION_KEY, true);
        }
    }

    public function clearCbtDeskSession(): void
    {
        if (request()->hasSession()) {
            request()->session()->forget(self::CBT_DESK_SESSION_KEY);
        }
    }

    public function hasCbtDeskSession(): bool
    {
        return request()->hasSession()
            && request()->session()->get(self::CBT_DESK_SESSION_KEY) === true;
    }

    private function syncCbtDeskSession(AuthPortal $portal): void
    {
        if ($portal === AuthPortal::Cbt) {
            $this->markCbtDeskSession();

            return;
        }

        $this->clearCbtDeskSession();
    }

    private function recordLogin(User $user, AuthPortal $portal): void
    {
        try {
            LoginActivity::query()->create([
                'user_id' => $user->id,
                'portal' => $portal->value,
                'ip_address' => request()->ip(),
                'user_agent' => Str::limit((string) request()->userAgent(), 512, ''),
                'logged_in_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function staffUserForLogin(string $identifier, string $password): ?User
    {
        $trimmed = trim($identifier);

        if ($trimmed === '') {
            return null;
        }

        $user = null;

        if (str_contains($trimmed, '@')) {
            $user = User::query()
                ->with('staffProfile')
                ->whereRaw('LOWER(email) = ?', [strtolower($trimmed)])
                ->first();
        } else {
            $matched = StaffProfile::query()
                ->with('user')
                ->whereNotNull('phone')
                ->where('phone', '!=', '')
                ->get()
                ->filter(fn (StaffProfile $staff) => Phone::matches($trimmed, (string) $staff->phone))
                ->values();

            if ($matched->count() === 1) {
                $user = $matched->first()?->user;
            }
        }

        if ($user === null || ! $this->staffSecretMatches($user, $password)) {
            return null;
        }

        return $user;
    }

    private function staffSecretMatches(User $user, string $attempt): bool
    {
        if (Hash::check($attempt, $user->getAuthPassword())) {
            return true;
        }

        if (! $user->must_change_password) {
            return false;
        }

        $key = Phone::nationalKey($attempt);

        return $key !== '' && Hash::check($key, $user->getAuthPassword());
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

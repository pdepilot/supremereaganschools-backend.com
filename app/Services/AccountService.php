<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $user->update($attributes);

        app(RbacService::class)->audit($user, 'account.updated', $user, [
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return $user->fresh('roles');
    }

    public function changePassword(User $user, string $current, string $password): User
    {
        if (! $this->currentSecretMatches($user, $current)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current passphrase is incorrect.',
            ]);
        }

        $user->update([
            'password' => $password,
        ]);

        $student = $user->studentProfile;
        if ($student !== null && $student->passphrase_set_at === null) {
            $student->forceFill(['passphrase_set_at' => now()])->save();
        }

        $session = request()->session();
        $session->regenerate();
        $session->put([
            'password_hash_'.config('auth.defaults.guard', 'web') => $user->fresh()?->getAuthPassword(),
        ]);

        app(RbacService::class)->audit($user, 'account.password_changed', $user);

        return $user->fresh('roles');
    }

    private function currentSecretMatches(User $user, string $attempt): bool
    {
        if (Hash::check($attempt, $user->getAuthPassword())) {
            return true;
        }

        $student = $user->studentProfile;
        if ($student !== null && ! $student->hasPassphrase()) {
            return $student->guardianPhoneMatches($attempt);
        }

        return false;
    }
}

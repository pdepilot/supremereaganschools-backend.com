<?php

namespace App\Services;

use App\Enums\AuthPortal;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public const SENT = 'If that address is on the books, a restoration letter has been sent.';

    public const RESET = 'Your password has been restored. Sign in with the new key.';

    public function send(string $email, AuthPortal $portal): void
    {
        if (! $portal->supportsEmailPassword()) {
            return;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->first();

        if (
            $user === null
            || $user->status !== UserStatus::Active
            || ! $portal->admits($user)
            || ! $this->canReceiveMail($user)
        ) {
            return;
        }

        $user->passwordResetPortal = $portal;

        Password::sendResetLink(['email' => $user->email]);
    }

    /**
     * @throws ValidationException
     */
    public function reset(string $email, string $token, string $password, AuthPortal $portal): User
    {
        if (! $portal->supportsEmailPassword()) {
            throw ValidationException::withMessages([
                'email' => 'This desk cannot restore a password by letter.',
            ]);
        }

        $user = null;

        $status = Password::reset(
            [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'token' => $token,
            ],
            function (User $account) use ($password, $portal, &$user): void {
                if ($account->status !== UserStatus::Active || ! $portal->admits($account)) {
                    throw ValidationException::withMessages([
                        'email' => 'This restoration link is not valid or has expired.',
                    ]);
                }

                $account->forceFill([
                    'password' => $password,
                ])->save();

                $user = $account->fresh();
            },
        );

        if ($status !== Password::PASSWORD_RESET || $user === null) {
            throw ValidationException::withMessages([
                'email' => 'This restoration link is not valid or has expired.',
            ]);
        }

        return $user;
    }

    private function canReceiveMail(User $user): bool
    {
        $email = strtolower($user->email);

        return ! str_ends_with($email, '.invalid')
            && ! str_contains($email, '@students.');
    }
}

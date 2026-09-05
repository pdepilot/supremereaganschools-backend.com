<?php

namespace App\Console\Commands;

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SyncDeskAdminCommand extends Command
{
    protected $signature = 'desk:sync-admin
                            {--email= : Admin email (defaults to ADMIN_EMAIL / admin@supremereagan.com)}
                            {--password= : Admin password (defaults to ADMIN_PASSWORD)}';

    protected $description = 'Create or reset the portal school-admin login from env or options.';

    public function handle(): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: env('ADMIN_EMAIL', 'admin@supremereagan.com'))));
        $password = (string) ($this->option('password') ?: env('ADMIN_PASSWORD', ''));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Set a valid ADMIN_EMAIL in .env or pass --email=.');

            return self::FAILURE;
        }

        if (strlen($password) < 8) {
            $this->error('Set ADMIN_PASSWORD in .env (min 8 chars) or pass --password=.');

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first()
            ?? User::query()
                ->whereHas('roles', fn ($query) => $query->where('slug', RoleSlug::SchoolAdmin->value))
                ->first();

        if ($user === null) {
            $user = User::query()->create([
                'name' => 'School Administrator',
                'email' => $email,
                'password' => $password,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);
            $this->info('Created portal admin '.$email);
        } else {
            $user->fill([
                'email' => $email,
                'password' => $password,
                'status' => UserStatus::Active,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
            $this->info('Updated portal admin '.$email);
        }

        $user->assignRole(RoleSlug::SchoolAdmin);

        if (! Hash::check($password, $user->fresh()->getAuthPassword())) {
            $this->error('Password hash check failed after save.');

            return self::FAILURE;
        }

        $this->line('Roles: '.$user->fresh()->roleSlugs()->implode(', '));
        $this->line('Status: '.$user->fresh()->status->value);
        $this->info('Portal login is ready at /portal/login');

        return self::SUCCESS;
    }
}

<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;

class LocalAdminSeeder extends Seeder
{
    public static function email(): string
    {
        return strtolower((string) env('ADMIN_EMAIL', 'admin@supremereagan.com'));
    }

    public static function user(): ?User
    {
        return User::query()->where('email', self::email())->first()
            ?? User::query()
                ->whereHas('roles', fn ($query) => $query->where('slug', RoleSlug::SchoolAdmin->value))
                ->first();
    }

    public function run(): void
    {
        $email = self::email();
        $password = (string) env('ADMIN_PASSWORD', 'password');

        $user = self::user();

        if ($user === null) {
            $user = User::query()->create([
                'name' => 'School Administrator',
                'email' => $email,
                'password' => $password,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);
        } else {
            $user->fill([
                'email' => $email,
                'password' => $password,
                'status' => UserStatus::Active,
            ])->save();
        }

        $user->assignRole(RoleSlug::SchoolAdmin);
    }
}

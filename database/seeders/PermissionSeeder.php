<?php

namespace Database\Seeders;

use App\Enums\PermissionSlug;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionSlug::cases() as $index => $permission) {
            Permission::query()->updateOrCreate(
                ['slug' => $permission->value],
                [
                    'name' => $permission->label(),
                    'group' => $permission->group(),
                    'description' => $permission->label().' access on the command desk.',
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}

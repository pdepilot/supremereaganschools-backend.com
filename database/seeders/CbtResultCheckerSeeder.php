<?php

namespace Database\Seeders;

use App\Models\CbtResultProduct;
use Illuminate\Database\Seeder;

class CbtResultCheckerSeeder extends Seeder
{
    public function run(): void
    {
        CbtResultProduct::query()->updateOrCreate(
            ['code' => 'SINGLE_RESULT'],
            [
                'name' => 'Single CBT Result Checker',
                'description' => 'Unlock detailed scores and the printable report for one CBT result.',
                'amount_kobo' => 50000,
                'currency' => 'NGN',
                'checks_allowed' => 1,
                'duration_days' => null,
                'is_active' => true,
            ],
        );
    }
}

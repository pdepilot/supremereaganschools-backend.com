<?php

namespace Database\Seeders;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\Term;
use Illuminate\Database\Seeder;

class AcademicSessionSeeder extends Seeder
{
    public function run(): void
    {
        $sessions = [
            [
                'name' => '2024/2025',
                'starts_on' => '2024-09-09',
                'ends_on' => '2025-07-25',
                'status' => SessionStatus::Archived,
            ],
            [
                'name' => '2025/2026',
                'starts_on' => '2025-09-08',
                'ends_on' => '2026-07-24',
                'status' => SessionStatus::Active,
            ],
        ];

        $termNames = [1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'];

        foreach ($sessions as $data) {
            $session = AcademicSession::query()->updateOrCreate(
                ['name' => $data['name']],
                [
                    'starts_on' => $data['starts_on'],
                    'ends_on' => $data['ends_on'],
                    'term_count' => 3,
                    'status' => $data['status'],
                ],
            );

            foreach ($termNames as $number => $name) {
                Term::query()->updateOrCreate(
                    ['academic_session_id' => $session->id, 'term_number' => $number],
                    [
                        'name' => $name,
                        'status' => $session->status === SessionStatus::Active && $number === 1
                            ? SessionStatus::Active
                            : ($session->status === SessionStatus::Archived ? SessionStatus::Archived : SessionStatus::Planned),
                    ],
                );
            }
        }
    }
}

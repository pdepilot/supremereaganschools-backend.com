<?php

namespace Database\Seeders;

use App\Enums\SessionStatus;
use App\Models\AcademicSession;
use App\Models\SchoolSetting;
use App\Models\Term;
use Illuminate\Database\Seeder;

class SchoolSettingSeeder extends Seeder
{
    public function run(): void
    {
        $session = AcademicSession::query()->where('status', SessionStatus::Active)->first();
        $term = $session
            ? Term::query()->where('academic_session_id', $session->id)->where('term_number', 1)->first()
            : null;

        SchoolSetting::query()->updateOrCreate(
            ['id' => 1],
            [
                'name' => 'Supreme Reagan Schools',
                'short_name' => 'SRS',
                'motto' => 'Knowledge · Character · Excellence',
                'address' => '15 Spibat Road, Amakohia-Akwakuma, Owerri',
                'city' => 'Owerri',
                'state' => 'Imo',
                'phone' => '09065641343',
                'email' => 'supremereagansch@gmail.com',
                'admissions_email' => 'supremereagansch@gmail.com',
                'whatsapp' => '2349065641343',
                'website' => null,
                'timezone' => 'Africa/Lagos',
                'founded_on' => '2010-09-13',
                'office_opens_at' => '08:00',
                'office_closes_at' => '16:00',
                'logo_path' => '/site/Image/logo_main.png',
                'current_academic_session_id' => $session?->id,
                'current_term_id' => $term?->id,
            ],
        );
    }
}

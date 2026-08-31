<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Database\Seeder;

class FeesSeeder extends Seeder
{
    public function run(): void
    {
        $session = AcademicSession::query()->where('name', '2025/2026')->first();
        $term = $session
            ? Term::query()->where('academic_session_id', $session->id)->where('term_number', 1)->first()
            : null;

        if ($session === null || $term === null) {
            return;
        }

        $types = [
            ['name' => 'Tuition', 'code' => 'TUITION', 'amount' => 180000],
            ['name' => 'ICT Fee', 'code' => 'ICT', 'amount' => 25000],
            ['name' => 'Development Levy', 'code' => 'DEV', 'amount' => 50000],
            ['name' => 'Learning Materials', 'code' => 'MATERIALS', 'amount' => 30000],
        ];

        foreach ($types as $type) {
            $feeType = FeeType::query()->updateOrCreate(
                ['code' => $type['code']],
                ['name' => $type['name'], 'is_active' => true],
            );

            $exists = FeeStructure::query()
                ->where('fee_type_id', $feeType->id)
                ->where('academic_session_id', $session->id)
                ->where('term_id', $term->id)
                ->whereNull('level_id')
                ->whereNull('school_class_id')
                ->exists();

            if ($exists) {
                continue;
            }

            FeeStructure::query()->create([
                'fee_type_id' => $feeType->id,
                'academic_session_id' => $session->id,
                'term_id' => $term->id,
                'amount_kobo' => Money::toKobo($type['amount']),
            ]);
        }

        $invoices = app(InvoiceService::class);
        Enrollment::query()
            ->where('academic_session_id', $session->id)
            ->orderBy('id')
            ->get()
            ->each(function (Enrollment $enrollment) use ($invoices, $term) {
                $exists = Invoice::query()
                    ->where('student_profile_id', $enrollment->student_profile_id)
                    ->where('term_id', $term->id)
                    ->exists();

                if ($exists) {
                    return;
                }

                try {
                    $invoices->create([
                        'student_profile_id' => $enrollment->student_profile_id,
                        'term_id' => $term->id,
                        'due_on' => '2025-09-12',
                    ]);
                } catch (\Illuminate\Validation\ValidationException) {
                    //
                }
            });

        $actor = LocalAdminSeeder::user();
        $payments = app(PaymentService::class);

        if ($actor === null) {
            return;
        }

        $this->postIfNeeded($payments, $actor, 'SRS/2025/0142', 100000, 'transfer', 'First term tuition');
        $this->postIfNeeded($payments, $actor, 'SRS/2025/0142', 100000, 'transfer', 'First term balance');
        $this->postIfNeeded($payments, $actor, 'SRS/2025/0142', 85000, 'transfer', 'First term remainder');
        $this->postIfNeeded($payments, $actor, 'SRS/2025/0198', 95000, 'cash', 'Partial first term');
    }

    private function postIfNeeded(
        PaymentService $payments,
        User $actor,
        string $admissionNumber,
        int $amountNaira,
        string $channel,
        string $note,
    ): void {
        $student = StudentProfile::query()->where('admission_number', $admissionNumber)->first();

        if ($student === null) {
            return;
        }

        $already = Payment::query()
            ->where('student_profile_id', $student->id)
            ->where('amount_kobo', Money::toKobo($amountNaira))
            ->where('note', $note)
            ->exists();

        if ($already) {
            return;
        }

        try {
            $payments->post([
                'admission_number' => $admissionNumber,
                'amount' => $amountNaira,
                'channel' => $channel,
                'note' => $note,
            ], $actor);
        } catch (\Illuminate\Validation\ValidationException) {
            //
        }
    }
}

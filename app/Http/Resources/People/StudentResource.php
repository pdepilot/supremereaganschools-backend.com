<?php

namespace App\Http\Resources\People;

use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\RoleSlug;
use App\Enums\SchoolWing;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\StudentProfile;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StudentProfile
 */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = $request->user()?->hasAnyRole(RoleSlug::SuperAdmin, RoleSlug::SchoolAdmin) ?? false;
        $self = (int) $request->user()?->studentProfile?->id === (int) $this->id;
        $folder = $admin || $self;
        $current = $this->currentEnrollment();
        $fees = ($admin && $this->relationLoaded('invoices')) ? $this->feeSummary($current) : null;

        return [
            'id' => $this->id,
            'admission_number' => $this->admission_number,
            'surname' => $this->surname,
            'first_name' => $this->first_name,
            'other_names' => $this->other_names,
            'full_name' => $this->fullName(),
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->when($folder, $this->nationality),
            'state_of_origin' => $this->when($folder, $this->state_of_origin),
            'lga' => $this->when($folder, $this->lga),
            'home_address' => $this->when($folder, $this->home_address),
            'phone' => $this->when($folder, $this->phone),
            'email' => $this->when($folder, $this->email),
            'status' => $this->status?->value,
            'account_status' => $this->whenLoaded('user', fn () => $this->user?->status?->value),
            'admitted_on' => $this->admitted_on?->toDateString(),
            'blood_group' => $this->when($folder, $this->blood_group),
            'genotype' => $this->when($folder, $this->genotype),
            'medical_notes' => $this->when($folder, $this->medical_notes),
            'previous_school' => $this->when($folder, $this->previous_school),
            'interests' => $this->when($folder, $this->interests),
            'has_photo' => filled($this->photo_path),
            'photo_url' => filled($this->photo_path) ? '/api/v1/students/'.$this->id.'/photo' : null,
            'class_section_offering_id' => $this->whenLoaded('enrollments', fn () => $current?->class_section_offering_id),
            'current_form' => $this->whenLoaded('enrollments', fn () => $current?->classSectionOffering?->classSection?->name),
            'level_id' => $this->whenLoaded('enrollments', fn () => $current?->classSectionOffering?->classSection?->schoolClass?->level_id),
            'level_name' => $this->whenLoaded('enrollments', fn () => $current?->classSectionOffering?->classSection?->schoolClass?->level?->name),
            'level_slug' => $this->whenLoaded('enrollments', fn () => $current?->classSectionOffering?->classSection?->schoolClass?->level?->slug),
            'wing' => $this->whenLoaded('enrollments', function () use ($current) {
                $slug = $current?->classSectionOffering?->classSection?->schoolClass?->level?->slug;

                return SchoolWing::fromLevelSlug($slug)?->value;
            }),
            'campus_name' => $this->whenLoaded('enrollments', fn () => $current?->classSectionOffering?->campus?->name),
            'session_name' => $this->whenLoaded('enrollments', fn () => $current?->academicSession?->name),
            'primary_guardian' => $this->whenLoaded('guardians', function () {
                $link = $this->guardians->firstWhere('pivot.is_primary', true) ?? $this->guardians->first();

                return $link?->full_name;
            }),
            'fee_state' => $this->when($admin, $fees['state'] ?? null),
            'fee_label' => $this->when($admin, $fees['label'] ?? null),
            'fee_status' => $this->when($admin, $fees['fee_status'] ?? null),
            'fee_status_label' => $this->when($admin, $fees['fee_status_label'] ?? null),
            'fee_total_naira' => $this->when($admin, $fees['total_naira'] ?? null),
            'fee_paid_naira' => $this->when($admin, $fees['paid_naira'] ?? null),
            'fee_balance_naira' => $this->when($admin, $fees['balance_naira'] ?? null),
            'enrollments' => EnrollmentResource::collection($this->whenLoaded('enrollments')),
            'guardians' => GuardianResource::collection($this->whenLoaded('guardians')),
        ];
    }

    private function currentEnrollment(): ?Enrollment
    {
        if (! $this->relationLoaded('enrollments')) {
            return null;
        }

        return $this->enrollments->first(
            fn (Enrollment $enrollment) => $enrollment->status === EnrollmentStatus::Active,
        ) ?? $this->enrollments->sortByDesc('enrolled_on')->first();
    }

    /**
     * @return array{
     *     state: string,
     *     label: string,
     *     fee_status: string,
     *     fee_status_label: string,
     *     total_naira: float,
     *     paid_naira: float,
     *     balance_naira: float
     * }
     */
    private function feeSummary(?Enrollment $current): array
    {
        $invoices = $this->invoices->filter(function (Invoice $invoice) use ($current) {
            if ($invoice->status === InvoiceStatus::Void) {
                return false;
            }

            if ($current === null) {
                return true;
            }

            return (int) $invoice->academic_session_id === (int) $current->academic_session_id;
        });

        if ($invoices->isEmpty()) {
            return [
                'state' => 'none',
                'label' => 'No invoice',
                'fee_status' => 'none',
                'fee_status_label' => 'No invoice',
                'total_naira' => 0,
                'paid_naira' => 0,
                'balance_naira' => 0,
            ];
        }

        $total = (int) $invoices->sum('total_kobo');
        $paid = (int) $invoices->sum('paid_kobo');
        $remaining = max(0, $total - $paid);

        if ($remaining <= 0) {
            $state = 'paid';
            $status = InvoiceStatus::Paid;
            $label = 'Paid in Full';
        } elseif ($paid > 0) {
            $state = 'partial';
            $status = InvoiceStatus::Partial;
            $label = Money::formatNaira($remaining).' due';
        } else {
            $state = 'outstanding';
            $status = InvoiceStatus::Unpaid;
            $label = Money::formatNaira($remaining).' due';
        }

        return [
            'state' => $state,
            'label' => $label,
            'fee_status' => $status->feeStatus(),
            'fee_status_label' => $status->feeStatusLabel(),
            'total_naira' => Money::toNaira($total),
            'paid_naira' => Money::toNaira($paid),
            'balance_naira' => Money::toNaira($remaining),
        ];
    }
}

<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Enrollment;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentAllocation;
use App\Models\Term;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private readonly SchoolNumberService $numbers) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Invoice
    {
        $term = $this->term((int) $attributes['term_id']);
        $enrollment = $this->enrollmentFor((int) $attributes['student_profile_id'], $term);

        return DB::transaction(function () use ($attributes, $term, $enrollment) {
            $this->assertNoDuplicate((int) $attributes['student_profile_id'], $term->id);

            $lines = $this->linesFor($enrollment, $term);

            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'term_id' => 'No fee structure matches this pupil for the selected term.',
                ]);
            }

            $invoice = Invoice::query()->create([
                'number' => $this->numbers->nextInvoiceNumber((int) $term->academicSession?->starts_on?->year),
                'student_profile_id' => $enrollment->student_profile_id,
                'enrollment_id' => $enrollment->id,
                'academic_session_id' => $term->academic_session_id,
                'term_id' => $term->id,
                'status' => InvoiceStatus::Unpaid,
                'total_kobo' => 0,
                'paid_kobo' => 0,
                'due_on' => $attributes['due_on'] ?? $term->starts_on?->toDateString()
                    ?? $term->academicSession?->starts_on?->toDateString(),
            ]);

            foreach ($lines as $line) {
                $invoice->items()->create($line);
            }

            $invoice->update(['total_kobo' => $invoice->items()->sum('amount_kobo')]);

            return $this->fresh($invoice);
        });
    }

    /**
     * @return array{created: int, skipped: int, invoices: Collection<int, Invoice>}
     */
    public function generateForTerm(int $termId): array
    {
        $term = $this->term($termId);

        $enrollments = Enrollment::query()
            ->with(['classSectionOffering.classSection.schoolClass'])
            ->where('academic_session_id', $term->academic_session_id)
            ->where('status', EnrollmentStatus::Active)
            ->orderBy('id')
            ->get();

        $created = collect();
        $skipped = 0;

        foreach ($enrollments as $enrollment) {
            $exists = Invoice::query()
                ->where('student_profile_id', $enrollment->student_profile_id)
                ->where('term_id', $term->id)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            try {
                $created->push($this->create([
                    'student_profile_id' => $enrollment->student_profile_id,
                    'term_id' => $term->id,
                ]));
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return [
            'created' => $created->count(),
            'skipped' => $skipped,
            'invoices' => $created,
        ];
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function applyLedgerFilters(Builder $query, Request $request): Builder
    {
        if ($request->filled('academic_session_id')) {
            $query->where('academic_session_id', $request->integer('academic_session_id'));
        }

        if ($request->filled('term_id')) {
            $query->where('term_id', $request->integer('term_id'));
        }

        if ($request->filled('status')) {
            $status = InvoiceStatus::fromFilter($request->string('status')->toString());
            if ($status !== null) {
                $query->where('status', $status->value);
            }
        }

        if ($request->filled('admission_number')) {
            $number = trim($request->string('admission_number')->toString());
            $query->whereHas('student', fn ($student) => $student->where('admission_number', $number));
        }

        if ($request->filled('q')) {
            $search = trim($request->string('q')->toString());
            $query->whereHas('student', function ($student) use ($search) {
                $student->where(function ($builder) use ($search) {
                    $builder->where('admission_number', 'like', '%'.$search.'%')
                        ->orWhere('surname', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('other_names', 'like', '%'.$search.'%');
                });
            });
        }

        if ($request->filled('due_from')) {
            $query->whereDate('due_on', '>=', $request->date('due_from'));
        }

        if ($request->filled('due_to')) {
            $query->whereDate('due_on', '<=', $request->date('due_to'));
        }

        if ($request->filled('school_class_id')
            || $request->filled('class_section_id')
            || $request->filled('class_section_offering_id')
            || $request->filled('arm')) {
            $query->whereHas('enrollment.classSectionOffering', function ($offering) use ($request) {
                if ($request->filled('class_section_offering_id')) {
                    $offering->where('class_section_offerings.id', $request->integer('class_section_offering_id'));
                }

                if ($request->filled('class_section_id')) {
                    $offering->where('class_section_id', $request->integer('class_section_id'));
                }

                if ($request->filled('school_class_id') || $request->filled('arm')) {
                    $offering->whereHas('classSection', function ($section) use ($request) {
                        if ($request->filled('school_class_id')) {
                            $section->where('school_class_id', $request->integer('school_class_id'));
                        }

                        if ($request->filled('arm')) {
                            $section->where('arm', trim($request->string('arm')->toString()));
                        }
                    });
                }
            });
        }

        return $query;
    }

    public function refreshTotals(Invoice $invoice): Invoice
    {
        $invoice->load('items');

        foreach ($invoice->items as $item) {
            $paid = (int) PaymentAllocation::query()
                ->where('invoice_item_id', $item->id)
                ->whereHas('payment', fn ($payment) => $payment->where('status', PaymentStatus::Posted->value))
                ->sum('amount_kobo');

            $item->update(['paid_kobo' => $paid]);
        }

        $invoice->paid_kobo = (int) $invoice->items()->sum('paid_kobo');

        if ($invoice->status !== InvoiceStatus::Void) {
            if ($invoice->total_kobo > 0 && $invoice->paid_kobo >= $invoice->total_kobo) {
                $invoice->status = InvoiceStatus::Paid;
            } elseif ($invoice->paid_kobo > 0) {
                $invoice->status = InvoiceStatus::Partial;
            } else {
                $invoice->status = InvoiceStatus::Unpaid;
            }
        }

        $invoice->save();

        return $this->fresh($invoice);
    }

    public function void(Invoice $invoice): Invoice
    {
        if ($invoice->payments()->where('status', PaymentStatus::Posted)->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice has posted payments and cannot be voided. Void the payments first.',
            ]);
        }

        $invoice->update(['status' => InvoiceStatus::Void]);

        return $this->fresh($invoice);
    }

    /**
     * @return list<string>
     */
    public function defaultRelations(): array
    {
        return [
            'student',
            'enrollment.classSectionOffering.classSection',
            'academicSession',
            'term',
            'items.feeType',
        ];
    }

    public function fresh(Invoice $invoice): Invoice
    {
        return $invoice->fresh($this->defaultRelations());
    }

    private function term(int $termId): Term
    {
        $term = Term::query()->with('academicSession')->find($termId);

        if ($term === null) {
            throw ValidationException::withMessages([
                'term_id' => 'The selected term does not exist.',
            ]);
        }

        return $term;
    }

    private function enrollmentFor(int $studentId, Term $term): Enrollment
    {
        $enrollment = Enrollment::query()
            ->with(['classSectionOffering.classSection.schoolClass'])
            ->where('student_profile_id', $studentId)
            ->where('academic_session_id', $term->academic_session_id)
            ->first();

        if ($enrollment === null) {
            throw ValidationException::withMessages([
                'student_profile_id' => 'This pupil is not enrolled in that academic session.',
            ]);
        }

        return $enrollment;
    }

    private function assertNoDuplicate(int $studentId, int $termId): void
    {
        if (Invoice::query()->where('student_profile_id', $studentId)->where('term_id', $termId)->exists()) {
            throw ValidationException::withMessages([
                'term_id' => 'An invoice already exists for this pupil in that term.',
            ]);
        }
    }

    /**
     * @return Collection<int, array{fee_type_id: int, description: string, amount_kobo: int, paid_kobo: int}>
     */
    private function linesFor(Enrollment $enrollment, Term $term): Collection
    {
        $class = $enrollment->classSectionOffering?->classSection?->schoolClass;
        $levelId = $class?->level_id;
        $classId = $class?->id;

        $candidates = FeeStructure::query()
            ->with('feeType')
            ->where('academic_session_id', $term->academic_session_id)
            ->where(fn ($query) => $query->whereNull('term_id')->orWhere('term_id', $term->id))
            ->where(fn ($query) => $query->whereNull('level_id')->orWhere('level_id', $levelId))
            ->where(fn ($query) => $query->whereNull('school_class_id')->orWhere('school_class_id', $classId))
            ->whereHas('feeType', fn ($query) => $query->where('is_active', true))
            ->get();

        return $candidates
            ->groupBy('fee_type_id')
            ->map(function (Collection $group) use ($term, $levelId, $classId) {
                $best = $group->sortByDesc(function (FeeStructure $structure) use ($term, $levelId, $classId) {
                    $score = 0;
                    if ($structure->school_class_id && (int) $structure->school_class_id === (int) $classId) {
                        $score += 4;
                    }
                    if ($structure->level_id && (int) $structure->level_id === (int) $levelId) {
                        $score += 2;
                    }
                    if ($structure->term_id && (int) $structure->term_id === (int) $term->id) {
                        $score += 1;
                    }

                    return $score;
                })->first();

                return [
                    'fee_type_id' => $best->fee_type_id,
                    'description' => $best->feeType?->name ?? 'Fee',
                    'amount_kobo' => $best->amount_kobo,
                    'paid_kobo' => 0,
                ];
            })
            ->values();
    }
}

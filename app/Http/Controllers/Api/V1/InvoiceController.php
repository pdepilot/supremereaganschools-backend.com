<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fees\GenerateInvoicesRequest;
use App\Http\Requests\Fees\StoreInvoiceRequest;
use App\Http\Resources\Fees\InvoiceResource;
use App\Http\Resources\Fees\PaymentResource;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolSetting;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Services\InvoiceService;
use App\Services\PeopleAccessService;
use App\Support\ApiResponse;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PeopleAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $rows = $this->scopedQuery($request)
            ->with($this->invoices->defaultRelations())
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success('Invoices retrieved.', InvoiceResource::collection($rows)->resolve());
    }

    public function mine(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    public function forStudent(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->assertHouseholdFees($request->user(), $studentProfile);
        $request->merge(['student_profile_id' => $studentProfile->id]);

        return $this->index($request);
    }

    public function studentSummary(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->assertHouseholdFees($request->user(), $studentProfile);
        $request->merge(['student_profile_id' => $studentProfile->id]);

        return ApiResponse::success(
            'Fee summary retrieved.',
            $this->householdSummary($request, $studentProfile),
        );
    }

    public function mineSummary(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('viewAny', Invoice::class);

        if ($this->access->administers($user)) {
            return $this->summary($request);
        }

        abort_unless($this->access->isStudent($user) || $this->access->isParent($user), 403);

        $student = $this->access->isStudent($user) ? $user->studentProfile : null;

        if ($this->access->isParent($user) && $request->filled('student_profile_id')) {
            $ids = $this->access->linkedStudentIds($user);
            $this->assertLinkedStudent($request->integer('student_profile_id'), $ids);
            $student = StudentProfile::query()->find($request->integer('student_profile_id'));
        }

        return ApiResponse::success(
            'Fee summary retrieved.',
            $this->householdSummary($request, $student),
        );
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);
        abort_unless($this->access->administers($request->user()), 403);

        $query = Invoice::query()->where('status', '!=', InvoiceStatus::Void->value);

        if ($this->hasExplicitLedgerScope($request)) {
            $this->invoices->applyLedgerFilters($query, $request);
        } else {
            $termId = SchoolSetting::query()->value('current_term_id');
            if ($termId) {
                $query->where('term_id', $termId);
            }
        }

        return ApiResponse::success('Fee ledger summary retrieved.', $this->summarize($query, $request));
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoices->create($request->validated());

        return ApiResponse::success('Invoice created.', (new InvoiceResource($invoice))->resolve(), 201);
    }

    public function generate(GenerateInvoicesRequest $request): JsonResponse
    {
        $result = $this->invoices->generateForTerm((int) $request->validated('term_id'));

        return ApiResponse::success('Invoices generated.', [
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'invoices' => InvoiceResource::collection($result['invoices'])->resolve(),
        ]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return ApiResponse::success(
            'Invoice retrieved.',
            (new InvoiceResource($invoice->load($this->invoices->defaultRelations())))->resolve(),
        );
    }

    public function statement(Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        $invoice->load(array_merge($this->invoices->defaultRelations(), [
            'payments' => fn ($query) => $query->where('status', PaymentStatus::Posted->value)->orderByDesc('paid_at'),
            'payments.recorder:id,name',
        ]));

        return ApiResponse::success('Fee statement retrieved.', [
            'invoice' => (new InvoiceResource($invoice))->resolve(),
            'payments' => PaymentResource::collection($invoice->payments)->resolve(),
        ]);
    }

    public function destroy(Invoice $invoice): JsonResponse
    {
        $this->authorize('delete', $invoice);
        $invoice = $this->invoices->void($invoice);

        return ApiResponse::success('Invoice voided.', (new InvoiceResource($invoice))->resolve());
    }

    /**
     * @return Builder<Invoice>
     */
    private function scopedQuery(Request $request): Builder
    {
        $query = Invoice::query();
        $user = $request->user();

        if ($this->access->isStudent($user) && ! $this->access->administers($user)) {
            $query->where('student_profile_id', $user->studentProfile?->id);
        } elseif ($this->access->isParent($user) && ! $this->access->administers($user)) {
            $ids = $this->access->linkedStudentIds($user);
            if ($request->filled('student_profile_id')) {
                $this->assertLinkedStudent($request->integer('student_profile_id'), $ids);
                $ids = collect([$request->integer('student_profile_id')]);
            }
            $query->whereIn('student_profile_id', $ids);
        } elseif ($request->filled('student_profile_id')) {
            $query->where('student_profile_id', $request->integer('student_profile_id'));
        }

        $this->invoices->applyLedgerFilters($query, $request);

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function householdSummary(Request $request, ?StudentProfile $student): array
    {
        $query = $this->scopedQuery($request)->where('status', '!=', InvoiceStatus::Void->value);
        $invoices = (clone $query)->with($this->invoices->defaultRelations())->orderByDesc('id')->get();
        $payload = $this->summarize($query, $request);
        $payload['invoices'] = InvoiceResource::collection($invoices)->resolve();
        $payload['student_profile_id'] = $student?->id;
        $payload['student_name'] = $student?->fullName();
        $payload['admission_number'] = $student?->admission_number;

        $latest = $invoices->first();
        $payload['status'] = $this->rollStatus($invoices);
        $payload['fee_status'] = InvoiceStatus::fromFilter($payload['status'])?->feeStatus();
        $payload['fee_status_label'] = InvoiceStatus::fromFilter($payload['status'])?->feeStatusLabel();
        $payload['term_name'] = $payload['term_name'] ?? $latest?->term?->name;
        $payload['session_name'] = $payload['session_name'] ?? $latest?->academicSession?->name;

        return $payload;
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return array<string, mixed>
     */
    private function summarize(Builder $query, Request $request): array
    {
        $expected = (int) (clone $query)->sum('total_kobo');
        $collected = (int) (clone $query)->sum('paid_kobo');
        $outstanding = max(0, $expected - $collected);
        $invoiceCount = (int) (clone $query)->count();
        $paidInFull = (int) (clone $query)->where('status', InvoiceStatus::Paid->value)->count();
        $partial = (int) (clone $query)->where('status', InvoiceStatus::Partial->value)->count();
        $unpaid = (int) (clone $query)->where('status', InvoiceStatus::Unpaid->value)->count();

        $today = (int) Payment::query()
            ->where('status', PaymentStatus::Posted->value)
            ->whereDate('paid_at', Carbon::now('Africa/Lagos')->toDateString())
            ->whereIn('invoice_id', (clone $query)->select('id'))
            ->sum('amount_kobo');

        $term = null;
        $session = null;

        if ($request->filled('term_id')) {
            $term = Term::query()->with('academicSession')->find($request->integer('term_id'));
            $session = $term?->academicSession;
        } elseif ($request->filled('academic_session_id')) {
            $session = AcademicSession::query()->find($request->integer('academic_session_id'));
        } elseif (! $this->hasExplicitLedgerScope($request)) {
            $termId = SchoolSetting::query()->value('current_term_id');
            $term = $termId ? Term::query()->with('academicSession')->find($termId) : null;
            $session = $term?->academicSession;
        }

        return [
            'term_id' => $term?->id,
            'term_name' => $term?->name,
            'academic_session_id' => $session?->id,
            'session_name' => $session?->name,
            'invoice_count' => $invoiceCount,
            'students_with_fees' => $invoiceCount,
            'paid_in_full_count' => $paidInFull,
            'partially_paid_count' => $partial,
            'outstanding_count' => $unpaid,
            'collection_share' => $expected > 0 ? (int) round(($collected / $expected) * 100) : null,
            'expected_kobo' => $expected,
            'collected_kobo' => $collected,
            'outstanding_kobo' => $outstanding,
            'today_kobo' => $today,
            'expected_naira' => Money::toNaira($expected),
            'collected_naira' => Money::toNaira($collected),
            'outstanding_naira' => Money::toNaira($outstanding),
            'today_naira' => Money::toNaira($today),
            'expected' => Money::compactNaira($expected),
            'collected' => Money::compactNaira($collected),
            'outstanding' => Money::compactNaira($outstanding),
            'today' => Money::compactNaira($today),
        ];
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function rollStatus(Collection $invoices): string
    {
        if ($invoices->isEmpty()) {
            return InvoiceStatus::Unpaid->value;
        }

        $total = (int) $invoices->sum('total_kobo');
        $paid = (int) $invoices->sum('paid_kobo');

        if ($total > 0 && $paid >= $total) {
            return InvoiceStatus::Paid->value;
        }

        if ($paid > 0) {
            return InvoiceStatus::Partial->value;
        }

        return InvoiceStatus::Unpaid->value;
    }

    private function hasExplicitLedgerScope(Request $request): bool
    {
        return $request->filled('term_id')
            || $request->filled('academic_session_id')
            || $request->filled('status')
            || $request->filled('school_class_id')
            || $request->filled('class_section_id')
            || $request->filled('class_section_offering_id')
            || $request->filled('arm')
            || $request->filled('admission_number')
            || $request->filled('q')
            || $request->filled('student_profile_id')
            || $request->filled('due_from')
            || $request->filled('due_to')
            || $request->string('scope')->toString() === 'all';
    }

    private function assertHouseholdFees(mixed $user, StudentProfile $student): void
    {
        $this->authorize('viewAny', Invoice::class);

        if ($this->access->administers($user)) {
            return;
        }

        if ($this->access->isStudent($user) && (int) $user->studentProfile?->id === (int) $student->id) {
            return;
        }

        if ($this->access->isParent($user) && $this->access->linkedStudentIds($user)->contains($student->id)) {
            return;
        }

        throw new AuthorizationException;
    }

    private function assertLinkedStudent(int $studentId, $ids): void
    {
        if (! $ids->contains($studentId)) {
            throw new AuthorizationException;
        }
    }
}

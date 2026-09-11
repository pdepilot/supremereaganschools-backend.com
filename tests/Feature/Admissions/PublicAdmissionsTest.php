<?php

namespace Tests\Feature\Admissions;

use App\Enums\ApplicationStatus;
use App\Enums\EnquiryStatus;
use App\Enums\OnlinePaymentPurpose;
use App\Enums\OnlinePaymentStatus;
use App\Enums\RoleSlug;
use App\Mail\AdmissionApplicationReceivedMail;
use App\Models\AdmissionApplication;
use App\Models\ContactEnquiry;
use App\Models\Document;
use App\Models\OnlinePayment;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PublicAdmissionsTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_guest_can_submit_a_contact_enquiry(): void
    {
        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Mrs. Ngozi Eze',
            'phone' => '08031110011',
            'email' => 'ngozi@example.test',
            'subject' => 'Request a campus visit',
            'message' => 'I would like to visit before first-term admissions close.',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'urgent')
            ->assertJsonPath('data.name', 'Mrs. Ngozi Eze');

        $this->assertDatabaseHas('contact_enquiries', [
            'email' => 'ngozi@example.test',
            'status' => EnquiryStatus::Urgent->value,
        ]);
    }

    public function test_general_correspondence_is_unread_not_urgent(): void
    {
        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'PTA Secretariat',
            'phone' => '08030000000',
            'email' => 'pta@example.test',
            'subject' => 'General correspondence',
            'message' => 'Confirming the hall for Thursday’s briefing.',
        ])->assertCreated()->assertJsonPath('data.status', 'unread');
    }

    public function test_guest_starts_paystack_checkout_before_application_is_received(): void
    {
        $local = Storage::fake('local');
        $public = Storage::fake('public');
        $this->level(['name' => 'Junior Secondary', 'slug' => 'jss']);
        $this->academicSession(['name' => '2025/2026']);
        $this->fakePaystackInitialize();

        $photo = UploadedFile::fake()->image('passport.jpg', 200, 200);

        $this->post('/api/v1/admission-applications', array_merge($this->applicationPayload(), [
            'passportPhoto' => $photo,
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.reference', 'ADM-0001')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.level_name', 'Junior Secondary')
            ->assertJsonPath('data.amount_kobo', 500000)
            ->assertJsonStructure(['data' => ['authorization_url', 'payment_reference']]);

        $application = AdmissionApplication::query()->first();
        $this->assertNotNull($application);
        $this->assertSame(ApplicationStatus::PendingPayment, $application->status);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseHas('online_payments', [
            'purpose' => OnlinePaymentPurpose::AdmissionApplicationFee->value,
            'status' => OnlinePaymentStatus::Pending->value,
            'payable_type' => $application->getMorphClass(),
            'payable_id' => $application->id,
            'email' => 'ngozi@example.test',
            'amount_kobo' => 500000,
        ]);

        $document = Document::query()->first();
        $this->assertSame('local', $document->disk);
        $this->assertFalse(str_starts_with($document->path, 'public/'));
        $local->assertExists($document->path);
        $public->assertMissing($document->path);
    }

    public function test_successful_paystack_settlement_submits_application_and_emails_parent(): void
    {
        Mail::fake();
        $this->level(['name' => 'Junior Secondary', 'slug' => 'jss']);
        $this->academicSession(['name' => '2025/2026']);
        $this->fakePaystackInitialize();

        $response = $this->postJson('/api/v1/admission-applications', $this->applicationPayload())
            ->assertCreated();

        $paymentReference = $response->json('data.payment_reference');
        $this->fakePaystackVerify($paymentReference, 500000);

        $this->get('/payments/paystack/callback?reference='.$paymentReference)
            ->assertRedirect();

        $application = AdmissionApplication::query()->first();
        $this->assertSame(ApplicationStatus::Submitted, $application->status);
        $this->assertSame(OnlinePaymentStatus::Paid, OnlinePayment::query()->first()->status);

        Mail::assertSent(AdmissionApplicationReceivedMail::class, function (AdmissionApplicationReceivedMail $mail) use ($application) {
            return $mail->application->is($application)
                && $mail->hasTo('ngozi@example.test');
        });
    }

    public function test_public_submit_cannot_mass_assign_status_or_reference(): void
    {
        $this->fakePaystackInitialize();

        $this->postJson('/api/v1/admission-applications', array_merge($this->applicationPayload(), [
            'status' => 'admitted',
            'reference' => 'ADM-9999',
            'student_profile_id' => 1,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.reference', 'ADM-0001')
            ->assertJsonPath('data.student_profile_id', null);
    }

    public function test_missing_fields_return_the_error_envelope(): void
    {
        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Anon',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['phone', 'email', 'subject', 'message']);
    }

    public function test_guest_can_read_application_fee_pricing(): void
    {
        $this->getJson('/api/v1/admission-applications/fee')
            ->assertOk()
            ->assertJsonPath('data.amount_kobo', 500000)
            ->assertJsonPath('data.currency', 'NGN');
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationPayload(): array
    {
        return [
            'session' => '2025/2026',
            'level' => 'Secondary',
            'classApplied' => 'JSS 1',
            'entryTerm' => 'First Term',
            'surname' => 'Eze',
            'firstName' => 'Ifeanyi',
            'otherNames' => '',
            'gender' => 'Female',
            'dob' => '2014-03-12',
            'nationality' => 'Nigerian',
            'stateOfOrigin' => 'Imo',
            'lga' => 'Owerri North',
            'homeAddress' => '15 Spibat Road, Owerri',
            'parentName' => 'Mrs. Ngozi Eze',
            'relationship' => 'Mother',
            'parentPhone' => '08031110011',
            'parentEmail' => 'ngozi@example.test',
        ];
    }

    private function fakePaystackInitialize(): void
    {
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/test-admission',
                    'access_code' => 'ACCESS_ADM',
                    'reference' => 'ignored-by-server',
                ],
            ], 200),
        ]);
    }

    private function fakePaystackVerify(string $reference, int $amountKobo): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'amount' => $amountKobo,
                    'currency' => 'NGN',
                    'reference' => $reference,
                    'channel' => 'card',
                    'gateway_response' => 'Successful',
                    'paid_at' => now()->toIso8601String(),
                ],
            ], 200),
        ]);
    }
}

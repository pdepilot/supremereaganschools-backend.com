<?php

namespace Tests\Feature\Fees;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class FeesCatalogueTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_fee_types_and_structures(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $term = $this->termFor($session);

        $type = $this->actingAs($admin)->postJson('/api/v1/fee-types', [
            'name' => 'Tuition',
            'code' => 'tuition',
        ])->assertCreated()->assertJsonPath('data.code', 'TUITION');

        $this->actingAs($admin)->postJson('/api/v1/fee-structures', [
            'fee_type_id' => $type->json('data.id'),
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'amount' => 180000,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount_kobo', 18000000)
            ->assertJsonPath('data.amount_naira', 180000);
    }

    public function test_fee_structures_include_scope_and_can_be_revised(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $level = $this->level();
        $type = $this->feeType(['name' => 'Tuition', 'code' => 'TUI']);
        $structure = $this->feeStructure($type, $session, $term, [
            'level_id' => $level->id,
            'amount_kobo' => 15000000,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/fee-structures')
            ->assertOk()
            ->assertJsonPath('data.0.id', $structure->id)
            ->assertJsonPath('data.0.fee_type', 'Tuition')
            ->assertJsonPath('data.0.fee_type_code', 'TUI')
            ->assertJsonPath('data.0.level_name', 'Junior Secondary')
            ->assertJsonPath('data.0.amount_naira', 150000);

        $this->actingAs($admin)
            ->putJson('/api/v1/fee-structures/'.$structure->id, [
                'amount' => 165000,
            ])
            ->assertOk()
            ->assertJsonPath('data.amount_naira', 165000)
            ->assertJsonPath('data.level_name', 'Junior Secondary');
    }

    public function test_duplicate_fee_structure_for_the_same_scope_is_rejected(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $type = $this->feeType();
        $this->feeStructure($type, $session, $term);

        $this->actingAs($admin)->postJson('/api/v1/fee-structures', [
            'fee_type_id' => $type->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'amount_kobo' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('fee_type_id');
    }

    public function test_teacher_cannot_manage_the_fee_catalogue(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($teacher)->getJson('/api/v1/fee-types')->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/v1/fee-types', [
            'name' => 'ICT Fee',
            'code' => 'ICT',
        ])->assertForbidden();
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\RoleSlug;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SchoolHandoverResetCommand extends Command
{
    protected $signature = 'school:handover-reset
        {--force : Skip the confirmation prompt}
        {--wipe-cms : Also wipe news posts, events, and announcements (kept by default)}';

    protected $description = 'Wipe test pupils, staff, fees, sessions, and classroom data for school handover. Keeps portal admins, roles, classes, subjects, fee types, and news by default.';

    /**
     * Operational tables wiped in any order (foreign keys disabled).
     *
     * @var list<string>
     */
    private array $operationalTables = [
        'cbt_result_access',
        'cbt_result_checker_purchases',
        'paystack_webhook_events',
        'online_payments',
        'payment_allocations',
        'payments',
        'invoice_items',
        'invoices',
        'fee_structures',
        'cbt_exam_integrity_events',
        'cbt_sync_logs',
        'cbt_answers',
        'cbt_results',
        'cbt_attempts',
        'cbt_exam_assignments',
        'cbt_exam_question_options',
        'cbt_exam_questions',
        'cbt_exams',
        'attendance_corrections',
        'attendance_records',
        'assignment_submissions',
        'assignments',
        'learning_materials',
        'timetable_slots',
        'term_summaries',
        'term_results',
        'assessment_scores',
        'promotions',
        'messages',
        'conversation_participants',
        'conversations',
        'notifications',
        'outbound_mails',
        'admission_applications',
        'contact_enquiry_replies',
        'contact_enquiries',
        'class_teacher_assignments',
        'subject_teacher_assignments',
        'guardian_student',
        'enrollments',
        'subject_offerings',
        'class_section_offerings',
        'terms',
        'academic_sessions',
        'documents',
        'student_profiles',
        'guardian_profiles',
        'staff_profiles',
        'login_activities',
        'rbac_audit_logs',
        'sessions',
        'password_reset_tokens',
    ];

    /**
     * Optional CMS tables wiped unless --keep-cms.
     *
     * @var list<string>
     */
    private array $cmsTables = [
        'announcements',
        'events',
        'post_tag',
        'posts',
        'post_categories',
        'post_tags',
    ];

    public function handle(): int
    {
        $this->warn('This permanently deletes pupils, guardians, teachers, fees, invoices, sessions, CBT attempts, attendance, and class work.');
        $this->line('Kept: portal admin logins, roles/permissions, campuses, levels, classes, subjects, fee types, CBT question bank, result products, and news/events/announcements.');
        if ($this->option('wipe-cms')) {
            $this->warn('Also wiping news posts, events, and announcements (--wipe-cms).');
        }

        if (! $this->option('force') && ! $this->confirm('Wipe all operational test data for school handover?', false)) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();

        try {
            SchoolSetting::query()->update([
                'current_academic_session_id' => null,
                'current_term_id' => null,
            ]);

            $tables = $this->operationalTables;
            if ($this->option('wipe-cms')) {
                $tables = array_merge($tables, $this->cmsTables);
            }

            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::table($table)->delete();
                $this->line("Cleared {$table}");
            }

            $this->removeNonPortalUsers();
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->callSilent('db:seed', ['--class' => \Database\Seeders\NewsInsightsSeeder::class, '--force' => true]);
        $this->line('Restored news categories, tags, and resource hubs (no articles).');

        $this->info('Handover reset complete. Portal admins can sign in and enter real school data.');

        return self::SUCCESS;
    }

    private function removeNonPortalUsers(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $portalSlugs = array_map(
            fn (RoleSlug $role) => $role->value,
            RoleSlug::portalRoles(),
        );

        $keepIds = User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('slug', $portalSlugs))
            ->pluck('id')
            ->all();

        $query = User::query();
        if ($keepIds !== []) {
            $query->whereNotIn('id', $keepIds);
        }

        $removed = 0;
        foreach ($query->cursor() as $user) {
            if (Schema::hasTable('role_user')) {
                DB::table('role_user')->where('user_id', $user->id)->delete();
            }
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('user_id', $user->id)->delete();
            }
            $user->delete();
            $removed++;
        }

        $this->line("Removed {$removed} non-portal user account(s)");
    }
}

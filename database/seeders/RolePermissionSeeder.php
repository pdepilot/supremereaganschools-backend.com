<?php

namespace Database\Seeders;

use App\Enums\PermissionSlug;
use App\Enums\RoleSlug;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalogue = Permission::query()->pluck('id', 'slug');

        foreach ($this->matrix() as $roleSlug => $permissionSlugs) {
            $role = Role::query()->where('slug', $roleSlug)->first();

            if ($role === null) {
                continue;
            }

            $ids = collect($permissionSlugs)
                ->map(fn (string $slug) => $catalogue[$slug] ?? null)
                ->filter()
                ->values()
                ->all();

            $role->permissions()->sync($ids);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function matrix(): array
    {
        $all = array_map(
            fn (PermissionSlug $permission) => $permission->value,
            PermissionSlug::cases(),
        );

        $academicCore = [
            PermissionSlug::DeskView->value,
            PermissionSlug::StudentsView->value,
            PermissionSlug::StudentsCreate->value,
            PermissionSlug::StudentsEdit->value,
            PermissionSlug::StaffView->value,
            PermissionSlug::GuardiansView->value,
            PermissionSlug::AcademicsView->value,
            PermissionSlug::AcademicsManage->value,
            PermissionSlug::TimetableView->value,
            PermissionSlug::TimetableManage->value,
            PermissionSlug::AttendanceView->value,
            PermissionSlug::AttendanceManage->value,
            PermissionSlug::MarksView->value,
            PermissionSlug::MarksManage->value,
            PermissionSlug::NoticesView->value,
            PermissionSlug::NoticesManage->value,
            PermissionSlug::MessagesView->value,
            PermissionSlug::ReportsView->value,
            PermissionSlug::ReportsExport->value,
        ];

        return [
            RoleSlug::SchoolAdmin->value => $all,
            RoleSlug::Principal->value => array_values(array_unique([
                ...$academicCore,
                PermissionSlug::AdmissionsView->value,
                PermissionSlug::FeesView->value,
                PermissionSlug::PaymentsView->value,
                PermissionSlug::NewsView->value,
                PermissionSlug::ContactView->value,
                PermissionSlug::SettingsView->value,
            ])),
            RoleSlug::VicePrincipal->value => [
                PermissionSlug::DeskView->value,
                PermissionSlug::StudentsView->value,
                PermissionSlug::StudentsEdit->value,
                PermissionSlug::StaffView->value,
                PermissionSlug::AcademicsView->value,
                PermissionSlug::TimetableView->value,
                PermissionSlug::AttendanceView->value,
                PermissionSlug::AttendanceManage->value,
                PermissionSlug::MarksView->value,
                PermissionSlug::MarksManage->value,
                PermissionSlug::NoticesView->value,
                PermissionSlug::MessagesView->value,
                PermissionSlug::ReportsView->value,
            ],
            RoleSlug::ExaminationOfficer->value => [
                PermissionSlug::DeskView->value,
                PermissionSlug::StudentsView->value,
                PermissionSlug::AcademicsView->value,
                PermissionSlug::MarksView->value,
                PermissionSlug::MarksManage->value,
                PermissionSlug::AttendanceView->value,
                PermissionSlug::ReportsView->value,
                PermissionSlug::ReportsExport->value,
            ],
            RoleSlug::AdmissionsOfficer->value => [
                PermissionSlug::DeskView->value,
                PermissionSlug::StudentsView->value,
                PermissionSlug::StudentsCreate->value,
                PermissionSlug::StudentsEdit->value,
                PermissionSlug::GuardiansView->value,
                PermissionSlug::GuardiansCreate->value,
                PermissionSlug::GuardiansEdit->value,
                PermissionSlug::AdmissionsView->value,
                PermissionSlug::AdmissionsManage->value,
                PermissionSlug::ContactView->value,
                PermissionSlug::ContactManage->value,
            ],
            RoleSlug::ContentManager->value => [
                PermissionSlug::DeskView->value,
                PermissionSlug::NoticesView->value,
                PermissionSlug::NoticesManage->value,
                PermissionSlug::NewsView->value,
                PermissionSlug::NewsManage->value,
                PermissionSlug::EmailView->value,
                PermissionSlug::EmailManage->value,
                PermissionSlug::ContactView->value,
                PermissionSlug::ContactManage->value,
            ],
            RoleSlug::Accountant->value => [
                PermissionSlug::DeskView->value,
                PermissionSlug::StudentsView->value,
                PermissionSlug::FeesView->value,
                PermissionSlug::FeesManage->value,
                PermissionSlug::PaymentsView->value,
                PermissionSlug::PaymentsManage->value,
                PermissionSlug::ReportsView->value,
                PermissionSlug::ReportsExport->value,
            ],
        ];
    }
}

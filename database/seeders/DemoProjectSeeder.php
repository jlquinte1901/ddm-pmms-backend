<?php

namespace Database\Seeders;

use App\Enums\ProjectStatus;
use App\Enums\RoleName;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\User;
use App\Services\ProjectCategoryService;
use App\Services\ProjectService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Seeds demonstration PMMS projects for visual verification only.
 *
 * This seeder is intentionally NOT called by DatabaseSeeder. Run it on demand:
 *
 *     php artisan db:seed --class=DemoProjectSeeder
 *
 * Projects are created through ProjectService, so creation, progress,
 * pause, completion, and termination write project history and audit rows
 * exactly like the API does. Re-running the seeder only adds missing demo
 * records and never duplicates them.
 */
class DemoProjectSeeder extends Seeder
{
    private const string ENGINEER_EMAIL = 'engineer.demo@ced.com';

    private const string ENGINEER_PASSWORD = 'Engineer@12345';

    /**
     * @var list<string>
     */
    private const array CATEGORY_NAMES = [
        'Roads and Highways',
        'Buildings',
        'Drainage and Flood Control',
        'Water Supply',
        'Bridges',
    ];

    public function run(): void
    {
        $projects = app(ProjectService::class);
        $categories = app(ProjectCategoryService::class);

        $engineer = $this->demoEngineer();
        $admin = $this->superAdmin() ?? $engineer;

        $categoryIds = [];

        foreach (self::CATEGORY_NAMES as $name) {
            $category = ProjectCategory::query()->where('name', $name)->first()
                ?? $categories->create(['name' => $name], $admin);

            $categoryIds[$name] = $category->getKey();
        }

        $today = CarbonImmutable::today((string) config('app.timezone', 'Asia/Manila'));

        foreach ($this->demoProjects() as $spec) {
            if (Project::query()->withTrashed()->where('contract_number', $spec['contract_number'])->exists()) {
                continue;
            }

            $project = $projects->create([
                'project_category_id' => $categoryIds[$spec['category']],
                'implementation_year' => $spec['implementation_year'],
                'project_name' => $spec['project_name'],
                'contract_number' => $spec['contract_number'],
                'location' => $spec['location'],
                'region' => 'Region IV-A',
                'lgu' => 'City of Calamba',
                'department' => 'City Engineering Department',
                'implementing_office' => 'City Engineering Department',
                'project_manager' => $spec['project_manager'],
                'fund_source' => $spec['fund_source'],
                'contract_amount' => $spec['contract_amount'],
                'planned_start_date' => $today->addDays($spec['start_offset'])->toDateString(),
                'planned_finish_date' => $today->addDays($spec['finish_offset'])->toDateString(),
                'contractor' => $spec['contractor'],
                'remarks' => null,
            ], $engineer);

            $this->applyState($project, $spec, $projects, $engineer, $admin, $today);
        }
    }

    private function demoEngineer(): User
    {
        $user = User::query()->withTrashed()->firstOrNew([
            'email' => self::ENGINEER_EMAIL,
        ]);

        $user->forceFill([
            'full_name' => 'Demo Project Engineer',
            'section' => 'Project Monitoring',
            'password' => Hash::make(self::ENGINEER_PASSWORD),
            'is_active' => true,
            'email_verified_at' => now(),
            'deleted_at' => null,
        ]);

        $user->save();

        $guard = config('auth.defaults.guard', 'web');
        $role = Role::query()->firstOrCreate([
            'name' => RoleName::ProjectEngineer->value,
            'guard_name' => $guard,
        ]);

        $user->syncRoles([$role]);

        return $user;
    }

    private function superAdmin(): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', RoleName::SuperAdmin->value))
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function applyState(
        Project $project,
        array $spec,
        ProjectService $projects,
        User $engineer,
        User $admin,
        CarbonImmutable $today,
    ): void {
        $reportDate = $today->toDateString();

        switch ($spec['state']) {
            case 'critical':
                $projects->start($project, 'Demo data: works started.', $engineer);
                $projects->recordProgress($project, [
                    'report_date' => $reportDate,
                    'progress_percentage' => $spec['progress'],
                    'accomplishment' => 'Demo data: ongoing implementation.',
                    'remarks' => null,
                ], $engineer);
                break;

            case 'overdue':
                $projects->start($project, 'Demo data: works started.', $engineer);
                $projects->recordProgress($project, [
                    'report_date' => $reportDate,
                    'progress_percentage' => $spec['progress'],
                    'accomplishment' => 'Demo data: works behind the planned finish date.',
                    'remarks' => 'Demo data: delayed by weather and right-of-way issues.',
                ], $engineer);
                break;

            case 'paused':
                $projects->start($project, 'Demo data: works started.', $engineer);
                $projects->recordProgress($project, [
                    'report_date' => $reportDate,
                    'progress_percentage' => $spec['progress'],
                    'accomplishment' => 'Demo data: works partially completed.',
                    'remarks' => null,
                ], $engineer);
                $projects->pause(
                    $project,
                    ProjectStatus::Suspended,
                    'Demo data: suspended pending a revised program of works.',
                    $engineer,
                );
                break;

            case 'completed':
                $projects->start($project, 'Demo data: works started.', $engineer);
                $projects->recordProgress($project, [
                    'report_date' => $reportDate,
                    'progress_percentage' => 100,
                    'accomplishment' => 'Demo data: project completed.',
                    'remarks' => null,
                ], $engineer);
                break;

            case 'terminated':
                $projects->start($project, 'Demo data: works started.', $engineer);
                $projects->recordProgress($project, [
                    'report_date' => $reportDate,
                    'progress_percentage' => $spec['progress'],
                    'accomplishment' => 'Demo data: partial implementation before termination.',
                    'remarks' => null,
                ], $engineer);
                $projects->terminate(
                    $project,
                    'Demo data: terminated through an administer action.',
                    $admin,
                );
                break;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function demoProjects(): array
    {
        return [
            [
                'contract_number' => 'DEMO-2026-001',
                'project_name' => 'Concreting of Barangay San Isidro Access Road',
                'category' => 'Roads and Highways',
                'implementation_year' => 2026,
                'location' => 'Barangay San Isidro, Calamba City',
                'project_manager' => 'Engr. Maria Santos',
                'fund_source' => 'Local Development Fund',
                'contract_amount' => '12500000.00',
                'contractor' => 'Sta. Rosa Builders Inc.',
                'start_offset' => -30,
                'finish_offset' => 120,
                'state' => 'normal',
                'progress' => 0,
            ],
            [
                'contract_number' => 'DEMO-2026-002',
                'project_name' => 'Rehabilitation of Barangay Poblacion Drainage',
                'category' => 'Drainage and Flood Control',
                'implementation_year' => 2026,
                'location' => 'Barangay Poblacion, Calamba City',
                'project_manager' => 'Engr. Jose Ramos',
                'fund_source' => 'Local Development Fund',
                'contract_amount' => '8750000.00',
                'contractor' => 'Calamba Construction Corp.',
                'start_offset' => -120,
                'finish_offset' => 18,
                'state' => 'critical',
                'progress' => 60,
            ],
            [
                'contract_number' => 'DEMO-2026-003',
                'project_name' => 'Improvement of Barangay Real Road Shoulder',
                'category' => 'Roads and Highways',
                'implementation_year' => 2026,
                'location' => 'Barangay Real, Calamba City',
                'project_manager' => 'Engr. Maria Santos',
                'fund_source' => 'National Government',
                'contract_amount' => '15200000.00',
                'contractor' => 'Laguna Roadworks Co.',
                'start_offset' => -200,
                'finish_offset' => -12,
                'state' => 'overdue',
                'progress' => 80,
            ],
            [
                'contract_number' => 'DEMO-2026-004',
                'project_name' => 'Construction of Barangay Canlubang Water Tank',
                'category' => 'Water Supply',
                'implementation_year' => 2026,
                'location' => 'Barangay Canlubang, Calamba City',
                'project_manager' => 'Engr. Ana Dela Cruz',
                'fund_source' => 'Special Account',
                'contract_amount' => '4600000.00',
                'contractor' => 'Prime Waterworks Inc.',
                'start_offset' => -60,
                'finish_offset' => 200,
                'state' => 'paused',
                'progress' => 45,
            ],
            [
                'contract_number' => 'DEMO-2026-005',
                'project_name' => 'Construction of Barangay Bucal Barangay Hall',
                'category' => 'Buildings',
                'implementation_year' => 2026,
                'location' => 'Barangay Bucal, Calamba City',
                'project_manager' => 'Engr. Jose Ramos',
                'fund_source' => 'Local Development Fund',
                'contract_amount' => '22000000.00',
                'contractor' => 'South Luzon Builders Inc.',
                'start_offset' => -300,
                'finish_offset' => -45,
                'state' => 'completed',
                'progress' => 100,
            ],
            [
                'contract_number' => 'DEMO-2025-006',
                'project_name' => 'Rehabilitation of Barangay Mabato Bridge',
                'category' => 'Bridges',
                'implementation_year' => 2025,
                'location' => 'Barangay Mabato, Calamba City',
                'project_manager' => 'Engr. Ana Dela Cruz',
                'fund_source' => 'National Government',
                'contract_amount' => '6300000.00',
                'contractor' => 'Bridgeworks Philippines Inc.',
                'start_offset' => -500,
                'finish_offset' => -200,
                'state' => 'terminated',
                'progress' => 25,
            ],
        ];
    }
}

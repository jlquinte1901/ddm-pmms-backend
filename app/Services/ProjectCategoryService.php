<?php

namespace App\Services;

use App\Models\ProjectCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ProjectCategoryService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{name: string, is_active?: bool}  $data
     */
    public function create(array $data, User $actor): ProjectCategory
    {
        return DB::transaction(function () use ($data, $actor): ProjectCategory {
            $category = ProjectCategory::query()->create([
                'name' => $data['name'],
            ]);

            $category->forceFill([
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            $this->audit->record(
                module: 'pmms',
                action: 'category_created',
                auditable: $category,
                newValues: $this->snapshot($category),
                actor: $actor,
            );

            return $category;
        });
    }

    /**
     * @param  array{name?: string, is_active?: bool}  $data
     */
    public function update(ProjectCategory $category, array $data, User $actor): ProjectCategory
    {
        return DB::transaction(function () use ($category, $data, $actor): ProjectCategory {
            $category = ProjectCategory::query()
                ->whereKey($category->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $oldValues = $this->snapshot($category);

            $category->fill([
                'name' => $data['name'] ?? $category->name,
            ]);

            if (array_key_exists('is_active', $data)) {
                $category->forceFill(['is_active' => (bool) $data['is_active']]);
            }

            $category->save();

            $this->audit->record(
                module: 'pmms',
                action: 'category_updated',
                auditable: $category,
                oldValues: $oldValues,
                newValues: $this->snapshot($category),
                actor: $actor,
            );

            return $category->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ProjectCategory $category): array
    {
        return [
            'name' => $category->name,
            'is_active' => $category->is_active,
        ];
    }
}

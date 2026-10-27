<?php

namespace App\Services;

use App\Enums\DocumentCategoryScope;
use App\Models\DocumentCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class DocumentCategoryService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array{name: string, default_access_level: string, is_active?: bool}  $data
     */
    public function create(array $data, User $actor): DocumentCategory
    {
        return DB::transaction(function () use ($data, $actor): DocumentCategory {
            $category = DocumentCategory::query()->create([
                'name' => $data['name'],
                'scope' => DocumentCategoryScope::Ddm->value,
                'default_access_level' => $data['default_access_level'],
            ]);

            $category->forceFill([
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            $this->audit->record(
                module: 'ddm',
                action: 'category_created',
                auditable: $category,
                newValues: $this->snapshot($category),
                actor: $actor,
            );

            return $category;
        });
    }

    /**
     * @param  array{name?: string, default_access_level?: string, is_active?: bool}  $data
     */
    public function update(DocumentCategory $category, array $data, User $actor): DocumentCategory
    {
        return DB::transaction(function () use ($category, $data, $actor): DocumentCategory {
            $category = DocumentCategory::query()
                ->whereKey($category->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $oldValues = $this->snapshot($category);

            $category->fill([
                'name' => $data['name'] ?? $category->name,
                'default_access_level' => $data['default_access_level'] ?? $category->default_access_level->value,
            ]);

            if (array_key_exists('is_active', $data)) {
                $category->forceFill(['is_active' => (bool) $data['is_active']]);
            }

            $category->save();

            $this->audit->record(
                module: 'ddm',
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
    private function snapshot(DocumentCategory $category): array
    {
        return [
            'name' => $category->name,
            'scope' => $category->scope->value,
            'default_access_level' => $category->default_access_level->value,
            'is_active' => $category->is_active,
        ];
    }
}

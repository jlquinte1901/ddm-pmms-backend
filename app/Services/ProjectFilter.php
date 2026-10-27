<?php

namespace App\Services;

use App\Enums\ProjectIndicator;
use App\Enums\ProjectStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * One validated filter object shared by the project list, dashboard, and
 * reports. A PE never receives another PE's projects even if a filter is
 * manipulated.
 */
final readonly class ProjectFilter
{
    public function __construct(
        public ?int $implementationYear = null,
        public ?int $projectCategoryId = null,
        public ?ProjectStatus $status = null,
        public ?ProjectIndicator $indicator = null,
        public ?int $assignedPeId = null,
        public ?string $contractor = null,
        public ?string $fundSource = null,
        public ?string $region = null,
        public ?string $lgu = null,
        public ?string $q = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            implementationYear: isset($data['implementation_year']) ? (int) $data['implementation_year'] : null,
            projectCategoryId: isset($data['project_category_id']) ? (int) $data['project_category_id'] : null,
            status: isset($data['status']) ? ProjectStatus::from((string) $data['status']) : null,
            indicator: isset($data['indicator']) ? ProjectIndicator::from((string) $data['indicator']) : null,
            assignedPeId: isset($data['assigned_pe_id']) ? (int) $data['assigned_pe_id'] : null,
            contractor: self::stringOrNull($data['contractor'] ?? null),
            fundSource: self::stringOrNull($data['fund_source'] ?? null),
            region: self::stringOrNull($data['region'] ?? null),
            lgu: self::stringOrNull($data['lgu'] ?? null),
            q: self::stringOrNull($data['q'] ?? null),
        );
    }

    public function apply(Builder $query, CarbonImmutable $today, bool $allowAssignedPeFilter = true): Builder
    {
        return $query
            ->when(
                $this->implementationYear !== null,
                fn (Builder $query): Builder => $query->where('implementation_year', $this->implementationYear),
            )
            ->when(
                $this->projectCategoryId !== null,
                fn (Builder $query): Builder => $query->where('project_category_id', $this->projectCategoryId),
            )
            ->when(
                $this->status !== null,
                fn (Builder $query): Builder => $query->where('status', $this->status),
            )
            ->when(
                $this->indicator !== null,
                fn (Builder $query): Builder => $query->indicator($this->indicator, $today),
            )
            ->when(
                $allowAssignedPeFilter && $this->assignedPeId !== null,
                fn (Builder $query): Builder => $query->where('assigned_pe_id', $this->assignedPeId),
            )
            ->when(
                $this->contractor !== null,
                fn (Builder $query): Builder => $query->where('contractor', 'like', '%'.$this->contractor.'%'),
            )
            ->when(
                $this->fundSource !== null,
                fn (Builder $query): Builder => $query->where('fund_source', 'like', '%'.$this->fundSource.'%'),
            )
            ->when(
                $this->region !== null,
                fn (Builder $query): Builder => $query->where('region', 'like', '%'.$this->region.'%'),
            )
            ->when(
                $this->lgu !== null,
                fn (Builder $query): Builder => $query->where('lgu', 'like', '%'.$this->lgu.'%'),
            )
            ->when($this->q !== null, function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('project_name', 'like', '%'.$this->q.'%')
                        ->orWhere('contract_number', 'like', '%'.$this->q.'%')
                        ->orWhere('location', 'like', '%'.$this->q.'%')
                        ->orWhere('contractor', 'like', '%'.$this->q.'%');
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'implementation_year' => $this->implementationYear,
            'project_category_id' => $this->projectCategoryId,
            'status' => $this->status?->value,
            'indicator' => $this->indicator?->value,
            'assigned_pe_id' => $this->assignedPeId,
            'contractor' => $this->contractor,
            'fund_source' => $this->fundSource,
            'region' => $this->region,
            'lgu' => $this->lgu,
            'q' => $this->q,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

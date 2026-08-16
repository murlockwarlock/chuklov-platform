<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $eval_suite_id
 * @property string $name
 * @property array<string, mixed> $test_inputs
 * @property array<string, mixed>|null $expected_output_schema
 * @property array<string, mixed> $expected_assertions
 * @property bool $is_active
 * @property-read Organization $organization
 * @property-read AiEvalSuite $suite
 */
#[Fillable([
    'organization_id',
    'eval_suite_id',
    'name',
    'test_inputs',
    'expected_output_schema',
    'expected_assertions',
    'is_active',
])]
class AiEvalCase extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiEvalSuite, $this> */
    public function suite(): BelongsTo
    {
        return $this->belongsTo(AiEvalSuite::class, 'eval_suite_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected function casts(): array
    {
        return [
            'test_inputs' => 'array',
            'expected_output_schema' => 'array',
            'expected_assertions' => 'array',
            'is_active' => 'boolean',
        ];
    }
}

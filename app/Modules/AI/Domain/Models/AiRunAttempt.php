<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\BudgetReservationStatus;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $ai_run_id
 * @property int $attempt_number
 * @property string $provider
 * @property string $model
 * @property int|null $model_release_id
 * @property int|null $credential_id
 * @property string|null $credential_revision
 * @property string|null $worker_lease_token
 * @property string $status
 * @property string|null $retry_or_failover_reason
 * @property int $latency_ms
 * @property array<string, mixed> $token_usage
 * @property int $reserved_cost_minor_units
 * @property CarbonInterface $budget_usage_date
 * @property BudgetReservationStatus $budget_reservation_status
 * @property int|null $settled_estimated_cost_minor_units
 * @property int|null $provider_cost_minor_units
 * @property array<string, mixed> $pricing_snapshot
 * @property AiErrorCategory|null $error_category
 * @property string|null $error_message_sanitized
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property-read Organization $organization
 * @property-read AiRun $run
 * @property-read AiModelRelease|null $modelRelease
 * @property-read OrganizationCredential|null $credential
 */
#[Fillable([
    'organization_id',
    'ai_run_id',
    'attempt_number',
    'provider',
    'model',
    'model_release_id',
    'credential_id',
    'credential_revision',
    'worker_lease_token',
    'status',
    'retry_or_failover_reason',
    'latency_ms',
    'token_usage',
    'reserved_cost_minor_units',
    'budget_usage_date',
    'budget_reservation_status',
    'settled_estimated_cost_minor_units',
    'provider_cost_minor_units',
    'pricing_snapshot',
    'error_category',
    'error_message_sanitized',
    'started_at',
    'finished_at',
])]
class AiRunAttempt extends Model
{
    protected $attributes = [
        'status' => 'running',
        'latency_ms' => 0,
        'token_usage' => '[]',
        'reserved_cost_minor_units' => 0,
        'budget_reservation_status' => 'reserved',
        'pricing_snapshot' => '[]',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /** @return BelongsTo<AiModelRelease, $this> */
    public function modelRelease(): BelongsTo
    {
        return $this->belongsTo(AiModelRelease::class, 'model_release_id');
    }

    /** @return BelongsTo<OrganizationCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(OrganizationCredential::class, 'credential_id');
    }

    public function getTokenUsage(): AiTokenUsage
    {
        return AiTokenUsage::fromArray($this->token_usage ?? []);
    }

    public function getPricingSnapshot(): AiPricingSnapshot
    {
        return AiPricingSnapshot::fromArray($this->pricing_snapshot ?? []);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'token_usage' => 'array',
            'pricing_snapshot' => 'array',
            'reserved_cost_minor_units' => 'integer',
            'settled_estimated_cost_minor_units' => 'integer',
            'provider_cost_minor_units' => 'integer',
            'budget_usage_date' => 'date',
            'budget_reservation_status' => BudgetReservationStatus::class,
            'error_category' => AiErrorCategory::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}

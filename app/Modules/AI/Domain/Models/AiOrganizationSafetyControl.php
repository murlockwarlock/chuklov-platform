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
 * @property bool $is_ai_globally_enabled
 * @property list<string> $disabled_capabilities
 * @property list<string> $disabled_providers
 * @property list<string> $disabled_tools
 * @property int $max_tokens_per_run
 * @property int $max_daily_spend_minor_units
 * @property int $max_runs_per_minute
 * @property int $max_tool_calls_per_run
 * @property int $default_timeout_seconds
 * @property int $max_failover_attempts
 * @property-read Organization $organization
 */
#[Fillable([
    'organization_id',
    'is_ai_globally_enabled',
    'disabled_capabilities',
    'disabled_providers',
    'disabled_tools',
    'max_tokens_per_run',
    'max_daily_spend_minor_units',
    'max_runs_per_minute',
    'max_tool_calls_per_run',
    'default_timeout_seconds',
    'max_failover_attempts',
])]
class AiOrganizationSafetyControl extends Model
{
    protected $attributes = [
        'is_ai_globally_enabled' => true,
        'disabled_capabilities' => '[]',
        'disabled_providers' => '[]',
        'disabled_tools' => '[]',
        'max_tokens_per_run' => 8192,
        'max_daily_spend_minor_units' => 5000,
        'max_runs_per_minute' => 60,
        'max_tool_calls_per_run' => 5,
        'default_timeout_seconds' => 60,
        'max_failover_attempts' => 3,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isCapabilityEnabled(string $capability): bool
    {
        if (! $this->is_ai_globally_enabled) {
            return false;
        }

        return ! in_array($capability, $this->disabled_capabilities ?? [], true);
    }

    public function isProviderEnabled(string $provider): bool
    {
        if (! $this->is_ai_globally_enabled) {
            return false;
        }

        return ! in_array($provider, $this->disabled_providers ?? [], true);
    }

    public function isToolEnabled(string $tool): bool
    {
        if (! $this->is_ai_globally_enabled) {
            return false;
        }

        return ! in_array($tool, $this->disabled_tools ?? [], true);
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
            'is_ai_globally_enabled' => 'boolean',
            'disabled_capabilities' => 'array',
            'disabled_providers' => 'array',
            'disabled_tools' => 'array',
            'max_tokens_per_run' => 'integer',
            'max_daily_spend_minor_units' => 'integer',
            'max_runs_per_minute' => 'integer',
            'max_tool_calls_per_run' => 'integer',
            'default_timeout_seconds' => 'integer',
            'max_failover_attempts' => 'integer',
        ];
    }
}

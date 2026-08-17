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
 * @property int $ai_run_id
 * @property string $worker_lease_token
 * @property int $call_index
 * @property string $tool_name
 * @property bool $is_read_only
 * @property string $input_digest
 * @property string $execution_status
 * @property int $latency_ms
 * @property string|null $error_sanitized
 * @property-read Organization $organization
 * @property-read AiRun $run
 */
#[Fillable([
    'organization_id',
    'ai_run_id',
    'worker_lease_token',
    'call_index',
    'tool_name',
    'is_read_only',
    'input_digest',
    'execution_status',
    'latency_ms',
    'error_sanitized',
])]
class AiRunToolCall extends Model
{
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
            'call_index' => 'integer',
            'is_read_only' => 'boolean',
            'latency_ms' => 'integer',
        ];
    }
}

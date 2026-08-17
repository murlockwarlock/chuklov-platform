<?php

namespace App\Modules\AI\Domain\Models;

use App\Models\User;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiParameterConfig;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $prompt_id
 * @property int $version
 * @property PromptVersionStatus $status
 * @property string $system_prompt
 * @property string $user_prompt_template
 * @property array<string, mixed> $variables_schema
 * @property array<string, mixed> $parameter_config
 * @property array<string, mixed> $context_policy
 * @property array<string, mixed>|null $output_schema
 * @property list<string> $allowed_tools
 * @property string|null $change_notes
 * @property CarbonInterface|null $activated_at
 * @property int|null $activated_by_user_id
 * @property-read Organization $organization
 * @property-read AiPrompt|null $prompt
 * @property-read User|null $activatedBy
 */
#[Fillable([
    'organization_id',
    'prompt_id',
    'version',
    'status',
    'system_prompt',
    'user_prompt_template',
    'variables_schema',
    'parameter_config',
    'context_policy',
    'output_schema',
    'allowed_tools',
    'change_notes',
    'activated_at',
    'activated_by_user_id',
])]
class AiPromptVersion extends Model
{
    protected $attributes = [
        'status' => 'draft',
        'variables_schema' => '[]',
        'parameter_config' => '[]',
        'context_policy' => '[]',
        'allowed_tools' => '[]',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiPrompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'prompt_id');
    }

    /** @return BelongsTo<User, $this> */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function getParameterConfig(): AiParameterConfig
    {
        return AiParameterConfig::fromArray($this->parameter_config ?? []);
    }

    public function getContextPolicy(): AiContextPolicy
    {
        return AiContextPolicy::fromArray($this->context_policy ?? []);
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
            'status' => PromptVersionStatus::class,
            'variables_schema' => 'array',
            'parameter_config' => 'array',
            'context_policy' => 'array',
            'output_schema' => 'array',
            'allowed_tools' => 'array',
            'activated_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}

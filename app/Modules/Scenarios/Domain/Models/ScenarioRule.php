<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use Database\Factories\ScenarioRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ScenarioEventType $trigger_event
 * @property ScenarioDelayUnit $delay_unit
 * @property ScenarioRulePurpose $purpose
 * @property bool $is_enabled
 * @property bool $system_managed
 * @property array<int, array<string, mixed>> $conditions
 * @property array<string, mixed> $recipient_strategy
 * @property list<string> $channel_priority
 * @property int $max_occurrences
 * @property ScenarioDelayUnit|null $repeat_interval_unit
 */
#[Fillable([
    'rule_key',
    'name',
    'trigger_event',
    'is_enabled',
    'system_managed',
    'delay_value',
    'delay_unit',
    'purpose',
    'conditions',
    'recipient_strategy',
    'channel_priority',
    'template_version_id',
    'max_occurrences',
    'repeat_interval_value',
    'repeat_interval_unit',
    'version',
])]
class ScenarioRule extends Model
{
    /** @use HasFactory<ScenarioRuleFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<NotificationTemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplateVersion::class, 'template_version_id');
    }

    /** @return HasMany<ScenarioAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ScenarioAction::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    protected static function newFactory(): ScenarioRuleFactory
    {
        return ScenarioRuleFactory::new();
    }

    protected function casts(): array
    {
        return [
            'trigger_event' => ScenarioEventType::class,
            'is_enabled' => 'boolean',
            'system_managed' => 'boolean',
            'delay_value' => 'integer',
            'delay_unit' => ScenarioDelayUnit::class,
            'purpose' => ScenarioRulePurpose::class,
            'conditions' => 'array',
            'recipient_strategy' => 'array',
            'channel_priority' => 'array',
            'max_occurrences' => 'integer',
            'repeat_interval_value' => 'integer',
            'repeat_interval_unit' => ScenarioDelayUnit::class,
            'version' => 'integer',
        ];
    }
}

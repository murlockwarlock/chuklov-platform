<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use Database\Factories\ScenarioActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ScenarioActionStatus $status
 * @property ScenarioEventType $trigger_event
 * @property ScenarioRulePurpose $purpose
 * @property array<string, mixed> $render_context
 * @property array<int, array<string, mixed>> $condition_snapshot
 * @property list<string> $channel_priority
 */
#[Fillable([
    'recipient_type',
    'client_id',
    'recipient_user_id',
    'template_version_id',
    'trigger_event',
    'rule_version',
    'condition_snapshot',
    'purpose',
    'channel_priority',
    'render_context',
    'materialization_key',
    'scheduled_for',
    'status',
    'terminal_reason',
])]
class ScenarioAction extends Model
{
    /** @use HasFactory<ScenarioActionFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ScenarioEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(ScenarioEvent::class, 'scenario_event_id');
    }

    /** @return BelongsTo<ScenarioRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ScenarioRule::class, 'scenario_rule_id');
    }

    /** @return BelongsTo<NotificationTemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplateVersion::class, 'template_version_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /** @return HasMany<ScenarioDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ScenarioDelivery::class);
    }

    protected static function newFactory(): ScenarioActionFactory
    {
        return ScenarioActionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => ScenarioActionStatus::class,
            'trigger_event' => ScenarioEventType::class,
            'purpose' => ScenarioRulePurpose::class,
            'channel_priority' => 'array',
            'render_context' => 'array',
            'condition_snapshot' => 'array',
            'scheduled_for' => 'datetime',
            'processing_started_at' => 'datetime',
            'delivered_at' => 'datetime',
            'suppressed_at' => 'datetime',
            'attempt_count' => 'integer',
            'rule_version' => 'integer',
        ];
    }
}

<?php

namespace App\Modules\Scenarios\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioDelayUnit;
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
 * @property array<int, array<string, mixed>>|bool|float|int|string|null $condition_snapshot
 * @property list<string> $channel_priority
 * @property int $sequence_number
 * @property int $max_occurrences
 * @property ScenarioDelayUnit|null $repeat_interval_unit
 */
#[Fillable([
    'recipient_type',
    'client_id',
    'recipient_user_id',
    'appointment_reminder_id',
    'booking_id',
    'booking_starts_at',
    'kind',
    'template_version_id',
    'trigger_event',
    'rule_version',
    'condition_snapshot',
    'sequence_number',
    'max_occurrences',
    'repeat_interval_value',
    'repeat_interval_unit',
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

    /** @return BelongsTo<AppointmentReminder, $this> */
    public function appointmentReminder(): BelongsTo
    {
        return $this->belongsTo(AppointmentReminder::class);
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
            'sequence_number' => 'integer',
            'max_occurrences' => 'integer',
            'repeat_interval_value' => 'integer',
            'repeat_interval_unit' => ScenarioDelayUnit::class,
            'scheduled_for' => 'datetime',
            'processing_started_at' => 'datetime',
            'delivered_at' => 'datetime',
            'suppressed_at' => 'datetime',
            'attempt_count' => 'integer',
            'rule_version' => 'integer',
            'appointment_reminder_id' => 'integer',
            'booking_id' => 'integer',
            'booking_starts_at' => 'datetime',
        ];
    }
}

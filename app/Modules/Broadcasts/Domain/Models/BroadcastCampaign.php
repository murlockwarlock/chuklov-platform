<?php

namespace App\Modules\Broadcasts\Domain\Models;

use App\Models\User;
use App\Modules\Broadcasts\Domain\Enums\BroadcastCampaignState;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property BroadcastCampaignState $state
 * @property string $send_mode
 * @property string $audience_type
 * @property list<string> $channel_priority
 * @property list<array{key: string, operator: string, value: mixed}> $segment_definition
 * @property list<int> $selected_client_ids
 * @property string $message_mode
 * @property string|null $message_body
 * @property string $segment_summary
 * @property int|null $template_version_ru_id
 * @property int|null $template_version_en_id
 * @property int|null $audience_snapshot_id
 * @property int $draft_version
 * @property int $audience_count
 * @property int $sent_count
 * @property int $delivered_count
 * @property int $failed_count
 * @property int $suppressed_count
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $dispatch_started_at
 * @property int $dispatch_attempt_count
 * @property Carbon|null $next_dispatch_at
 * @property string|null $last_dispatch_error_code
 */
#[Fillable(['name', 'send_mode', 'audience_type', 'channel_priority', 'segment_definition', 'selected_client_ids', 'message_mode', 'message_body', 'segment_summary', 'template_version_ru_id', 'template_version_en_id', 'scheduled_at'])]
class BroadcastCampaign extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<NotificationTemplateVersion, $this> */
    public function russianTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplateVersion::class, 'template_version_ru_id');
    }

    /** @return BelongsTo<NotificationTemplateVersion, $this> */
    public function englishTemplateVersion(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplateVersion::class, 'template_version_en_id');
    }

    /** @return BelongsTo<BroadcastAudienceSnapshot, $this> */
    public function audienceSnapshot(): BelongsTo
    {
        return $this->belongsTo(BroadcastAudienceSnapshot::class, 'audience_snapshot_id');
    }

    /** @return HasMany<BroadcastRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class, 'campaign_id');
    }

    protected function casts(): array
    {
        return [
            'state' => BroadcastCampaignState::class,
            'audience_type' => 'string',
            'channel_priority' => 'array',
            'segment_definition' => 'array',
            'selected_client_ids' => 'array',
            'scheduled_at' => 'datetime',
            'dispatch_started_at' => 'datetime',
            'next_dispatch_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'draft_version' => 'integer',
            'audience_count' => 'integer',
            'sent_count' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'suppressed_count' => 'integer',
            'dispatch_attempt_count' => 'integer',
        ];
    }
}

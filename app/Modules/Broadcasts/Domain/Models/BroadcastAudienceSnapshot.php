<?php

namespace App\Modules\Broadcasts\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $campaign_id
 * @property int $version
 * @property list<array{key: string, operator: string, value: mixed}> $segment_definition
 * @property string $segment_summary
 * @property list<string> $channel_priority
 * @property int|null $template_version_ru_id
 * @property int|null $template_version_en_id
 * @property int $matched_count
 * @property int $eligible_count
 * @property int $suppressed_count
 */
class BroadcastAudienceSnapshot extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<BroadcastCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BroadcastCampaign::class, 'campaign_id');
    }

    /** @return HasMany<BroadcastRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class, 'snapshot_id');
    }

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Broadcast audience snapshots are immutable.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Broadcast audience snapshots are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'segment_definition' => 'array',
            'channel_priority' => 'array',
            'version' => 'integer',
            'matched_count' => 'integer',
            'eligible_count' => 'integer',
            'suppressed_count' => 'integer',
            'materialized_at' => 'datetime',
        ];
    }
}

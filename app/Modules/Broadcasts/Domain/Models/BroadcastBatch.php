<?php

namespace App\Modules\Broadcasts\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $campaign_id
 * @property int $snapshot_id
 * @property int $sequence
 * @property string $state
 * @property string|null $lease_token
 * @property Carbon|null $claimed_at
 */
class BroadcastBatch extends Model
{
    protected $guarded = [];

    /** @return HasMany<BroadcastRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class, 'batch_id');
    }

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'completed_at' => 'datetime', 'sequence' => 'integer'];
    }
}

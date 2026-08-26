<?php

namespace App\Modules\Broadcasts\Domain\Models;

use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastDeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** @return BelongsTo<BroadcastRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(BroadcastRecipient::class, 'recipient_id');
    }

    protected function casts(): array
    {
        return ['outcome' => NotificationDeliveryOutcome::class, 'attempted_at' => 'datetime'];
    }
}

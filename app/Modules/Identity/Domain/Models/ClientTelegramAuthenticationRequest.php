<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $token_hash
 * @property string $browser_session_hash
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $consumed_at
 * @property-read Organization $organization
 * @property-read Client|null $client
 * @property-read ClientChannelIdentity|null $channelIdentity
 */
class ClientTelegramAuthenticationRequest extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<ClientChannelIdentity, $this> */
    public function channelIdentity(): BelongsTo
    {
        return $this->belongsTo(ClientChannelIdentity::class, 'client_channel_identity_id');
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}

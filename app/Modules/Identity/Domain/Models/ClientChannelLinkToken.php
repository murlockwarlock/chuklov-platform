<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ClientChannelLinkTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Organization $organization
 * @property-read Client $client
 * @property string $channel
 * @property string $flow
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
#[Fillable(['channel', 'flow'])]
class ClientChannelLinkToken extends Model
{
    /** @use HasFactory<ClientChannelLinkTokenFactory> */
    use HasFactory;

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

    protected static function newFactory(): ClientChannelLinkTokenFactory
    {
        return ClientChannelLinkTokenFactory::new();
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}

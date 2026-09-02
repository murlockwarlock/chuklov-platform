<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ClientChannelIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Client $client
 * @property-read Organization $organization
 * @property ChannelIdentityStatus $verification_status
 * @property string|null $external_username
 */
#[Fillable(['channel', 'external_id', 'external_username'])]
class ClientChannelIdentity extends Model
{
    /** @use HasFactory<ClientChannelIdentityFactory> */
    use HasFactory;

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function newFactory(): ClientChannelIdentityFactory
    {
        return ClientChannelIdentityFactory::new();
    }

    protected function casts(): array
    {
        return [
            'verification_status' => ChannelIdentityStatus::class,
            'verified_at' => 'datetime',
        ];
    }
}

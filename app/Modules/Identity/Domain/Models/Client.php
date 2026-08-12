<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Organization $organization
 * @property string|null $full_name
 */
#[Fillable(['full_name', 'email', 'phone', 'language', 'timezone', 'lead_source', 'referral_code'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ClientChannelIdentity, $this> */
    public function channelIdentities(): HasMany
    {
        return $this->hasMany(ClientChannelIdentity::class);
    }

    /** @return HasMany<ClientConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(ClientConsent::class);
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}

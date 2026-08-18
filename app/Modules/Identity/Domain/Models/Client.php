<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\ValueObjects\ClientPhoneSearchKey;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read Organization $organization
 * @property string|null $full_name
 * @property string|null $phone_search_key
 */
#[Fillable(['full_name', 'email', 'phone', 'language', 'timezone', 'lead_source', 'referral_code'])]
class Client extends Model
{
    protected $hidden = ['phone_search_key'];

    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $client): void {
            $client->phone_search_key = ClientPhoneSearchKey::from($client->phone)?->value;
        });
    }

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

    /** @return HasMany<ClientBookingRestriction, $this> */
    public function bookingRestrictions(): HasMany
    {
        return $this->hasMany(ClientBookingRestriction::class);
    }

    /** @return HasOne<ClientBookingRestriction, $this> */
    public function activeBookingRestriction(): HasOne
    {
        return $this->hasOne(ClientBookingRestriction::class)->whereNull('unblocked_at');
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** @return HasOne<MedicalProfile, $this> */
    public function medicalProfile(): HasOne
    {
        return $this->hasOne(MedicalProfile::class);
    }

    /** @return HasMany<MedicalAttachment, $this> */
    public function medicalAttachments(): HasMany
    {
        return $this->hasMany(MedicalAttachment::class);
    }

    /** @return HasMany<MedicalSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(MedicalSession::class);
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}

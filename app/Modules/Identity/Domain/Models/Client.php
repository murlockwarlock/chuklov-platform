<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Identity\Domain\ValueObjects\ClientPhoneSearchKey;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
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
 * @property string|null $language
 * @property string|null $timezone_source
 * @property int $organization_id
 */
#[Fillable(['full_name', 'email', 'phone', 'language', 'timezone', 'timezone_source', 'lead_source', 'referral_code'])]
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

    /** @return HasOne<ClientAttribution, $this> */
    public function attribution(): HasOne
    {
        return $this->hasOne(ClientAttribution::class, 'client_id');
    }

    /** @return HasOne<ClientReferralIdentity, $this> */
    public function referralIdentity(): HasOne
    {
        return $this->hasOne(ClientReferralIdentity::class, 'client_id');
    }

    /** @return HasOne<ReferralRelationship, $this> */
    public function referralRelationship(): HasOne
    {
        return $this->hasOne(ReferralRelationship::class, 'referred_client_id');
    }

    /** @return HasMany<ReferralRelationship, $this> */
    public function referredClients(): HasMany
    {
        return $this->hasMany(ReferralRelationship::class, 'referrer_client_id');
    }

    /** @return HasMany<FeedbackSubmission, $this> */
    public function feedbackSubmissions(): HasMany
    {
        return $this->hasMany(FeedbackSubmission::class);
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

    /** @return HasMany<SurveyAttempt, $this> */
    public function surveyAttempts(): HasMany
    {
        return $this->hasMany(SurveyAttempt::class);
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}

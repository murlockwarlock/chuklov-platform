<?php

namespace App\Modules\Scheduling\Domain\Models;

use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\ValueObjects\ProviderAccountAffinity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingSource;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Enums\PaymentRequirementType;
use App\Modules\Scheduling\Domain\Enums\PaymentStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\ValueObjects\InstantInterval;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read Organization $organization
 * @property-read Client $client
 * @property-read Specialist $specialist
 * @property-read Service $service
 * @property-read BookingStatus $status
 * @property-read PaymentStatus $payment_status
 * @property-read VisitFormat $visit_format
 * @property-read BookingSource $source
 * @property-read MeetingLinkMode|null $meeting_link_mode
 * @property-read string|null $provider_name
 * @property-read string|null $provider_account_id
 * @property-read string|null $provider_host_user_id
 * @property-read string|null $provider_meeting_id
 * @property-read string|null $provider_meeting_uuid
 * @property-read string|null $provider_join_url
 * @property-read VideoMeetingSyncStatus $provider_sync_status
 * @property-read VideoMeetingOperation|null $provider_operation
 * @property-read int $provider_sync_version
 * @property-read CarbonImmutable|null $provider_synced_at
 * @property-read string|null $provider_error_code
 * @property-read string|null $provider_correlation_key
 * @property-read string|null $provider_lease_token
 * @property-read CarbonImmutable|null $provider_lease_expires_at
 * @property-read int|null $provider_lease_event_id
 * @property-read string|null $provider_lease_processing_token
 * @property-read PaymentRequirementType|null $payment_requirement
 */
#[Fillable([
    'calendar_uid',
    'visit_format',
    'status',
    'payment_status',
    'payment_requirement',
    'payment_requirement_amount_minor',
    'payment_requirement_currency',
    'source',
    'starts_at',
    'ends_at',
    'blocking_ends_at',
    'schedule_timezone',
    'client_timezone',
    'location',
    'meeting_link_mode',
    'meeting_url',
    'provider_name',
    'provider_account_id',
    'provider_host_user_id',
    'provider_meeting_id',
    'provider_meeting_uuid',
    'provider_join_url',
    'provider_sync_status',
    'provider_operation',
    'provider_sync_version',
    'provider_synced_at',
    'provider_error_code',
    'provider_correlation_key',
    'provider_lease_token',
    'provider_lease_expires_at',
    'provider_lease_event_id',
    'provider_lease_processing_token',
    'party_size',
    'event_version',
    'requested_at',
    'cancelled_at',
])]
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected $attributes = [
        'payment_status' => PaymentStatus::Unpaid->value,
        'event_version' => 1,
        'party_size' => 1,
    ];

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

    /** @return BelongsTo<Specialist, $this> */
    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasMany<BookingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(BookingEvent::class);
    }

    public function instantInterval(): InstantInterval
    {
        return InstantInterval::from($this->startsAtUtc(), $this->blockingEndsAtUtc());
    }

    public function startsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('starts_at'))->utc();
    }

    public function endsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('ends_at'))->utc();
    }

    public function effectiveMeetingUrl(): ?string
    {
        if ($this->visit_format !== VisitFormat::Online) {
            return null;
        }

        $url = $this->meeting_link_mode === MeetingLinkMode::Auto
            ? ($this->provider_sync_status === VideoMeetingSyncStatus::Ready ? $this->provider_join_url : null)
            : $this->meeting_url;

        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)) {
            return null;
        }

        return $url;
    }

    public function blockingEndsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('blocking_ends_at'))->utc();
    }

    protected static function newFactory(): BookingFactory
    {
        return BookingFactory::new();
    }

    protected function casts(): array
    {
        return [
            'visit_format' => VisitFormat::class,
            'status' => BookingStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_requirement' => PaymentRequirementType::class,
            'source' => BookingSource::class,
            'meeting_link_mode' => MeetingLinkMode::class,
            'provider_sync_status' => VideoMeetingSyncStatus::class,
            'provider_operation' => VideoMeetingOperation::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'blocking_ends_at' => 'datetime',
            'requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'provider_synced_at' => 'datetime',
            'provider_lease_expires_at' => 'datetime',
            'party_size' => 'integer',
            'payment_requirement_amount_minor' => 'integer',
            'event_version' => 'integer',
            'provider_sync_version' => 'integer',
            'provider_lease_event_id' => 'integer',
        ];
    }

    public function providerAccountAffinity(): ?ProviderAccountAffinity
    {
        $accountId = $this->getAttribute('provider_account_id');
        $hostUserId = $this->getAttribute('provider_host_user_id');

        if (! is_string($accountId)
            || trim($accountId) === ''
            || ! is_string($hostUserId)
            || trim($hostUserId) === '') {
            return null;
        }

        return new ProviderAccountAffinity(trim($accountId), trim($hostUserId));
    }

    public function providerIdentity(): ?VideoMeetingIdentity
    {
        $meetingId = $this->getAttribute('provider_meeting_id');
        if (! is_string($meetingId) || trim($meetingId) === '') {
            return null;
        }

        $uuid = $this->getAttribute('provider_meeting_uuid');

        return new VideoMeetingIdentity(
            meetingId: $meetingId,
            meetingUuid: is_string($uuid) && trim($uuid) !== '' ? $uuid : null,
            providerAccountAffinity: $this->providerAccountAffinity(),
        );
    }

    public function hasProviderLease(): bool
    {
        return $this->provider_lease_token !== null
            || $this->provider_lease_expires_at !== null
            || $this->provider_lease_event_id !== null
            || $this->provider_lease_processing_token !== null;
    }
}

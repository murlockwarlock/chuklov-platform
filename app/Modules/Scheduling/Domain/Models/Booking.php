<?php

namespace App\Modules\Scheduling\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingSource;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
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
 */
#[Fillable([
    'calendar_uid',
    'visit_format',
    'status',
    'payment_status',
    'source',
    'starts_at',
    'ends_at',
    'blocking_ends_at',
    'schedule_timezone',
    'client_timezone',
    'location',
    'meeting_link_mode',
    'meeting_url',
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
            'source' => BookingSource::class,
            'meeting_link_mode' => MeetingLinkMode::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'blocking_ends_at' => 'datetime',
            'requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'party_size' => 'integer',
            'event_version' => 'integer',
        ];
    }
}

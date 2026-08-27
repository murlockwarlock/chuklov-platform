<?php

namespace App\Modules\B2B\Domain\Models;

use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Factories\B2bSalesCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $organization_id
 * @property int $lead_id
 * @property int $client_id
 * @property int $specialist_id
 * @property B2bSalesCallStatus $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $schedule_timezone
 * @property string $requested_timezone
 * @property VideoMeetingMode $meeting_mode
 * @property string|null $provider_name
 * @property string|null $provider_meeting_id
 * @property string|null $provider_meeting_uuid
 * @property string|null $provider_join_url
 * @property string|null $manual_meeting_url
 * @property VideoMeetingSyncStatus $provider_sync_status
 * @property VideoMeetingOperation|null $provider_operation
 * @property int $provider_sync_version
 * @property CarbonImmutable|null $provider_synced_at
 * @property string|null $provider_error_code
 * @property string|null $provider_recreate_meeting_id
 * @property string|null $provider_correlation_key
 * @property string|null $provider_lease_token
 * @property CarbonImmutable|null $provider_lease_expires_at
 * @property int|null $provider_lease_event_id
 * @property string|null $provider_lease_processing_token
 * @property int $event_version
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Organization $organization
 * @property-read B2bLead $lead
 * @property-read Client $client
 * @property-read Specialist $specialist
 * @property-read UnavailablePeriod|null $occupancyPeriod
 */
#[Fillable([])]
class B2bSalesCall extends Model
{
    /** @use HasFactory<B2bSalesCallFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<B2bLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(B2bLead::class, 'lead_id');
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

    /** @return HasOne<UnavailablePeriod, $this> */
    public function occupancyPeriod(): HasOne
    {
        return $this->hasOne(UnavailablePeriod::class, 'b2b_sales_call_id');
    }

    public function startsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('starts_at'))->utc();
    }

    public function endsAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('ends_at'))->utc();
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
            meetingUuid: is_string($uuid) && $uuid !== '' ? $uuid : null,
        );
    }

    protected function casts(): array
    {
        return [
            'status' => B2bSalesCallStatus::class,
            'meeting_mode' => VideoMeetingMode::class,
            'provider_sync_status' => VideoMeetingSyncStatus::class,
            'provider_operation' => VideoMeetingOperation::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'provider_synced_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'provider_lease_expires_at' => 'datetime',
            'provider_sync_version' => 'integer',
            'event_version' => 'integer',
            'provider_lease_event_id' => 'integer',
        ];
    }

    protected static function newFactory(): B2bSalesCallFactory
    {
        return B2bSalesCallFactory::new();
    }
}

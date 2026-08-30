<?php

namespace Database\Factories;

use App\Modules\B2B\Domain\Enums\B2bSalesCallStatus;
use App\Modules\B2B\Domain\Enums\VideoMeetingMode;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Enums\VideoMeetingSyncStatus;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<B2bSalesCall> */
class B2bSalesCallFactory extends Factory
{
    protected $model = B2bSalesCall::class;

    public function definition(): array
    {
        $start = now()->addDays(3)->setTime(14, 0);

        return [
            'status' => B2bSalesCallStatus::Scheduled->value,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinute(),
            'schedule_timezone' => 'UTC',
            'requested_timezone' => 'UTC',
            'meeting_mode' => VideoMeetingMode::Automatic->value,
            'provider_name' => 'zoom',
            'provider_meeting_id' => null,
            'provider_meeting_uuid' => null,
            'provider_join_url' => null,
            'manual_meeting_url' => null,
            'provider_sync_status' => VideoMeetingSyncStatus::Pending->value,
            'provider_operation' => VideoMeetingOperation::Create->value,
            'provider_sync_version' => 1,
            'provider_synced_at' => null,
            'provider_error_code' => null,
            'provider_recreate_meeting_id' => null,
            'provider_recreate_correlation_key' => null,
            'provider_correlation_key' => Str::random(32),
            'provider_lease_token' => null,
            'provider_lease_expires_at' => null,
            'provider_lease_event_id' => null,
            'provider_lease_processing_token' => null,
            'event_version' => 1,
            'cancelled_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (B2bSalesCall $call): B2bSalesCall => $call->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forLead(B2bLead $lead): static
    {
        return $this->afterMaking(fn (B2bSalesCall $call): B2bSalesCall => $call->forceFill([
            'organization_id' => $lead->organization_id,
            'lead_id' => $lead->getKey(),
            'client_id' => $lead->client_id,
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (B2bSalesCall $call): B2bSalesCall => $call->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }

    public function forSpecialist(Specialist $specialist): static
    {
        return $this->afterMaking(fn (B2bSalesCall $call): B2bSalesCall => $call->forceFill([
            'organization_id' => $specialist->organization_id,
            'specialist_id' => $specialist->getKey(),
        ]));
    }
}

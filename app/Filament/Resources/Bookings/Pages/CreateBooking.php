<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\CreateBooking as CreateBookingAction;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $context = app(OrganizationContext::class);
        $organizationId = $context->id();
        $client = Client::query()->where('organization_id', $organizationId)->findOrFail((int) $data['client_id']);
        $specialist = Specialist::query()->where('organization_id', $organizationId)->findOrFail((int) $data['specialist_id']);
        $service = Service::query()->where('organization_id', $organizationId)->findOrFail((int) $data['service_id']);
        $startsAt = $data['starts_at'] instanceof DateTimeInterface
            ? $data['starts_at']
            : CarbonImmutable::parse((string) $data['starts_at'], $context->organization()->defaultTimezone());

        return app(CreateBookingAction::class)->handle(
            actor: $actor,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: $startsAt,
            format: VisitFormat::from((string) $data['visit_format']),
            clientTimezone: null,
            meetingLinkMode: null,
            idempotencyKey: null,
            partySize: (int) ($data['party_size'] ?? 1),
            location: isset($data['location']) ? (string) $data['location'] : null,
        );
    }
}

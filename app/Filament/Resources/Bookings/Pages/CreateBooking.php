<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\CreateBooking as CreateBookingAction;
use App\Modules\Scheduling\Application\ResolveSpecialistViewerTimezone;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Создать запись на приём';

    #[Url(as: 'return_to_journal', history: true)]
    public bool $returnToJournal = false;

    #[Url(as: 'specialist_id', history: true, nullable: true)]
    public ?int $journalSpecialistId = null;

    #[Url(as: 'week', history: true, nullable: true)]
    public ?string $journalWeek = null;

    #[Url(as: 'view', history: true, nullable: true)]
    public ?string $journalView = null;

    public function mount(): void
    {
        parent::mount();

        $prefill = [];
        $organizationId = app(OrganizationContext::class)->id();
        $specialistId = request()->query('specialist_id');

        if (is_numeric($specialistId)) {
            $specialist = Specialist::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereKey((int) $specialistId)
                ->first();
            if ($specialist instanceof Specialist) {
                $prefill['specialist_id'] = $specialist->getKey();
            }
        }

        $startsAt = request()->query('starts_at');
        if (is_string($startsAt) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $startsAt) === 1) {
            $local = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i',
                $startsAt,
                $this->formTimezone(),
            );
            if ($local instanceof CarbonImmutable) {
                $prefill['starts_at'] = $local
                    ->setTimezone($this->formTimezone())
                    ->format('Y-m-d H:i');
            }
        }

        if ($prefill !== []) {
            $this->form->fillPartially($prefill, array_keys($prefill));
        }
    }

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
            : CarbonImmutable::parse(
                (string) $data['starts_at'],
                app(ResolveSpecialistViewerTimezone::class)->forUser($actor),
            );

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
            workingLocationId: isset($data['working_location_id']) && $data['working_location_id'] !== ''
                ? (int) $data['working_location_id']
                : null,
            locationArea: isset($data['location_area']) ? (string) $data['location_area'] : null,
        );
    }

    protected function getRedirectUrl(): string
    {
        if ($this->returnToJournal) {
            $week = $this->journalWeek;
            $parameters = [];
            if (is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1) {
                $parameters['week'] = $week;
            }
            if ($this->journalView === 'list') {
                $parameters['view'] = 'list';
            } else {
                $parameters['view'] = 'week';
            }
            $specialistId = $this->journalSpecialistId;
            if ($specialistId !== null && Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->where('is_active', true)
                ->whereKey($specialistId)
                ->exists()) {
                $parameters['specialist_id'] = $specialistId;
            }
            $url = ListBookings::getUrl();

            return $parameters === [] ? $url : $url.'?'.http_build_query($parameters);
        }

        return parent::getRedirectUrl();
    }

    private function formTimezone(): string
    {
        $actor = auth()->user();

        return $actor instanceof User
            ? app(ResolveSpecialistViewerTimezone::class)->forUser($actor)
            : app(OrganizationContext::class)->defaultTimezone();
    }
}

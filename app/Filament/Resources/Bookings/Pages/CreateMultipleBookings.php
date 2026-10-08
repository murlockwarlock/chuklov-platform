<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Schemas\BookingForm;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Application\CreateMultipleBookings as CreateMultipleBookingsAction;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Throwable;

class CreateMultipleBookings extends LocalizedCreateRecord
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Создать несколько записей';

    #[Url(as: 'return_to_journal', history: true)]
    public bool $returnToJournal = false;

    #[Url(as: 'specialist_id', history: true, nullable: true)]
    public ?int $journalSpecialistId = null;

    #[Url(as: 'week', history: true, nullable: true)]
    public ?string $journalWeek = null;

    #[Url(as: 'view', history: true, nullable: true)]
    public ?string $journalView = null;

    public string $batchIntentKey = '';

    public int $createdBookingCount = 0;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Создать записи'));
    }

    public function form(Schema $schema): Schema
    {
        return BookingForm::configure($schema, multiple: true)
            ->statePath('data')
            ->model($this->getModel())
            ->operation('create');
    }

    public function mount(): void
    {
        parent::mount();

        $this->batchIntentKey = (string) Str::uuid();

        $organizationId = app(OrganizationContext::class)->id();
        $specialist = $this->resolveSpecialist(request()->query('specialist_id'), $organizationId);
        if ($specialist instanceof Specialist) {
            $this->form->fillPartially(['specialist_id' => $specialist->getKey()], ['specialist_id']);
        }
    }

    public function create(bool $another = false): void
    {
        try {
            parent::create($another);

            if ($another) {
                $this->batchIntentKey = (string) Str::uuid();
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            $message = __('Не удалось создать записи. Проверьте клиента, услугу, даты и доступное время и попробуйте ещё раз.');
            $this->addError('data.slots', $message);
            Notification::make()
                ->danger()
                ->title(__('Не удалось создать записи'))
                ->body($message)
                ->send();
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        $organizationId = app(OrganizationContext::class)->id();
        $client = Client::query()->where('organization_id', $organizationId)->findOrFail((int) $data['client_id']);
        $specialist = Specialist::query()->where('organization_id', $organizationId)->findOrFail((int) $data['specialist_id']);
        $service = Service::query()->where('organization_id', $organizationId)->findOrFail((int) $data['service_id']);

        try {
            $bookings = app(CreateMultipleBookingsAction::class)->handle(
                actor: $actor,
                client: $client,
                specialist: $specialist,
                service: $service,
                format: VisitFormat::from((string) $data['visit_format']),
                slots: array_values(is_array($data['slots'] ?? null) ? $data['slots'] : []),
                batchIntentKey: $this->batchIntentKey,
                partySize: (int) ($data['party_size'] ?? 1),
                location: isset($data['location']) ? (string) $data['location'] : null,
                workingLocationId: isset($data['working_location_id']) && $data['working_location_id'] !== ''
                    ? (int) $data['working_location_id']
                    : null,
                locationArea: isset($data['location_area']) ? (string) $data['location_area'] : null,
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            if (isset($errors['slots'])) {
                $errors['data.slots'] = $errors['slots'];
                unset($errors['slots']);
            }

            throw ValidationException::withMessages($errors);
        }

        $this->createdBookingCount = count($bookings);

        return $bookings[0];
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Создано :count записей', ['count' => $this->createdBookingCount]);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->returnToJournal) {
            $week = $this->journalWeek;
            $parameters = [];
            if (is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1) {
                $parameters['week'] = $week;
            }
            $parameters['view'] = $this->journalView === 'list' ? 'list' : 'week';

            if ($this->journalSpecialistId !== null && Specialist::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->whereKey($this->journalSpecialistId)
                ->exists()) {
                $parameters['specialist_id'] = $this->journalSpecialistId;
            }

            $url = BookingResource::getUrl('index');

            return $url.'?'.http_build_query($parameters);
        }

        return BookingResource::getUrl('index');
    }

    private function resolveSpecialist(mixed $requestedId, int $organizationId): ?Specialist
    {
        $requestedSpecialistId = is_int($requestedId)
            ? $requestedId
            : (is_string($requestedId) && ctype_digit($requestedId) ? (int) $requestedId : null);

        if ($requestedSpecialistId !== null) {
            $requestedSpecialist = Specialist::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereKey($requestedSpecialistId)
                ->first();
            if ($requestedSpecialist instanceof Specialist) {
                return $requestedSpecialist;
            }
        }

        $actor = auth()->user();
        if ($actor instanceof User) {
            $viewerSpecialist = Specialist::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->where('staff_user_id', $actor->getKey())
                ->orderBy('display_name')
                ->orderBy('id')
                ->first();
            if ($viewerSpecialist instanceof Specialist) {
                return $viewerSpecialist;
            }
        }

        return Specialist::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->orderBy('id')
            ->first();
    }
}

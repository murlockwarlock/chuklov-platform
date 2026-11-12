<?php

namespace App\Filament\Resources\Clients\Resources\Sessions\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Resources\Sessions\MedicalSessionResource;
use App\Filament\Resources\Clients\Resources\Sessions\Schemas\ClinicalTestProtocol;
use App\Filament\Support\InteractsWithFormValidationFeedback;
use App\Filament\Support\LocalizedCreateRecord;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\GenerateSessionResult;
use App\Modules\Sessions\Application\MedicalSessionPainInitializer;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateMedicalSession extends LocalizedCreateRecord
{
    use InteractsWithFormValidationFeedback;

    protected static string $resource = MedicalSessionResource::class;

    protected static ?string $title = 'Новый сеанс';

    protected static ?string $breadcrumb = 'Новый сеанс';

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $data = [
            'test_results' => ClinicalTestProtocol::stateFor([]),
            'pains' => array_map(
                static fn (array $pain): array => [
                    ...$pain,
                    'before_score_ui' => self::scoreUiValue($pain['before_score'] ?? null),
                    'after_score_ui' => self::scoreUiValue($pain['after_score'] ?? null),
                ],
                app(MedicalSessionPainInitializer::class)->defaultsForClient($this->parentClient()),
            ),
        ];
        $bookingId = request()->query('booking_id');
        if (is_numeric($bookingId) && (int) $bookingId > 0) {
            $booking = Booking::query()
                ->where('organization_id', app(OrganizationContext::class)->id())
                ->whereKey((int) $bookingId)
                ->first();

            if ($booking instanceof Booking && $booking->clinicalClientId() === (int) $this->parentClient()->getKey()) {
                $data['booking_id'] = (int) $booking->getKey();
                $data['specialist_id'] = (int) $booking->specialist_id;
                $data['occurred_at'] = $booking->starts_at;
            }
        }

        $this->form->fill();
        $defaultState = $this->form->getRawState();
        $this->form->fill(is_array($defaultState) ? array_replace($defaultState, $data) : $data);

        $this->callHook('afterFill');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = $this->actor();
        $parent = $this->parentClient();
        $formState = $data;

        unset($data['pain_not_measured'], $data['pain_vas']);

        try {
            $command = CreateSessionCommand::fromArray($data);
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure($exception, $formState);
        }

        try {
            $result = app(CreateSession::class)->handle($actor, $parent, $command);

            return MedicalSession::query()
                ->where('organization_id', $result->organizationId)
                ->where('id', $result->id)
                ->firstOrFail();
        } catch (ValidationException $exception) {
            throw $this->formValidationFailure($exception, $formState);
        } catch (Throwable $exception) {
            throw $this->unexpectedFormFailure($exception, $formState);
        }
    }

    protected function getRedirectUrl(): string
    {
        $parent = $this->getParentRecord();

        return ClientResource::getUrl('sessions', ['record' => $parent], shouldGuessMissingParameters: true);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('Сеанс создан');
    }

    public function fillDraftResult(): string
    {
        $actor = $this->actor();
        $client = $this->parentClient();
        $draft = $this->form->getRawState();

        return app(GenerateSessionResult::class)->handleCreateDraft(
            $actor,
            $client,
            is_array($draft) ? $draft : $draft->toArray(),
        );
    }

    private function actor(): User
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

    private function parentClient(): Client
    {
        $parent = $this->getParentRecord();

        if (! $parent instanceof Client) {
            abort(404);
        }

        return $parent;
    }

    private static function scoreUiValue(mixed $value): int
    {
        return is_numeric($value) && (int) $value >= 0 && (int) $value <= 10 ? (int) $value : 0;
    }
}

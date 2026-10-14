<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attribution\Application\AcceptManualAttribution;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Application\RecordPortalClientConsents;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\CreateMultipleBookings;
use App\Modules\Scheduling\Application\UpdateClientTimezonePreference;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final readonly class CreatePortalBooking
{
    public function __construct(
        private ListPublishedLegalDocuments $legalDocuments,
        private RecordPortalClientConsents $recordConsents,
        private CreateBooking $createBooking,
        private CreateMultipleBookings $createMultipleBookings,
        private AcceptManualAttribution $acceptAttribution,
        private UpdateClientTimezonePreference $timezonePreference,
        private SaveBookingPersonalData $personalData,
    ) {}

    /**
     * @param  list<array{legal_document_id: int, granted: bool}>  $consents
     * @param  array<string, mixed>|null  $personalData
     */
    public function handle(
        Client $client,
        Specialist $specialist,
        Service $service,
        CarbonImmutable $startsAt,
        VisitFormat $format,
        array $consents,
        bool $marketingConsent,
        ?string $clientTimezone,
        int $partySize,
        ?string $location,
        ?int $workingLocationId = null,
        ?string $locationArea = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $mapUrl = null,
        ?string $attributionSource = null,
        ?string $attributionSourceDetail = null,
        ?array $personalData = null,
    ): Booking {
        return $this->createBooking->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: $startsAt,
            format: $format,
            clientTimezone: $clientTimezone,
            meetingLinkMode: null,
            idempotencyKey: null,
            partySize: $partySize,
            location: $location,
            workingLocationId: $workingLocationId,
            locationArea: $locationArea,
            latitude: $latitude,
            longitude: $longitude,
            mapUrl: $mapUrl,
            beforeCreate: function () use ($client, $clientTimezone, $consents, $marketingConsent, $attributionSource, $attributionSourceDetail, $personalData): void {
                $this->personalData->handle($client, $personalData);
                if ($clientTimezone !== null) {
                    $this->timezonePreference->handle($clientTimezone, $client);
                }

                $this->recordConsentsForBooking($client, $consents, $marketingConsent);

                if (filled($attributionSource)) {
                    $this->acceptAttribution->handle($client, $attributionSource, $attributionSourceDetail);
                }
            },
        );
    }

    /**
     * @param  list<string>  $startsAt
     * @param  list<array{legal_document_id: int, granted: bool}>  $consents
     * @param  array<string, mixed>|null  $personalData
     * @return list<Booking>
     */
    public function handleMultiple(
        Client $client,
        Specialist $specialist,
        Service $service,
        array $startsAt,
        VisitFormat $format,
        array $consents,
        bool $marketingConsent,
        ?string $clientTimezone,
        int $partySize,
        ?string $location,
        string $idempotencyKey,
        ?int $workingLocationId = null,
        ?string $locationArea = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $mapUrl = null,
        ?string $attributionSource = null,
        ?string $attributionSourceDetail = null,
        ?array $personalData = null,
    ): array {
        try {
            $timezone = IanaTimezone::from($clientTimezone ?? $client->timezone ?? 'UTC')->value;
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['clientTimezone' => __('Выберите корректный часовой пояс.')]);
        }
        $slots = array_map(static function (string $start) use ($timezone): array {
            $instant = CarbonImmutable::parse($start)->utc();

            return ['date' => $instant->setTimezone($timezone)->toDateString(), 'time' => $instant->toIso8601String()];
        }, $startsAt);

        return $this->createMultipleBookings->handle(
            actor: $client, client: $client, specialist: $specialist, service: $service,
            format: $format, slots: $slots, batchIntentKey: $idempotencyKey,
            partySize: $partySize, location: $location, workingLocationId: $workingLocationId,
            locationArea: $locationArea, clientTimezone: $clientTimezone,
            latitude: $latitude, longitude: $longitude, mapUrl: $mapUrl,
            beforeCreate: function () use ($client, $clientTimezone, $consents, $marketingConsent, $attributionSource, $attributionSourceDetail, $personalData): void {
                $this->personalData->handle($client, $personalData);
                if ($clientTimezone !== null) {
                    $this->timezonePreference->handle($clientTimezone, $client);
                }
                $this->recordConsentsForBooking($client, $consents, $marketingConsent);
                if (filled($attributionSource)) {
                    $this->acceptAttribution->handle($client, $attributionSource, $attributionSourceDetail);
                }
            },
        );
    }

    /** @param list<array{legal_document_id: int, granted: bool}> $consents */
    private function recordConsentsForBooking(Client $client, array $consents, bool $marketingConsent): void
    {
        $documents = $this->legalDocuments->handle($client->language);
        $this->ensureRequiredLegalDocumentsPublished($documents);
        $answers = $consents;
        if ($marketingConsent) {
            $marketingDocument = $documents
                ->first(fn (LegalDocument $document): bool => $document->document_type === ConsentSubject::Marketing->value);
            if ($marketingDocument !== null) {
                $answers[] = [
                    'legal_document_id' => (int) $marketingDocument->getKey(),
                    'granted' => true,
                ];
            }
        }

        $this->recordConsents->handle($client, $answers);
    }

    /** @param Collection<int, LegalDocument> $documents */
    private function ensureRequiredLegalDocumentsPublished(Collection $documents): void
    {
        $publishedSubjects = [];

        foreach ($documents as $document) {
            $subject = ConsentSubject::tryFrom((string) $document->document_type);

            if ($subject instanceof ConsentSubject) {
                $publishedSubjects[$subject->value] = true;
            }
        }

        $missingSubjects = [];

        foreach (ConsentSubject::cases() as $subject) {
            if ($subject->isRequired() && ! isset($publishedSubjects[$subject->value])) {
                $missingSubjects[] = $subject->label('en');
            }
        }

        if ($missingSubjects !== []) {
            throw ValidationException::withMessages([
                'legal_configuration' => 'Required legal documents are not configured or published: '.implode(', ', $missingSubjects).'.',
            ]);
        }
    }
}

<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Application\RecordPortalClientConsents;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CreatePortalBooking
{
    public function __construct(
        private ListPublishedLegalDocuments $legalDocuments,
        private RecordPortalClientConsents $recordConsents,
        private CreateBooking $createBooking,
    ) {}

    /** @param list<array{legal_document_id: int, granted: bool}> $consents */
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
    ): Booking {
        return DB::transaction(function () use ($client, $specialist, $service, $startsAt, $format, $consents, $marketingConsent, $clientTimezone, $partySize, $location): Booking {
            $documents = $this->legalDocuments->handle($client->language);
            $answers = $consents;
            if ($marketingConsent) {
                $marketingDocument = $documents
                    ->first(fn ($document): bool => $document->document_type === ConsentSubject::Marketing->value);
                if ($marketingDocument !== null) {
                    $answers[] = [
                        'legal_document_id' => (int) $marketingDocument->getKey(),
                        'granted' => true,
                    ];
                }
            }

            if ($documents->isNotEmpty()) {
                $this->recordConsents->handle($client, $answers);
            }

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
            );
        });
    }
}

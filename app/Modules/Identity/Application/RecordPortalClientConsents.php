<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Enums\LegalDocumentStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordPortalClientConsents
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param list<array{legal_document_id: int, granted: bool}> $consents */
    public function handle(Client $client, array $consents): void
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ClientRecords);

        $ids = array_map(
            static fn (array $consent): int => (int) $consent['legal_document_id'],
            $consents,
        );

        if ($ids !== array_values(array_unique($ids))) {
            throw ValidationException::withMessages(['consents' => 'Each legal document can be answered once.']);
        }

        $documents = LegalDocument::query()
            ->whereIn('id', $ids)
            ->where('status', LegalDocumentStatus::Published)
            ->where('organization_id', $organization->getKey())
            ->get()
            ->keyBy(fn (LegalDocument $document): int => $document->getKey());

        if ($documents->count() !== count($ids)) {
            throw ValidationException::withMessages(['consents' => 'The legal document selection is invalid.']);
        }

        $answers = [];

        foreach ($consents as $consent) {
            $documentId = (int) $consent['legal_document_id'];
            $document = $documents->get($documentId);

            if (! $document instanceof LegalDocument) {
                throw ValidationException::withMessages(['consents' => 'The legal document selection is invalid.']);
            }

            $subject = ConsentSubject::tryFrom($document->document_type);

            if (! $subject instanceof ConsentSubject) {
                throw ValidationException::withMessages(['consents' => 'The legal document purpose is not configured.']);
            }

            $answers[$documentId] = [
                'document' => $document,
                'subject' => $subject,
                'granted' => $consent['granted'],
            ];
        }

        foreach ($documents as $document) {
            if ($document->is_required && (($answers[$document->getKey()]['granted'] ?? false) !== true)) {
                throw ValidationException::withMessages([
                    'consents' => 'All required legal documents must be accepted.',
                ]);
            }
        }

        DB::transaction(function () use ($organization, $client, $answers): void {
            foreach ($answers as $answer) {
                /** @var LegalDocument $document */
                $document = $answer['document'];
                /** @var ConsentSubject $subject */
                $subject = $answer['subject'];
                $consent = new ClientConsent;
                $consent->forceFill([
                    'organization_id' => $organization->getKey(),
                    'client_id' => $client->getKey(),
                    'legal_document_id' => $document->getKey(),
                    'subject' => $subject,
                    'version' => $document->version,
                    'is_required' => $document->is_required,
                    'granted' => $answer['granted'],
                    'evidence' => 'portal',
                    'recorded_at' => now(),
                    'recorded_by_user_id' => null,
                ]);
                $consent->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: null,
                    action: 'client.consent.recorded',
                    targetType: ClientConsent::class,
                    targetId: (string) $consent->getKey(),
                    metadata: [
                        'subject' => $subject->value,
                        'version' => $document->version,
                        'granted' => $answer['granted'],
                        'evidence' => 'portal',
                    ],
                );
            }
        });
    }
}

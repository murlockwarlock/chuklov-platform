<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Support\RichText\RichTextDocument;
use Illuminate\Support\Collection;

final class GetClientProfile
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly ListPublishedLegalDocuments $legalDocuments,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $client = $this->clientContext->client();
        $documents = $this->legalDocuments->handle($this->locale($client->language));
        $consents = $this->consents($client, $documents);
        $marketingDocument = $documents->first(fn (LegalDocument $document): bool => $document->document_type === ConsentSubject::Marketing->value);
        $marketingConsent = $marketingDocument === null ? null : $consents->get($marketingDocument->getKey());
        $b2bProfile = BroadcastClientProfile::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->first();
        $b2bAnswer = $b2bProfile?->getRawOriginal('b2b_specialist_answer');

        return [
            'profile' => [
                'fullName' => $client->full_name,
                'email' => $client->email,
                'phone' => $client->phone,
                'timezone' => $client->timezone,
                'locale' => $this->locale($client->language),
                'emailEditable' => ! $client->channelIdentities()
                    ->where('channel', 'email')
                    ->where('verification_status', ChannelIdentityStatus::Verified)
                    ->exists(),
            ],
            'telegram' => [
                'connected' => $client->channelIdentities()
                    ->where('channel', 'telegram')
                    ->where('verification_status', ChannelIdentityStatus::Verified)
                    ->exists(),
            ],
            'b2bSpecialistAnswer' => $b2bAnswer,
            'legalDocuments' => $documents->map(function (LegalDocument $document) use ($consents): array {
                $consent = $consents->get($document->getKey());
                $subject = ConsentSubject::tryFrom($document->document_type);

                return [
                    'id' => $document->getKey(),
                    'documentType' => $document->document_type,
                    'title' => $subject?->label($this->locale($document->locale)) ?? $document->purpose,
                    'purpose' => $document->purpose,
                    'content' => $document->content,
                    'contentHtml' => RichTextDocument::canonicalHtml($document->content),
                    'version' => $document->version,
                    'isRequired' => $subject?->isRequired() ?? false,
                    'accepted' => $consent instanceof ClientConsent
                        && $consent->version === $document->version
                        && $consent->granted,
                ];
            })->values()->all(),
            'marketingConsent' => $marketingDocument === null ? null : [
                'documentId' => $marketingDocument->getKey(),
                'accepted' => $marketingConsent instanceof ClientConsent
                    && $marketingConsent->version === $marketingDocument->version
                    && $marketingConsent->granted,
            ],
            'timezoneOptions' => $this->timezoneOptions($client->timezone),
            'urls' => [
                'update' => route('portal.profile.update'),
                'consents' => route('portal.profile.consents'),
                'telegramLink' => route('portal.telegram.link'),
                'b2bAnswer' => route('portal.profile.b2b-answer'),
            ],
        ];
    }

    private function locale(?string $language): string
    {
        $normalized = strtolower((string) $language);

        if (str_starts_with($normalized, 'ru')) {
            return 'ru';
        }

        if (str_starts_with($normalized, 'en')) {
            return 'en';
        }

        $default = config('portal.default_locale', 'ru');

        return in_array($default, ['ru', 'en'], true) ? $default : 'ru';
    }

    /** @return list<array{value: string, label: string}> */
    public function timezoneOptions(?string $current = null): array
    {
        $timezones = timezone_identifiers_list();
        if ($current !== null && in_array($current, $timezones, true)) {
            $timezones = [$current, ...array_values(array_diff($timezones, [$current]))];
        }

        return array_map(static fn (string $timezone): array => [
            'value' => $timezone,
            'label' => $timezone,
        ], $timezones);
    }

    /**
     * @param  Collection<int, LegalDocument>  $documents
     * @return Collection<int, ClientConsent>
     */
    private function consents(Client $client, Collection $documents): Collection
    {
        $documentIds = $documents->pluck('id')->all();

        if ($documentIds === []) {
            return collect();
        }

        return ClientConsent::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->whereIn('legal_document_id', $documentIds)
            ->latest('id')
            ->get()
            ->unique('legal_document_id')
            ->keyBy('legal_document_id');
    }
}

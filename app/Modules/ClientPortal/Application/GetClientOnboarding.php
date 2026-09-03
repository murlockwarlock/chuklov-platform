<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Attribution\Application\GetClientAttribution;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientProfile;
use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Services\Application\ListPublishedServices;
use App\Support\RichText\RichTextDocument;

class GetClientOnboarding
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly StartClientOnboarding $startOnboarding,
        private readonly ListPublishedServices $services,
        private readonly ListPublishedLegalDocuments $legalDocuments,
        private readonly GetClientAttribution $getAttribution,
    ) {}

    /** @return array<string, mixed> */
    public function handle(): array
    {
        $client = $this->clientContext->client();
        $onboarding = $this->startOnboarding->handle($client);
        $profile = [
            'full_name' => $client->full_name,
            'email' => $client->email,
            'phone' => $client->phone,
        ];
        $b2bProfile = BroadcastClientProfile::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->first();
        $b2bAnswer = $b2bProfile?->getRawOriginal('b2b_specialist_answer');
        $verifiedFields = $client->channelIdentities()
            ->where('verification_status', ChannelIdentityStatus::Verified)
            ->get()
            ->mapWithKeys(static fn ($identity): array => [$identity->channel => true])
            ->keys()
            ->values()
            ->all();
        $legalDocuments = $this->legalDocuments->handle($client->language)->map(static function ($document): array {
            $subject = ConsentSubject::tryFrom($document->document_type);

            return [
                'id' => $document->getKey(),
                'documentType' => $document->document_type,
                'title' => $subject?->label(str_starts_with(strtolower((string) $document->locale), 'ru') ? 'ru' : 'en') ?? $document->purpose,
                'purpose' => $document->purpose,
                'content' => $document->content,
                'contentHtml' => RichTextDocument::canonicalHtml($document->content),
                'version' => $document->version,
                'isRequired' => $subject?->isRequired() ?? false,
            ];
        })->all();

        return [
            'currentStage' => $onboarding->current_stage->value,
            'stages' => array_map(
                static fn ($stage): string => $stage->value,
                ClientOnboardingStage::cases(),
            ),
            'profile' => $profile,
            'b2bSpecialistAnswer' => $b2bAnswer,
            'verifiedFields' => $verifiedFields,
            'completed' => $onboarding->completed_at !== null,
            'askLeadSource' => $this->getAttribution->handle($client) === null,
            'legalDocuments' => $legalDocuments,
            'services' => $this->services->handle()->map(static fn ($service): array => [
                'id' => $service->getKey(),
                'name' => $service->name,
                'summary' => $service->summary,
            ])->values()->all(),
        ];
    }
}

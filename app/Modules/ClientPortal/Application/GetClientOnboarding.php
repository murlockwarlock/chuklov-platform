<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\Identity\Application\ListPublishedLegalDocuments;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Services\Application\ListPublishedServices;

class GetClientOnboarding
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly StartClientOnboarding $startOnboarding,
        private readonly ListPublishedServices $services,
        private readonly ListPublishedLegalDocuments $legalDocuments,
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
            'language' => $client->language,
            'timezone' => $client->timezone,
            'lead_source' => $client->lead_source,
            'referral_code' => $client->referral_code,
        ];
        $knownFields = array_keys(array_filter($profile, static fn (mixed $value): bool => $value !== null && $value !== ''));
        $missingFields = array_keys(array_filter($profile, static fn (mixed $value): bool => $value === null || $value === ''));
        $verifiedFields = $client->channelIdentities()
            ->where('verification_status', ChannelIdentityStatus::Verified)
            ->get()
            ->mapWithKeys(static fn ($identity): array => [$identity->channel => true])
            ->keys()
            ->values()
            ->all();
        $legalDocuments = $this->legalDocuments->handle($client->language)->map(static fn ($document): array => [
            'id' => $document->getKey(),
            'documentType' => $document->document_type,
            'purpose' => $document->purpose,
            'locale' => $document->locale,
            'version' => $document->version,
            'content' => $document->content,
            'isRequired' => $document->is_required,
            'publishedAt' => $document->published_at?->toIso8601String(),
        ])->all();

        return [
            'flowVersion' => $onboarding->flow_version,
            'currentStage' => $onboarding->current_stage->value,
            'stages' => array_map(
                static fn ($stage): string => $stage->value,
                ClientOnboardingStage::cases(),
            ),
            'profile' => $profile,
            'knownFields' => $knownFields,
            'missingFields' => $missingFields,
            'verifiedFields' => $verifiedFields,
            'completed' => $onboarding->completed_at !== null,
            'blockedStages' => [],
            'legalDocuments' => $legalDocuments,
            'services' => $this->services->handle()->map(static fn ($service): array => [
                'id' => $service->getKey(),
                'name' => $service->name,
                'summary' => $service->summary,
            ])->values()->all(),
        ];
    }
}

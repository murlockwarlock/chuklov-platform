<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\Services\Application\ListPublishedServices;

class GetClientOnboarding
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly StartClientOnboarding $startOnboarding,
        private readonly ListPublishedServices $services,
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
            'blockedStages' => ['goals'],
            'services' => $this->services->handle()->map(static fn ($service): array => [
                'id' => $service->getKey(),
                'name' => $service->name,
                'summary' => $service->summary,
            ])->values()->all(),
        ];
    }
}

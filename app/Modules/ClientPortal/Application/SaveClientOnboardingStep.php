<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Application\RecordPortalClientConsents;
use App\Modules\Identity\Application\UpdateClientProfileFromPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveClientOnboardingStep
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly StartClientOnboarding $startOnboarding,
        private readonly UpdateClientProfileFromPortal $updateProfile,
        private readonly RecordPortalClientConsents $recordConsents,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $confirmedFields
     * @param  list<array{legal_document_id: int, granted: bool}>  $consents
     */
    public function handle(
        ClientOnboardingStage $stage,
        array $attributes,
        array $confirmedFields,
        array $consents = [],
    ): ClientOnboarding {
        $client = $this->clientContext->client();
        $onboarding = $this->startOnboarding->handle($client);

        if ($onboarding->current_stage !== $stage) {
            throw ValidationException::withMessages([
                'stage' => 'Complete the current onboarding stage first.',
            ]);
        }

        if ($onboarding->completed_at !== null) {
            throw ValidationException::withMessages([
                'stage' => 'This onboarding flow is already complete.',
            ]);
        }

        if ($stage !== ClientOnboardingStage::Contacts && $stage !== ClientOnboardingStage::Goals
            && ($attributes !== [] || $confirmedFields !== [] || $consents !== [])) {
            throw ValidationException::withMessages([
                'stage' => 'This onboarding stage does not accept profile fields.',
            ]);
        }

        if ($stage === ClientOnboardingStage::Goals && $attributes !== []) {
            throw ValidationException::withMessages([
                'stage' => 'This onboarding stage does not accept profile fields.',
            ]);
        }

        return DB::transaction(function () use ($stage, $attributes, $confirmedFields, $consents, $onboarding, $client): ClientOnboarding {
            if ($stage === ClientOnboardingStage::Contacts) {
                $this->updateProfile->handle($client, $attributes, $confirmedFields);
            }

            if ($stage === ClientOnboardingStage::Goals) {
                $this->recordConsents->handle($client, $consents);
            }

            $data = $onboarding->data ?? [];
            $data[$stage->value] = [
                'completed_at' => now()->toIso8601String(),
                'fields' => array_keys($attributes),
                'confirmed_fields' => $confirmedFields,
                'consents' => array_map(
                    static fn (array $consent): int => (int) $consent['legal_document_id'],
                    $consents,
                ),
            ];
            $nextStage = $stage->next();

            $onboarding->forceFill([
                'current_stage' => $nextStage ?? $stage,
                'data' => $data,
                'completed_at' => $nextStage === null ? now() : null,
            ]);
            $onboarding->save();

            return $onboarding->refresh();
        });
    }
}

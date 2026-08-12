<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Application\UpdateClientProfileFromPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveClientOnboardingStep
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly StartClientOnboarding $startOnboarding,
        private readonly UpdateClientProfileFromPortal $updateProfile,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $confirmedFields
     */
    public function handle(
        ClientOnboardingStage $stage,
        array $attributes,
        array $confirmedFields,
    ): ClientOnboarding {
        $client = $this->clientContext->client();
        $onboarding = $this->startOnboarding->handle($client);

        if ($onboarding->current_stage !== $stage) {
            throw ValidationException::withMessages([
                'stage' => 'Complete the current onboarding stage first.',
            ]);
        }

        if ($stage === ClientOnboardingStage::Goals) {
            throw ValidationException::withMessages([
                'goals' => 'Approved consent configuration is not available yet.',
            ]);
        }

        if ($stage !== ClientOnboardingStage::Contacts && ($attributes !== [] || $confirmedFields !== [])) {
            throw ValidationException::withMessages([
                'stage' => 'This onboarding stage does not accept profile fields.',
            ]);
        }

        return DB::transaction(function () use ($stage, $attributes, $confirmedFields, $onboarding, $client): ClientOnboarding {
            if ($stage === ClientOnboardingStage::Contacts) {
                $this->updateProfile->handle($client, $attributes, $confirmedFields);
            }

            $data = $onboarding->data ?? [];
            $data[$stage->value] = [
                'completed_at' => now()->toIso8601String(),
                'fields' => array_keys($attributes),
                'confirmed_fields' => $confirmedFields,
            ];
            $nextStage = $stage->next();

            if ($nextStage === null) {
                throw ValidationException::withMessages([
                    'stage' => 'This onboarding stage is not available yet.',
                ]);
            }

            $onboarding->forceFill([
                'current_stage' => $nextStage,
                'data' => $data,
            ]);
            $onboarding->save();

            return $onboarding->refresh();
        });
    }
}

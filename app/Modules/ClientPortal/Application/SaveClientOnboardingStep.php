<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Broadcasts\Application\SetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Application\RecordPortalClientConsents;
use App\Modules\Identity\Application\UpdateClientProfileFromPortal;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveClientOnboardingStep
{
    public function __construct(
        private readonly ClientPortalContext $clientContext,
        private readonly StartClientOnboarding $startOnboarding,
        private readonly UpdateClientProfileFromPortal $updateProfile,
        private readonly RecordPortalClientConsents $recordConsents,
        private readonly SetClientB2bSpecialistAnswer $setB2bAnswer,
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
        $b2bAnswer = null;

        if ($stage === ClientOnboardingStage::Contacts && array_key_exists('b2b_specialist_answer', $attributes)) {
            $rawAnswer = $attributes['b2b_specialist_answer'];
            $b2bAnswer = is_string($rawAnswer) ? B2bSpecialistAnswer::tryFrom($rawAnswer) : null;
            unset($attributes['b2b_specialist_answer']);

            if (! $b2bAnswer instanceof B2bSpecialistAnswer) {
                throw ValidationException::withMessages([
                    'b2b_specialist_answer' => 'Choose yes or no for the B2B specialist question.',
                ]);
            }
        }

        if ($stage === ClientOnboardingStage::Contacts) {
            $confirmedFields = $this->deriveConfirmedFields($client, $attributes, $confirmedFields);
        }

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

        return DB::transaction(function () use ($stage, $attributes, $confirmedFields, $consents, $onboarding, $client, $b2bAnswer): ClientOnboarding {
            if ($stage === ClientOnboardingStage::Contacts) {
                $this->updateProfile->handle($client, $attributes, $confirmedFields);
                if ($b2bAnswer instanceof B2bSpecialistAnswer) {
                    $this->setB2bAnswer->handle($client, $client, $b2bAnswer, 'portal');
                }
            }

            if ($stage === ClientOnboardingStage::Goals) {
                $this->recordConsents->handle($client, $consents);
            }

            $data = $onboarding->data ?? [];
            $fields = array_keys($attributes);
            if ($b2bAnswer instanceof B2bSpecialistAnswer) {
                $fields[] = 'b2b_specialist_answer';
            }
            $data[$stage->value] = [
                'completed_at' => now()->toIso8601String(),
                'fields' => $fields,
                'confirmed_fields' => $confirmedFields,
                'consents' => array_map(
                    static fn (array $consent): int => (int) $consent['legal_document_id'],
                    $consents,
                ),
                'b2b_specialist_answer' => $b2bAnswer?->value,
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

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $confirmedFields
     * @return list<string>
     */
    private function deriveConfirmedFields(Client $client, array $attributes, array $confirmedFields): array
    {
        foreach (array_keys($attributes) as $field) {
            if ($this->hasKnownValue($client->getAttribute($field))) {
                $confirmedFields[] = $field;
            }
        }

        return array_values(array_unique($confirmedFields));
    }

    private function hasKnownValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}

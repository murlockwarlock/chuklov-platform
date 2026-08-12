<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class StartClientOnboarding
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Client $client): ClientOnboarding
    {
        $organization = $this->context->organization();

        if ((int) $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        try {
            return $this->persist($client);
        } catch (UniqueConstraintViolationException) {
            return ClientOnboarding::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('flow_version', $this->flowVersion())
                ->firstOrFail();
        }
    }

    private function persist(Client $client): ClientOnboarding
    {
        $organization = $this->context->organization();

        return DB::transaction(function () use ($organization, $client): ClientOnboarding {
            $existing = ClientOnboarding::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('flow_version', $this->flowVersion())
                ->lockForUpdate()
                ->first();

            if ($existing instanceof ClientOnboarding) {
                return $existing;
            }

            $onboarding = new ClientOnboarding;
            $onboarding->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'flow_version' => $this->flowVersion(),
                'current_stage' => ClientOnboardingStage::Contacts,
                'data' => [],
            ]);
            $onboarding->save();

            return $onboarding->refresh();
        });
    }

    private function flowVersion(): string
    {
        return (string) config('portal.onboarding.version', 'm2-v1');
    }
}

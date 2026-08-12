<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use InvalidArgumentException;

class RecordClientConsent
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Client $client,
        ConsentSubject $subject,
        string $version,
        bool $granted,
        string $evidence,
    ): ClientConsent {
        $organization = $client->organization;
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::RecordConsent);

        $version = trim($version);
        $evidence = trim($evidence);

        if ($version === '' || mb_strlen($version) > 64) {
            throw new InvalidArgumentException('The consent version is invalid.');
        }

        if ($evidence === '' || mb_strlen($evidence) > 64 || preg_match('/^[a-z0-9._-]+$/', $evidence) !== 1) {
            throw new InvalidArgumentException('The consent evidence is invalid.');
        }

        $consent = new ClientConsent;
        $consent->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'subject' => $subject,
            'version' => $version,
            'is_required' => $subject->isRequired(),
            'granted' => $granted,
            'evidence' => $evidence,
            'recorded_at' => now(),
            'recorded_by_user_id' => $actor->getKey(),
        ]);
        $consent->save();

        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'client.consent.recorded',
            targetType: ClientConsent::class,
            targetId: (string) $consent->getKey(),
            metadata: [
                'subject' => $subject->value,
                'version' => $version,
                'granted' => $granted,
                'evidence' => $evidence,
            ],
        );

        return $consent->refresh();
    }
}

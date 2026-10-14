<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Application\PatientProfileAccess;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

final class AttachmentAuthorization
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
    ) {}

    public function organization(): Organization
    {
        return $this->context->organization();
    }

    public function authorizeUpload(User|Client $actor, Client $client, AttachmentType $type = AttachmentType::MedicalReport): Organization
    {
        if ($actor instanceof Client) {
            $organization = $this->organization();
            $this->features->authorize($organization, OrganizationFeature::ClientRecords);
            $this->assertClientOwned($actor, $organization);
            $this->assertClientOwned($client, $organization);
            if ($type !== AttachmentType::MedicalReport) {
                throw new AuthorizationException('This client may only upload their own medical reports.');
            }
            app(PatientProfileAccess::class)->authorizeClinicalClient($actor, $client);

            return $organization;
        }

        return $this->authorizeManage($actor, $client);
    }

    public function authorizeView(User $actor, Client $client): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->assertClientOwned($client, $organization);

        return $organization;
    }

    public function authorizeManage(User $actor, Client $client): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $this->assertClientOwned($client, $organization);

        return $organization;
    }

    public function authorizeDownload(User $actor, MedicalAttachment $attachment): Organization
    {
        $organization = $this->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->assertAttachmentOwned($attachment, $organization);

        return $organization;
    }

    public function authorizeAiProcessing(User $actor, MedicalAttachment $attachment, Organization $organization): void
    {
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->assertAttachmentOwned($attachment, $organization);
    }

    public function allowsDownload(User $actor, MedicalAttachment $attachment): bool
    {
        try {
            $organization = $this->organization();

            return (int) $attachment->organization_id === (int) $organization->getKey()
                && $this->authorizer->allows($actor, $organization, OrganizationPermission::ViewClients);
        } catch (LogicException) {
            return false;
        }
    }

    public function allowsView(User $actor, Client $client): bool
    {
        try {
            $organization = $this->organization();

            return (int) $client->organization_id === (int) $organization->getKey()
                && $this->authorizer->allows($actor, $organization, OrganizationPermission::ViewClients);
        } catch (LogicException) {
            return false;
        }
    }

    public function allowsUpload(User $actor, ?Client $client = null): bool
    {
        try {
            $organization = $this->organization();

            if ($client !== null && (int) $client->organization_id !== (int) $organization->getKey()) {
                return false;
            }

            return $this->authorizer->allows($actor, $organization, OrganizationPermission::ManageClients);
        } catch (LogicException) {
            return false;
        }
    }

    public function assertAttachmentOwned(MedicalAttachment $attachment, ?Organization $organization = null): void
    {
        $orgId = $organization !== null ? (int) $organization->getKey() : $this->context->id();

        if ((int) $attachment->organization_id !== $orgId) {
            throw new AuthorizationException('The attachment is outside the current organization.');
        }
    }

    public function assertClientOwned(Client $client, ?Organization $organization = null): void
    {
        $orgId = $organization !== null ? (int) $organization->getKey() : $this->context->id();

        if ((int) $client->organization_id !== $orgId) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }
}

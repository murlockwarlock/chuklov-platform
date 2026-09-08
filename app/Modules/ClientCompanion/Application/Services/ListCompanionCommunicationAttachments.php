<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Models\User;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListCompanionCommunicationAttachments
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    /**
     * @return Builder<MedicalAttachment>
     */
    public function query(User $actor, Client $client): Builder
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCompanionHandoff);

        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);

        return MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->whereIn('attachment_type', [
                AttachmentType::CompanionImage,
                AttachmentType::CompanionDocument,
            ])
            ->select([
                'id',
                'uuid',
                'organization_id',
                'attachment_type',
                'original_filename',
                'mime_type',
                'size_bytes',
                'created_at',
            ])
            ->latest('created_at')
            ->latest('id')
            ->limit(50);
    }

    /** @return array<int, string> */
    public function options(User $actor, Client $client): array
    {
        return $this->query($actor, $client)
            ->get()
            ->mapWithKeys(static fn (MedicalAttachment $attachment): array => [
                (int) $attachment->getKey() => $attachment->original_filename.' · '.$attachment->attachment_type->label(),
            ])
            ->all();
    }
}

<?php

namespace App\Modules\Attachments\Application;

use App\Models\User;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListClientAttachments
{
    public function __construct(private AttachmentAuthorization $authorization) {}

    /**
     * @return Builder<MedicalAttachment>
     */
    public function query(User $actor, Client $client): Builder
    {
        $organization = $this->authorization->authorizeView($actor, $client);

        return MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->select([
                'id',
                'uuid',
                'organization_id',
                'client_id',
                'attachment_type',
                'original_filename',
                'mime_type',
                'size_bytes',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}

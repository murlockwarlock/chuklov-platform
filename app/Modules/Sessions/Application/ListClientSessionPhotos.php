<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Domain\Enums\MedicalSessionAttachmentPlane;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListClientSessionPhotos
{
    public function __construct(private AttachmentAuthorization $authorization) {}

    /** @return Builder<MedicalAttachment> */
    public function query(User $actor, Client $client): Builder
    {
        $organization = $this->authorization->authorizeView($actor, $client);

        return MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('attachment_type', AttachmentType::PosturePhoto)
            ->whereHas('sessionLinks', fn (Builder $links): Builder => $links
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey()))
            ->with(['sessionLinks' => fn ($links) => $links
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->with('session:id,occurred_at')])
            ->select([
                'id',
                'uuid',
                'organization_id',
                'client_id',
                'attachment_type',
                'original_filename',
                'display_name',
                'mime_type',
                'size_bytes',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /** @return array<string, string> */
    public static function planeOptions(): array
    {
        return collect(MedicalSessionAttachmentPlane::cases())
            ->mapWithKeys(static fn (MedicalSessionAttachmentPlane $plane): array => [$plane->value => $plane->label()])
            ->all();
    }
}

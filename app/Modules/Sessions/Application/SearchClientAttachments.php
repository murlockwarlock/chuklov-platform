<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Database\Eloquent\Builder;

final readonly class SearchClientAttachments
{
    public function __construct(private AttachmentAuthorization $authorization) {}

    /** @return array<int, string> */
    public function handle(User $actor, Client $client, string $search, int $limit = 50): array
    {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $normalized = trim($search);

        return MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->when($normalized !== '', fn (Builder $query): Builder => $query->where('original_filename', 'like', '%'.$normalized.'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 50)))
            ->get(['id', 'original_filename', 'attachment_type', 'scan_status'])
            ->mapWithKeys(static fn (MedicalAttachment $attachment): array => [
                (int) $attachment->getKey() => $attachment->original_filename.' · '.$attachment->attachment_type->label().' · '.$attachment->scan_status->label(),
            ])
            ->all();
    }

    public function label(User $actor, Client $client, int $attachmentId): ?string
    {
        $organization = $this->authorization->authorizeManage($actor, $client);
        $attachment = MedicalAttachment::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->whereKey($attachmentId)
            ->first(['id', 'original_filename', 'attachment_type', 'scan_status']);

        return $attachment instanceof MedicalAttachment
            ? $attachment->original_filename.' · '.$attachment->attachment_type->label().' · '.$attachment->scan_status->label()
            : null;
    }
}

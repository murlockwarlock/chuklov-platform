<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Attachments\Application\AttachmentAuthorization;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final readonly class BuildClientSessionPhotoArchive
{
    public function __construct(
        private AttachmentAuthorization $authorization,
        private AttachmentStorageInterface $storage,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Client $client): string
    {
        $organization = $this->authorization->authorizeView($actor, $client);
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The ZIP archive extension is unavailable.');
        }

        $path = tempnam(sys_get_temp_dir(), 'chuklov-session-photos-');
        if (! is_string($path)) {
            throw new RuntimeException('The temporary archive could not be created.');
        }

        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            unlink($path);
            throw new RuntimeException('The photo archive could not be opened.');
        }

        try {
            $attachments = MedicalAttachment::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('attachment_type', AttachmentType::PosturePhoto)
                ->whereHas('sessionLinks', fn ($links) => $links
                    ->where('organization_id', $organization->getKey())
                    ->where('client_id', $client->getKey()))
                ->with(['sessionLinks' => fn ($links) => $links
                    ->where('organization_id', $organization->getKey())
                    ->where('client_id', $client->getKey())
                    ->with('session:id,occurred_at')])
                ->orderBy('id')
                ->get();

            foreach ($attachments as $attachment) {
                $stream = $this->storage->readStream($attachment->storage_path);
                $contents = stream_get_contents($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                if (! is_string($contents)) {
                    throw new RuntimeException('A photo could not be read from private storage.');
                }

                $link = $attachment->sessionLinks->first();
                $date = $link?->session?->occurred_at?->format('Y-m-d') ?? 'without-date';
                $moment = $link?->gallery?->value ?? 'photo';
                $plane = $link?->plane?->value ?? 'without-plane';
                $extension = pathinfo($attachment->original_filename, PATHINFO_EXTENSION) ?: 'bin';
                $name = $date.'/'.$moment.'-'.$plane.'-'.$attachment->getKey().'-'.Str::slug(pathinfo($attachment->original_filename, PATHINFO_FILENAME)).'.'.$extension;
                $archive->addFromString($name, $contents);
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'attachment.downloaded',
                    targetType: MedicalAttachment::class,
                    targetId: (string) $attachment->getKey(),
                    metadata: ['source' => 'crm_archive'],
                );
            }

            $archive->close();
        } catch (\Throwable $exception) {
            $archive->close();
            unlink($path);
            throw $exception;
        }

        return $path;
    }
}

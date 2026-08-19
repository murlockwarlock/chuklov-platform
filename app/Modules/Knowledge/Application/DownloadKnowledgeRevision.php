<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Application\Data\KnowledgeRevisionDownloadResult;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Exceptions\KnowledgeRevisionFileUnavailable;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class DownloadKnowledgeRevision
{
    public function __construct(
        private KnowledgeAuthorization $authorization,
    ) {}

    public function handle(User $actor, KnowledgeSource $source, KnowledgeRevision $revision): KnowledgeRevisionDownloadResult
    {
        $this->authorization->organizationForRevision($actor, $source, $revision, OrganizationPermission::ViewKnowledge);
        if ($source->type !== KnowledgeSourceType::UploadedText || $revision->storage_disk === null || $revision->storage_path === null) {
            throw new KnowledgeRevisionFileUnavailable;
        }

        try {
            $stream = Storage::disk($revision->storage_disk)->readStream($revision->storage_path);
        } catch (Throwable) {
            throw new KnowledgeRevisionFileUnavailable;
        }
        if (! is_resource($stream)) {
            throw new KnowledgeRevisionFileUnavailable;
        }

        return new KnowledgeRevisionDownloadResult(
            stream: $stream,
            filename: $this->safeFilename($revision->original_filename, $revision->mime_type),
            mimeType: $revision->mime_type,
            sizeBytes: $revision->size_bytes,
        );
    }

    private function safeFilename(?string $originalFilename, string $mimeType): string
    {
        $filename = basename(str_replace('\\', '/', (string) $originalFilename));
        $filename = preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', ' ', $filename) ?? '';
        $filename = trim(mb_substr($filename, 0, 180), " .\t\n\r\0\x0B");

        if ($filename !== '') {
            return $filename;
        }

        return $mimeType === 'text/plain' ? 'knowledge-document.txt' : 'knowledge-document.md';
    }
}

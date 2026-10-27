<?php

namespace App\Modules\Content\Application;

use App\Models\User;
use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AttachContentSubtitles
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ContentMediaStorageInterface $storage,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, ContentSection $section, UploadedFile $file): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageContent);
        if ((int) $section->organization_id !== $organization->getKey()) {
            throw new AuthorizationException;
        }
        $path = $this->storage->storeSubtitles($organization->getKey(), $file);
        try {
            DB::transaction(function () use ($actor, $organization, $section, $path): void {
                $locked = ContentSection::query()->where('organization_id', $organization->getKey())->whereKey($section->getKey())->lockForUpdate()->firstOrFail();
                $locked->media = [...($locked->media ?? []), 'subtitles' => $path];
                $locked->save();
                $this->audit->handle($organization, $actor, 'content.section.updated', ContentSection::class, (string) $locked->getKey(), [
                    'section_key' => $locked->section_key, 'locale' => $locked->locale, 'is_visible' => $locked->is_visible,
                ]);
            });
        } catch (Throwable $exception) {
            $this->storage->deleteManaged($organization->getKey(), $path);
            throw $exception;
        }
    }
}

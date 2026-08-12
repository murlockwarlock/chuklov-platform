<?php

namespace App\Modules\Content\Application;

use App\Models\User;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateContentSection
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, ContentSection $section, array $attributes): ContentSection
    {
        $organization = $this->context->organization();

        if ((int) $section->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The content section is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageContent);
        $data = ContentSectionData::from($attributes);

        return DB::transaction(function () use ($actor, $data, $organization, $section): ContentSection {
            $lockedSection = ContentSection::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($section->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $changedFields = [];

            foreach ($data->attributes() as $field => $value) {
                if ($lockedSection->getAttribute($field) !== $value) {
                    $changedFields[] = $field;
                }
            }

            $lockedSection->forceFill($data->attributes());
            $lockedSection->save();

            if ($changedFields !== []) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'content.section.updated',
                    targetType: ContentSection::class,
                    targetId: (string) $lockedSection->getKey(),
                    metadata: [
                        'section_key' => $data->sectionKey,
                        'locale' => $data->locale,
                        'is_visible' => $data->isVisible,
                    ],
                );
            }

            return $lockedSection->refresh();
        });
    }
}

<?php

namespace App\Modules\Content\Application;

use App\Models\User;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

class CreateContentSection
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(User $actor, array $attributes): ContentSection
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageContent);
        $data = ContentSectionData::from($attributes);

        return DB::transaction(function () use ($actor, $data, $organization): ContentSection {
            $section = new ContentSection;
            $section->forceFill([
                'organization_id' => $organization->getKey(),
                ...$data->attributes(),
            ]);
            $section->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'content.section.created',
                targetType: ContentSection::class,
                targetId: (string) $section->getKey(),
                metadata: [
                    'section_key' => $data->sectionKey,
                    'locale' => $data->locale,
                    'is_visible' => $data->isVisible,
                ],
            );

            return $section->refresh();
        });
    }
}

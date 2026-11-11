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

final class SetContentSectionVisibility
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, ContentSection $section, bool $isVisible): ContentSection
    {
        $organization = $this->context->organization();

        if ((int) $section->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The content section is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageContent);

        return DB::transaction(function () use ($actor, $organization, $section, $isVisible): ContentSection {
            $locked = ContentSection::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($section->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((bool) $locked->is_visible !== $isVisible) {
                $locked->forceFill(['is_visible' => $isVisible])->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'content.section.updated',
                    targetType: ContentSection::class,
                    targetId: (string) $locked->getKey(),
                    metadata: [
                        'section_key' => $locked->section_key,
                        'locale' => $locked->locale,
                        'is_visible' => $isVisible,
                    ],
                );
            }

            return $locked->refresh();
        });
    }
}

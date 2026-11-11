<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final class SetNotificationTemplateActive
{
    public function __construct(
        private readonly ScenarioAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, NotificationTemplate $template, bool $isActive): NotificationTemplate
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($template);

        return DB::transaction(function () use ($actor, $organization, $template, $isActive): NotificationTemplate {
            $locked = NotificationTemplate::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((bool) $locked->is_active !== $isActive) {
                $locked->forceFill(['is_active' => $isActive])->save();
                $latest = $locked->versions()->latest('version')->first();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'scenario.template.updated',
                    targetType: NotificationTemplate::class,
                    targetId: (string) $locked->getKey(),
                    metadata: [
                        'template_key' => $locked->template_key,
                        'locale' => $locked->locale,
                        'version' => $latest?->version ?? 0,
                    ],
                );
            }

            return $locked->refresh();
        });
    }
}

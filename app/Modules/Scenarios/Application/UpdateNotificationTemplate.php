<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\NotificationTemplateConfiguration;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class UpdateNotificationTemplate
{
    public function __construct(
        private readonly ScenarioAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, NotificationTemplate $template, array $data): NotificationTemplate
    {
        $organization = $this->authorization->authorizeManage($actor);
        $this->authorization->assertOwned($template);
        $configuration = NotificationTemplateConfiguration::from($data);

        if ($configuration->templateKey !== $template->template_key || $configuration->locale !== $template->locale) {
            throw new AuthorizationException('Template identity cannot change after creation.');
        }

        return DB::transaction(function () use ($actor, $configuration, $organization, $template): NotificationTemplate {
            $lockedTemplate = NotificationTemplate::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $latest = $lockedTemplate->versions()->latest('version')->firstOrFail();

            $lockedTemplate->forceFill([
                'name' => $configuration->name,
                'purpose' => $configuration->purpose->value,
                'is_active' => $configuration->isActive,
            ])->save();

            $changed = $latest->subject !== $configuration->subject
                || $latest->body !== $configuration->body
                || $latest->variables !== $configuration->variables;

            if ($changed) {
                $version = new NotificationTemplateVersion;
                $version->forceFill([
                    'organization_id' => $organization->getKey(),
                    'template_id' => $lockedTemplate->getKey(),
                    'version' => $latest->version + 1,
                    'status' => NotificationTemplateStatus::Published,
                    'subject' => $configuration->subject,
                    'body' => $configuration->body,
                    'variables' => $configuration->variables,
                    'created_by_user_id' => $actor->getKey(),
                    'published_at' => now(),
                ]);
                $version->save();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'scenario.template.updated',
                targetType: NotificationTemplate::class,
                targetId: (string) $lockedTemplate->getKey(),
                metadata: [
                    'template_key' => $lockedTemplate->template_key,
                    'locale' => $lockedTemplate->locale,
                    'version' => $changed ? $latest->version + 1 : $latest->version,
                ],
            );

            return $lockedTemplate->refresh();
        });
    }
}

<?php

namespace App\Modules\Scenarios\Application;

use App\Models\User;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\NotificationTemplateConfiguration;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateNotificationTemplate
{
    public function __construct(
        private readonly ScenarioAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(User $actor, array $data): NotificationTemplate
    {
        $organization = $this->authorization->authorizeManage($actor);
        $data['template_key'] ??= 'template-'.Str::uuid()->toString();
        $configuration = NotificationTemplateConfiguration::from($data);

        return DB::transaction(function () use ($actor, $configuration, $organization): NotificationTemplate {
            $template = new NotificationTemplate;
            $template->forceFill([
                'organization_id' => $organization->getKey(),
                'template_key' => $configuration->templateKey,
                'name' => $configuration->name,
                'locale' => $configuration->locale,
                'purpose' => $configuration->purpose->value,
                'is_active' => $configuration->isActive,
            ]);
            $template->save();

            $version = new NotificationTemplateVersion;
            $version->forceFill([
                'organization_id' => $organization->getKey(),
                'template_id' => $template->getKey(),
                'version' => 1,
                'status' => NotificationTemplateStatus::Published,
                'subject' => $configuration->subject,
                'body' => $configuration->body,
                'variables' => $configuration->variables,
                'created_by_user_id' => $actor->getKey(),
                'published_at' => now(),
            ]);
            $version->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'scenario.template.created',
                targetType: NotificationTemplate::class,
                targetId: (string) $template->getKey(),
                metadata: [
                    'template_key' => $configuration->templateKey,
                    'locale' => $configuration->locale,
                    'version' => 1,
                ],
            );

            return $template->refresh();
        });
    }
}

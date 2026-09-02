<?php

namespace App\Modules\Broadcasts\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioTemplateVariableCatalog;
use Illuminate\Support\Str;

final class CreateBroadcastMessageTemplate
{
    public function handle(User $actor, Organization $organization, string $campaignName, string $body): NotificationTemplateVersion
    {
        $variables = ScenarioTemplateVariableCatalog::used($body);
        $template = new NotificationTemplate;
        $template->forceFill([
            'organization_id' => $organization->getKey(),
            'template_key' => 'broadcast-campaign-'.Str::uuid()->toString(),
            'name' => mb_substr('Рассылка: '.trim($campaignName), 0, 160),
            'locale' => 'ru',
            'purpose' => ScenarioRulePurpose::Marketing->value,
            'is_active' => true,
        ])->save();

        $version = new NotificationTemplateVersion;
        $version->forceFill([
            'organization_id' => $organization->getKey(),
            'template_id' => $template->getKey(),
            'version' => 1,
            'status' => NotificationTemplateStatus::Published,
            'body' => $body,
            'variables' => $variables,
            'created_by_user_id' => $actor->getKey(),
            'published_at' => now(),
        ])->save();

        return $version->refresh();
    }
}

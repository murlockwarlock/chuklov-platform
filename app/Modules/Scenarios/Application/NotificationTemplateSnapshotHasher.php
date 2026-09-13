<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;

final class NotificationTemplateSnapshotHasher
{
    public function forTemplate(NotificationTemplate $template, NotificationTemplateVersion $latestVersion): string
    {
        return hash('sha256', json_encode([
            'template' => [
                'id' => (int) $template->getKey(),
                'organization_id' => (int) $template->getRawOriginal('organization_id'),
                'template_key' => $template->getRawOriginal('template_key'),
                'name' => $template->getRawOriginal('name'),
                'locale' => $template->getRawOriginal('locale'),
                'purpose' => $template->getRawOriginal('purpose'),
                'is_active' => $template->getRawOriginal('is_active'),
            ],
            'version' => [
                'id' => (int) $latestVersion->getKey(),
                'organization_id' => (int) $latestVersion->getRawOriginal('organization_id'),
                'template_id' => (int) $latestVersion->getRawOriginal('template_id'),
                'version' => (int) $latestVersion->getRawOriginal('version'),
                'status' => $latestVersion->getRawOriginal('status'),
                'subject' => $latestVersion->getRawOriginal('subject'),
                'body' => $latestVersion->getRawOriginal('body'),
                'variables' => $latestVersion->getRawOriginal('variables'),
                'delivery_mode' => $latestVersion->getRawOriginal('delivery_mode'),
                'caption_position' => $latestVersion->getRawOriginal('caption_position'),
                'media' => $latestVersion->getRawOriginal('media'),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

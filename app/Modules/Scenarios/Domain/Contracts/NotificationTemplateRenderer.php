<?php

namespace App\Modules\Scenarios\Domain\Contracts;

use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\ValueObjects\RenderedNotification;

interface NotificationTemplateRenderer
{
    /** @param array<string, mixed> $context */
    public function render(NotificationTemplateVersion $template, array $context, string $locale): RenderedNotification;
}

<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationTemplateVersion> */
class NotificationTemplateVersionFactory extends Factory
{
    protected $model = NotificationTemplateVersion::class;

    public function definition(): array
    {
        return [
            'version' => 1,
            'status' => NotificationTemplateStatus::Published->value,
            'subject' => null,
            'body' => 'Hello {{ client.full_name }}.',
            'variables' => ['client.full_name'],
            'published_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (NotificationTemplateVersion $version): NotificationTemplateVersion => $version->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forTemplate(NotificationTemplate $template): static
    {
        return $this->afterMaking(fn (NotificationTemplateVersion $version): NotificationTemplateVersion => $version->forceFill([
            'organization_id' => $template->organization_id,
            'template_id' => $template->getKey(),
        ]));
    }

    public function createdBy(User $user): static
    {
        return $this->afterMaking(fn (NotificationTemplateVersion $version): NotificationTemplateVersion => $version->forceFill([
            'created_by_user_id' => $user->getKey(),
        ]));
    }
}

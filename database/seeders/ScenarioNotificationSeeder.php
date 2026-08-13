<?php

namespace Database\Seeders;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Illuminate\Database\Seeder;

final class ScenarioNotificationSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'chuklov')->first();

        if ($organization === null) {
            return;
        }

        $this->seedLocale($organization, 'en', 'Thank you for your visit, {{ client.full_name }}.');
        $this->seedLocale($organization, 'ru', 'Спасибо за ваш визит, {{ client.full_name }}.');
    }

    private function seedLocale(Organization $organization, string $locale, string $body): void
    {
        $template = NotificationTemplate::query()->firstOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'template_key' => 'post-session-follow-up',
                'locale' => $locale,
            ],
            [
                'name' => 'Post-session follow-up',
                'purpose' => ScenarioRulePurpose::Service->value,
                'is_active' => true,
            ],
        );
        $version = NotificationTemplateVersion::query()->firstOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'template_id' => $template->getKey(),
                'version' => 1,
            ],
            [
                'status' => NotificationTemplateStatus::Published->value,
                'body' => $body,
                'variables' => ['client.full_name'],
                'created_by_user_id' => null,
                'published_at' => now(),
            ],
        );

        ScenarioRule::query()->firstOrCreate(
            [
                'organization_id' => $organization->getKey(),
                'rule_key' => 'post-session-follow-up-24h-'.$locale,
            ],
            [
                'name' => 'Post-session follow-up ('.$locale.')',
                'trigger_event' => ScenarioEventType::BookingCompleted->value,
                'is_enabled' => true,
                'delay_value' => 24,
                'delay_unit' => 'hours',
                'purpose' => ScenarioRulePurpose::Service->value,
                'conditions' => [['type' => 'client.language', 'operator' => 'equals', 'value' => $locale]],
                'recipient_strategy' => ['type' => 'client'],
                'channel_priority' => ['telegram'],
                'template_version_id' => $version->getKey(),
                'version' => 1,
            ],
        );
    }
}

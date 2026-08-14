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
        Organization::query()
            ->orderBy('id')
            ->each(function (Organization $organization): void {
                $this->seedLocale($organization, 'en', 'Thank you for your visit, {{ client.full_name }}.');
                $this->seedLocale($organization, 'ru', 'Спасибо за ваш визит, {{ client.full_name }}.');
            });
    }

    private function seedLocale(Organization $organization, string $locale, string $body): void
    {
        $template = NotificationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->where('template_key', 'post-session-follow-up')
            ->where('locale', $locale)
            ->first();

        if ($template === null) {
            $template = new NotificationTemplate;
            $template->forceFill([
                'organization_id' => $organization->getKey(),
                'template_key' => 'post-session-follow-up',
                'locale' => $locale,
                'name' => 'Post-session follow-up',
                'purpose' => ScenarioRulePurpose::Service->value,
                'is_active' => true,
            ])->save();
        }

        $version = NotificationTemplateVersion::query()
            ->where('organization_id', $organization->getKey())
            ->where('template_id', $template->getKey())
            ->where('version', 1)
            ->first();

        if ($version === null) {
            $version = new NotificationTemplateVersion;
            $version->forceFill([
                'organization_id' => $organization->getKey(),
                'template_id' => $template->getKey(),
                'version' => 1,
                'status' => NotificationTemplateStatus::Published->value,
                'body' => $body,
                'variables' => ['client.full_name'],
                'created_by_user_id' => null,
                'published_at' => now(),
            ])->save();
        }

        foreach ([
            [
                'key' => 'post-session-follow-up-24h-'.$locale,
                'name' => 'Post-session follow-up +24h ('.$locale.')',
                'delay' => 24,
                'conditions' => [['type' => 'client.language', 'operator' => 'equals', 'value' => $locale]],
            ],
            [
                'key' => 'post-session-follow-up-48h-'.$locale,
                'name' => 'Post-session follow-up +48h ('.$locale.')',
                'delay' => 48,
                'conditions' => [['type' => 'client.language', 'operator' => 'equals', 'value' => $locale]],
            ],
            [
                'key' => 'post-session-follow-up-72h-'.$locale,
                'name' => 'Post-session follow-up +72h ('.$locale.')',
                'delay' => 72,
                'conditions' => [
                    ['type' => 'client.language', 'operator' => 'equals', 'value' => $locale],
                    ['type' => 'booking.status', 'operator' => 'equals', 'value' => 'completed'],
                ],
            ],
        ] as $seed) {
            $rule = ScenarioRule::query()
                ->where('organization_id', $organization->getKey())
                ->where('rule_key', $seed['key'])
                ->first();

            if ($rule === null) {
                $rule = new ScenarioRule;
                $rule->forceFill([
                    'organization_id' => $organization->getKey(),
                    'rule_key' => $seed['key'],
                    'name' => $seed['name'],
                    'trigger_event' => ScenarioEventType::BookingCompleted->value,
                    'is_enabled' => true,
                    'delay_value' => $seed['delay'],
                    'delay_unit' => 'hours',
                    'purpose' => ScenarioRulePurpose::Service->value,
                    'conditions' => $seed['conditions'],
                    'recipient_strategy' => ['type' => 'client'],
                    'channel_priority' => ['telegram'],
                    'template_version_id' => $version->getKey(),
                    'max_occurrences' => 1,
                    'repeat_interval_value' => null,
                    'repeat_interval_unit' => null,
                    'version' => 1,
                ])->save();
            }
        }
    }
}

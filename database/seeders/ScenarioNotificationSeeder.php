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
                $this->seedB2b($organization, 'en', 'Your B2B conversation with {{ client.full_name }} (#{{ sales_call.id }}) is scheduled for {{ sales_call.local_date }} at {{ sales_call.local_time }} ({{ sales_call.timezone }}).');
                $this->seedB2b($organization, 'ru', 'Разговор о развитии бизнеса с клиентом {{ client.full_name }} (№{{ sales_call.id }}) запланирован на {{ sales_call.local_date }} в {{ sales_call.local_time }} ({{ sales_call.timezone }}).');
                $this->seedBookingConfirmation($organization, 'en', 'Your appointment with {{ booking.specialist_name }} for {{ booking.service_name }} is confirmed for {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).');
                $this->seedBookingConfirmation($organization, 'ru', 'Ваша запись к специалисту {{ booking.specialist_name }} на услугу «{{ booking.service_name }}» подтверждена на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}).');
                $this->seedFeedback($organization, 'en', 'Please rate your visit, {{ client.full_name }}.');
                $this->seedFeedback($organization, 'ru', 'Оцените визит, {{ client.full_name }}.');
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

    private function seedB2b(Organization $organization, string $locale, string $body): void
    {
        $template = NotificationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->where('template_key', 'b2b-sales-call-ready')
            ->where('locale', $locale)
            ->first();

        if ($template === null) {
            $template = new NotificationTemplate;
            $template->forceFill([
                'organization_id' => $organization->getKey(),
                'template_key' => 'b2b-sales-call-ready',
                'locale' => $locale,
                'name' => 'B2B sales call ready',
                'purpose' => ScenarioRulePurpose::Transactional->value,
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
                'variables' => ['client.full_name', 'sales_call.id', 'sales_call.local_date', 'sales_call.local_time', 'sales_call.timezone'],
                'created_by_user_id' => null,
                'published_at' => now(),
            ])->save();
        }

        $clientRuleKey = 'b2b-sales-call-ready-client-'.$locale;
        if (! ScenarioRule::query()->where('organization_id', $organization->getKey())->where('rule_key', $clientRuleKey)->exists()) {
            $rule = new ScenarioRule;
            $rule->forceFill([
                'organization_id' => $organization->getKey(),
                'rule_key' => $clientRuleKey,
                'name' => 'B2B sales call ready for client ('.$locale.')',
                'trigger_event' => ScenarioEventType::B2bSalesCallReady->value,
                'is_enabled' => true,
                'delay_value' => 0,
                'delay_unit' => 'minutes',
                'purpose' => ScenarioRulePurpose::Transactional->value,
                'conditions' => [['type' => 'client.language', 'operator' => 'equals', 'value' => $locale]],
                'recipient_strategy' => ['type' => 'client'],
                'channel_priority' => ['telegram'],
                'template_version_id' => $version->getKey(),
                'max_occurrences' => 1,
                'repeat_interval_value' => null,
                'repeat_interval_unit' => null,
                'version' => 1,
            ])->save();
        }

        if ($locale === 'en' && ! ScenarioRule::query()->where('organization_id', $organization->getKey())->where('rule_key', 'b2b-sales-call-ready-specialist')->exists()) {
            $specialistVersion = $this->ensureTransactionalTemplate(
                organization: $organization,
                templateKey: 'b2b-sales-call-ready-specialist',
                locale: 'en',
                name: 'B2B sales call ready for specialist',
                body: 'B2B conversation with {{ client.full_name }} (#{{ sales_call.id }}) is scheduled for {{ sales_call.local_date }} at {{ sales_call.local_time }} ({{ sales_call.timezone }}). Open CRM: {{ sales_call.crm_url }}',
                variables: ['client.full_name', 'sales_call.id', 'sales_call.local_date', 'sales_call.local_time', 'sales_call.timezone', 'sales_call.crm_url'],
            );
            $rule = new ScenarioRule;
            $rule->forceFill([
                'organization_id' => $organization->getKey(),
                'rule_key' => 'b2b-sales-call-ready-specialist',
                'name' => 'B2B sales call ready for specialist',
                'trigger_event' => ScenarioEventType::B2bSalesCallReady->value,
                'is_enabled' => true,
                'delay_value' => 0,
                'delay_unit' => 'minutes',
                'purpose' => ScenarioRulePurpose::Transactional->value,
                'conditions' => [],
                'recipient_strategy' => ['type' => 'assigned_specialist'],
                'channel_priority' => ['telegram'],
                'template_version_id' => $specialistVersion->getKey(),
                'max_occurrences' => 1,
                'repeat_interval_value' => null,
                'repeat_interval_unit' => null,
                'version' => 1,
            ])->save();
        }
    }

    private function seedBookingConfirmation(Organization $organization, string $locale, string $body): void
    {
        $version = $this->ensureTransactionalTemplate(
            organization: $organization,
            templateKey: 'booking-confirmed',
            locale: $locale,
            name: 'Booking confirmed',
            body: $body,
            variables: ['booking.specialist_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone'],
        );
        $ruleKey = 'booking-confirmed-client-'.$locale;

        if (ScenarioRule::query()->where('organization_id', $organization->getKey())->where('rule_key', $ruleKey)->exists()) {
            return;
        }

        $rule = new ScenarioRule;
        $rule->forceFill([
            'organization_id' => $organization->getKey(),
            'rule_key' => $ruleKey,
            'name' => 'Booking confirmed for client ('.$locale.')',
            'trigger_event' => ScenarioEventType::BookingConfirmed->value,
            'is_enabled' => true,
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'purpose' => ScenarioRulePurpose::Transactional->value,
            'conditions' => [['type' => 'client.language', 'operator' => 'equals', 'value' => $locale]],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'template_version_id' => $version->getKey(),
            'max_occurrences' => 1,
            'repeat_interval_value' => null,
            'repeat_interval_unit' => null,
            'version' => 1,
        ])->save();
    }

    private function seedFeedback(Organization $organization, string $locale, string $body): void
    {
        $version = $this->ensureFeedbackTemplate($organization, $locale, $body);
        $ruleKey = 'booking-completed-feedback-'.$locale;

        if (ScenarioRule::query()->where('organization_id', $organization->getKey())->where('rule_key', $ruleKey)->exists()) {
            return;
        }

        $rule = new ScenarioRule;
        $rule->forceFill([
            'organization_id' => $organization->getKey(),
            'rule_key' => $ruleKey,
            'name' => 'Оценка визита после завершения ('.$locale.')',
            'trigger_event' => ScenarioEventType::BookingCompleted->value,
            'is_enabled' => true,
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'purpose' => ScenarioRulePurpose::Service->value,
            'conditions' => [['type' => 'client.language', 'operator' => 'equals', 'value' => $locale]],
            'recipient_strategy' => ['type' => 'client'],
            'channel_priority' => ['telegram'],
            'template_version_id' => $version->getKey(),
            'max_occurrences' => 1,
            'repeat_interval_value' => null,
            'repeat_interval_unit' => null,
            'version' => 1,
        ])->save();
    }

    private function ensureFeedbackTemplate(Organization $organization, string $locale, string $body): NotificationTemplateVersion
    {
        $template = NotificationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->where('template_key', 'booking-completed-feedback')
            ->where('locale', $locale)
            ->first();

        if ($template === null) {
            $template = new NotificationTemplate;
            $template->forceFill([
                'organization_id' => $organization->getKey(),
                'template_key' => 'booking-completed-feedback',
                'locale' => $locale,
                'name' => 'Оценка визита',
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
                'variables' => ['client.full_name', 'feedback.url'],
                'created_by_user_id' => null,
                'published_at' => now(),
            ])->save();
        }

        return $version;
    }

    /** @param list<string> $variables */
    private function ensureTransactionalTemplate(
        Organization $organization,
        string $templateKey,
        string $locale,
        string $name,
        string $body,
        array $variables,
    ): NotificationTemplateVersion {
        $template = NotificationTemplate::query()
            ->where('organization_id', $organization->getKey())
            ->where('template_key', $templateKey)
            ->where('locale', $locale)
            ->first();

        if ($template === null) {
            $template = new NotificationTemplate;
            $template->forceFill([
                'organization_id' => $organization->getKey(),
                'template_key' => $templateKey,
                'locale' => $locale,
                'name' => $name,
                'purpose' => ScenarioRulePurpose::Transactional->value,
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
                'variables' => $variables,
                'created_by_user_id' => null,
                'published_at' => now(),
            ])->save();
        }

        return $version;
    }
}

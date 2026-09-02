<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\NotificationTemplateStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use App\Modules\Scenarios\Domain\Models\AppointmentReminder;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use Illuminate\Support\Facades\DB;

final class EnsureAppointmentReminderDefaults
{
    public function handle(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            $organization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $templates = [];

            foreach ($this->templateDefinitions() as $definition) {
                $templates[$definition['key'].'|'.$definition['locale']] = $this->ensureTemplate(
                    organization: $organization,
                    templateKey: $definition['key'],
                    locale: $definition['locale'],
                    name: $definition['name'],
                    body: $definition['body'],
                    variables: $definition['variables'],
                );
            }

            foreach ($this->ruleDefinitions() as $definition) {
                $templateId = $templates[$definition['template_key'].'|ru'];
                $this->ensureRule($organization, $definition, $templateId);
            }

            foreach ($this->reminderDefinitions() as $definition) {
                $reminder = AppointmentReminder::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('recipient_type', $definition['recipient_type'])
                    ->where('offset_value', $definition['offset_value'])
                    ->where('offset_unit', $definition['offset_unit'])
                    ->first();

                if ($reminder !== null) {
                    continue;
                }

                $reminder = new AppointmentReminder;
                $reminder->forceFill([
                    'organization_id' => $organization->getKey(),
                    'recipient_type' => $definition['recipient_type'],
                    'offset_value' => $definition['offset_value'],
                    'offset_unit' => $definition['offset_unit'],
                    'is_enabled' => true,
                ])->save();
            }
        });
    }

    /** @return list<array{key: string, locale: string, name: string, body: string, variables: list<string>}> */
    private function templateDefinitions(): array
    {
        return [
            [
                'key' => 'appointment-reminder-client-office',
                'locale' => 'ru',
                'name' => 'Напоминание клиенту — приём в кабинете',
                'body' => "Напоминаем: у вас запись к {{ booking.specialist_name }} {{ booking.local_date }} в {{ booking.local_time }}.\nАдрес: {{ booking.location }}",
                'variables' => ['booking.specialist_name', 'booking.local_date', 'booking.local_time', 'booking.location'],
            ],
            [
                'key' => 'appointment-reminder-client-office',
                'locale' => 'en',
                'name' => 'Напоминание клиенту — приём в кабинете',
                'body' => "Reminder: you have an appointment with {{ booking.specialist_name }} on {{ booking.local_date }} at {{ booking.local_time }}.\nAddress: {{ booking.location }}",
                'variables' => ['booking.specialist_name', 'booking.local_date', 'booking.local_time', 'booking.location'],
            ],
            [
                'key' => 'appointment-reminder-client-online',
                'locale' => 'ru',
                'name' => 'Напоминание клиенту — онлайн-визит',
                'body' => 'Напоминаем: у вас онлайн-запись к {{ booking.specialist_name }} {{ booking.local_date }} в {{ booking.local_time }}.',
                'variables' => ['booking.specialist_name', 'booking.local_date', 'booking.local_time'],
            ],
            [
                'key' => 'appointment-reminder-client-online',
                'locale' => 'en',
                'name' => 'Напоминание клиенту — онлайн-визит',
                'body' => 'Reminder: you have an online appointment with {{ booking.specialist_name }} on {{ booking.local_date }} at {{ booking.local_time }}.',
                'variables' => ['booking.specialist_name', 'booking.local_date', 'booking.local_time'],
            ],
            [
                'key' => 'appointment-reminder-client-home',
                'locale' => 'ru',
                'name' => 'Напоминание клиенту — выездной визит',
                'body' => "Напоминаем: у вас выездной визит к {{ booking.specialist_name }} {{ booking.local_date }} в {{ booking.local_time }}.\nАдрес: {{ booking.location }}",
                'variables' => ['booking.specialist_name', 'booking.local_date', 'booking.local_time', 'booking.location'],
            ],
            [
                'key' => 'appointment-reminder-client-home',
                'locale' => 'en',
                'name' => 'Напоминание клиенту — выездной визит',
                'body' => "Reminder: you have a home visit with {{ booking.specialist_name }} on {{ booking.local_date }} at {{ booking.local_time }}.\nAddress: {{ booking.location }}",
                'variables' => ['booking.specialist_name', 'booking.local_date', 'booking.local_time', 'booking.location'],
            ],
            [
                'key' => 'appointment-reminder-specialist-office',
                'locale' => 'ru',
                'name' => 'Напоминание специалисту — приём в кабинете',
                'body' => 'Через {{ booking.reminder_offset_label }} запись с {{ client.full_name }} на услугу «{{ booking.service_name }}» {{ booking.local_date }} в {{ booking.local_time }}. Telegram клиента: {{ client.telegram_contact }}. Адрес: {{ booking.location }}',
                'variables' => ['booking.reminder_offset_label', 'client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'client.telegram_contact', 'booking.location'],
            ],
            [
                'key' => 'appointment-reminder-specialist-online',
                'locale' => 'ru',
                'name' => 'Напоминание специалисту — онлайн-визит',
                'body' => 'Через {{ booking.reminder_offset_label }} онлайн-запись с {{ client.full_name }} на услугу «{{ booking.service_name }}» {{ booking.local_date }} в {{ booking.local_time }}. Telegram клиента: {{ client.telegram_contact }}.',
                'variables' => ['booking.reminder_offset_label', 'client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'client.telegram_contact'],
            ],
            [
                'key' => 'appointment-reminder-specialist-home',
                'locale' => 'ru',
                'name' => 'Напоминание специалисту — выездной визит',
                'body' => 'Через {{ booking.reminder_offset_label }} выезд к {{ client.full_name }} на услугу «{{ booking.service_name }}» {{ booking.local_date }} в {{ booking.local_time }}. Telegram клиента: {{ client.telegram_contact }}. Адрес: {{ booking.location }}',
                'variables' => ['booking.reminder_offset_label', 'client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'client.telegram_contact', 'booking.location'],
            ],
        ];
    }

    /** @return list<array{recipient_type: string, offset_value: int, offset_unit: string}> */
    private function reminderDefinitions(): array
    {
        return [
            ['recipient_type' => 'client', 'offset_value' => 1, 'offset_unit' => 'days'],
            ['recipient_type' => 'client', 'offset_value' => 2, 'offset_unit' => 'hours'],
            ['recipient_type' => 'client', 'offset_value' => 30, 'offset_unit' => 'minutes'],
            ['recipient_type' => 'specialist', 'offset_value' => 30, 'offset_unit' => 'minutes'],
        ];
    }

    /** @return list<array{template_key: string, recipient_type: string, format: string, name: string}> */
    private function ruleDefinitions(): array
    {
        return [
            ['template_key' => 'appointment-reminder-client-office', 'recipient_type' => 'client', 'format' => 'office', 'name' => 'Напоминание клиенту перед приёмом в кабинете'],
            ['template_key' => 'appointment-reminder-client-online', 'recipient_type' => 'client', 'format' => 'online', 'name' => 'Напоминание клиенту перед онлайн-визитом'],
            ['template_key' => 'appointment-reminder-client-home', 'recipient_type' => 'client', 'format' => 'home', 'name' => 'Напоминание клиенту перед выездом'],
            ['template_key' => 'appointment-reminder-specialist-office', 'recipient_type' => 'specialist', 'format' => 'office', 'name' => 'Напоминание специалисту перед приёмом в кабинете'],
            ['template_key' => 'appointment-reminder-specialist-online', 'recipient_type' => 'specialist', 'format' => 'online', 'name' => 'Напоминание специалисту перед онлайн-визитом'],
            ['template_key' => 'appointment-reminder-specialist-home', 'recipient_type' => 'specialist', 'format' => 'home', 'name' => 'Напоминание специалисту перед выездом'],
        ];
    }

    /** @param list<string> $variables */
    private function ensureTemplate(Organization $organization, string $templateKey, string $locale, string $name, string $body, array $variables): int
    {
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
                'name' => $name,
                'locale' => $locale,
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
                'status' => NotificationTemplateStatus::Published,
                'body' => $body,
                'variables' => $variables,
                'published_at' => now(),
            ])->save();
        }

        return (int) $version->getKey();
    }

    /** @param array{template_key: string, recipient_type: string, format: string, name: string} $definition */
    private function ensureRule(Organization $organization, array $definition, int $templateId): void
    {
        $rule = ScenarioRule::query()
            ->where('organization_id', $organization->getKey())
            ->where('rule_key', $definition['template_key'])
            ->first() ?? new ScenarioRule;
        $rule->forceFill([
            'organization_id' => $organization->getKey(),
            'rule_key' => $definition['template_key'],
            'name' => $definition['name'],
            'trigger_event' => ScenarioEventType::BookingConfirmed->value,
            'is_enabled' => true,
            'system_managed' => true,
            'delay_value' => 0,
            'delay_unit' => 'minutes',
            'purpose' => ScenarioRulePurpose::Service->value,
            'conditions' => [],
            'recipient_strategy' => ['type' => $definition['recipient_type'] === 'client' ? 'client' : 'assigned_specialist'],
            'channel_priority' => ['telegram'],
            'template_version_id' => $templateId,
            'max_occurrences' => 1,
            'repeat_interval_value' => null,
            'repeat_interval_unit' => null,
            'version' => $rule->exists ? $rule->version : 1,
        ])->save();
    }
}

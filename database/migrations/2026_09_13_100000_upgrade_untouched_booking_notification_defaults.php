<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $organizationIds = DB::table('organizations')->orderBy('id')->pluck('id');
        $definitions = $this->definitions();

        foreach ($organizationIds as $organizationId) {
            foreach ($definitions as $definition) {
                $this->upgrade($organizationId, $definition);
            }
        }
    }

    public function down(): void {}

    private function upgrade(mixed $organizationId, array $definition): void
    {
        DB::transaction(function () use ($organizationId, $definition): void {
            $this->upgradeWithinTransaction($organizationId, $definition);
        });
    }

    private function upgradeWithinTransaction(mixed $organizationId, array $definition): void
    {
        $template = DB::table('notification_templates')
            ->where('organization_id', $organizationId)
            ->where('template_key', $definition['template_key'])
            ->where('locale', $definition['locale'])
            ->where('purpose', 'transactional')
            ->lockForUpdate()
            ->first(['id', 'name', 'is_active']);

        if ($template === null
            || ! $template->is_active
            || ! in_array($template->name, $definition['legacy_names'], true)) {
            return;
        }

        $rule = DB::table('scenario_rules')
            ->where('organization_id', $organizationId)
            ->where('rule_key', $definition['rule_key'])
            ->lockForUpdate()
            ->first(['id', 'template_version_id', 'trigger_event', 'purpose', 'is_enabled', 'created_by_user_id', 'updated_by_user_id']);
        if ($rule === null
            || $rule->trigger_event !== $this->triggerEventFor($definition['template_key'])
            || $rule->purpose !== 'transactional'
            || ! $rule->is_enabled
            || $rule->created_by_user_id !== null
            || $rule->updated_by_user_id !== null) {
            return;
        }

        $current = DB::table('notification_template_versions')
            ->where('organization_id', $organizationId)
            ->where('id', $rule->template_version_id)
            ->where('template_id', $template->id)
            ->lockForUpdate()
            ->first([
                'id',
                'template_id',
                'version',
                'status',
                'subject',
                'body',
                'variables',
                'delivery_mode',
                'caption_position',
                'media',
                'created_by_user_id',
            ]);
        if ($current === null
            || $current->status !== 'published'
            || $current->created_by_user_id !== null
            || ! $this->isLatest($organizationId, $template->id, $current->id)
            || ! $this->isLegacyState($current, $definition['legacy_states'])) {
            return;
        }

        $timestamp = now();
        $versionId = DB::table('notification_template_versions')->insertGetId([
            'organization_id' => $organizationId,
            'template_id' => $template->id,
            'version' => (int) $current->version + 1,
            'status' => 'published',
            'subject' => $definition['subject'],
            'body' => $definition['body'],
            'variables' => json_encode($definition['variables'], JSON_THROW_ON_ERROR),
            'delivery_mode' => 'text',
            'caption_position' => 'below',
            'media' => null,
            'created_by_user_id' => null,
            'published_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        if ($template->name !== $definition['name']) {
            DB::table('notification_templates')
                ->where('organization_id', $organizationId)
                ->where('id', $template->id)
                ->whereIn('name', $definition['legacy_names'])
                ->update([
                    'name' => $definition['name'],
                    'updated_at' => $timestamp,
                ]);
        }

        DB::table('scenario_rules')
            ->where('organization_id', $organizationId)
            ->where('id', $rule->id)
            ->where('template_version_id', $current->id)
            ->update([
                'template_version_id' => $versionId,
                'version' => DB::raw('version + 1'),
                'updated_at' => $timestamp,
            ]);
    }

    private function isLatest(mixed $organizationId, int $templateId, int $versionId): bool
    {
        return (int) DB::table('notification_template_versions')
            ->where('organization_id', $organizationId)
            ->where('template_id', $templateId)
            ->orderByDesc('version')
            ->lockForUpdate()
            ->value('id') === $versionId;
    }

    private function triggerEventFor(string $templateKey): string
    {
        return match ($templateKey) {
            'booking-created',
            'booking-created-specialist',
            'booking-created-crm',
            'booking-home-visit-review-crm' => 'booking.created',
            'booking-confirmed',
            'booking-confirmed-specialist',
            'booking-confirmed-crm' => 'booking.confirmed',
            'booking-rescheduled',
            'booking-rescheduled-specialist',
            'booking-rescheduled-crm' => 'booking.rescheduled',
            'booking-cancelled',
            'booking-cancelled-specialist',
            'booking-cancelled-crm' => 'booking.cancelled',
            default => throw new LogicException('Unsupported booking notification template.'),
        };
    }

    private function isLegacyState(object $version, array $legacyStates): bool
    {
        $variables = json_decode((string) $version->variables, true);
        if (! is_array($variables)) {
            return false;
        }

        $media = null;
        if ($version->media !== null) {
            try {
                $media = json_decode((string) $version->media, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }
        }

        foreach ($legacyStates as $state) {
            if ($version->subject === $state['subject']
                && $version->body === $state['body']
                && array_values($variables) === $state['variables']
                && (string) ($version->delivery_mode ?? 'text') === ($state['delivery_mode'] ?? 'text')
                && (string) ($version->caption_position ?? 'below') === ($state['caption_position'] ?? 'below')
                && $media === ($state['media'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function definitions(): array
    {
        $clientDetails = [
            'booking.specialist_name',
            'booking.service_name',
            'booking.local_date',
            'booking.local_time',
            'booking.timezone',
            'booking.visit_details',
        ];
        $clientLabels = [
            'booking.specialist_name',
            'booking.service_name',
            'booking.local_date',
            'booking.local_time',
            'booking.timezone',
            'booking.visit_format_label',
            'booking.location_label',
        ];
        $specialistDetails = [
            'client.full_name',
            'client.telegram_contact',
            'booking.service_name',
            'booking.local_date',
            'booking.local_time',
            'booking.timezone',
            'booking.visit_details',
        ];
        $specialistLabels = [
            'client.full_name',
            'client.telegram_contact',
            'booking.service_name',
            'booking.local_date',
            'booking.local_time',
            'booking.timezone',
            'booking.visit_format_label',
            'booking.location_label',
        ];
        $crmVariables = ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time'];
        $crmHomeVisitVariables = ['client.full_name', 'booking.local_date', 'booking.local_time'];

        return [
            [
                'template_key' => 'booking-created',
                'locale' => 'ru',
                'rule_key' => 'booking-created-client-ru',
                'legacy_names' => ['Новая запись', 'Новая запись для клиента', 'Новая заявка', 'Booking request received', 'booking-created'],
                'name' => 'Заявка на запись',
                'subject' => null,
                'body' => "Заявка на запись принята\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nМы скоро подтвердим запись.",
                'variables' => $clientDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => "Новая заявка\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Новая заявка\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nМы скоро подтвердим запись.", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Новая заявка\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}", 'variables' => $clientLabels],
                ],
            ],
            [
                'template_key' => 'booking-created',
                'locale' => 'en',
                'rule_key' => 'booking-created-client-en',
                'legacy_names' => ['Новая запись', 'Новая запись для клиента', 'Appointment request', 'Booking request received', 'booking-created'],
                'name' => 'Заявка на запись',
                'subject' => null,
                'body' => "Your appointment request was received\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nWe will confirm it soon.",
                'variables' => $clientDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => "Appointment request received\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nWe will confirm it soon.", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Booking created\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}", 'variables' => $clientLabels],
                ],
            ],
            [
                'template_key' => 'booking-confirmed',
                'locale' => 'ru',
                'rule_key' => 'booking-confirmed-client-ru',
                'legacy_names' => ['Подтверждение записи', 'Подтверждение записи для клиента', 'Booking confirmed', 'booking-confirmed'],
                'name' => 'Подтверждение записи',
                'subject' => null,
                'body' => "Ваша запись подтверждена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}",
                'variables' => $clientDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => "Запись подтверждена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Запись подтверждена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}", 'variables' => $clientLabels],
                ],
            ],
            [
                'template_key' => 'booking-confirmed',
                'locale' => 'en',
                'rule_key' => 'booking-confirmed-client-en',
                'legacy_names' => ['Подтверждение записи', 'Подтверждение записи для клиента', 'Booking confirmed', 'booking-confirmed'],
                'name' => 'Appointment confirmation',
                'subject' => null,
                'body' => "Your appointment is confirmed\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}",
                'variables' => $clientDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => "Appointment confirmed\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Booking confirmed\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}", 'variables' => $clientLabels],
                ],
            ],
            [
                'template_key' => 'booking-rescheduled',
                'locale' => 'ru',
                'rule_key' => 'booking-rescheduled-client-ru',
                'legacy_names' => ['Перенос записи', 'Перенос записи для клиента', 'Booking rescheduled', 'booking-rescheduled'],
                'name' => 'Перенос записи',
                'subject' => null,
                'body' => "Ваша запись перенесена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}",
                'variables' => $clientDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => "Запись перенесена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Запись перенесена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}", 'variables' => $clientLabels],
                ],
            ],
            [
                'template_key' => 'booking-rescheduled',
                'locale' => 'en',
                'rule_key' => 'booking-rescheduled-client-en',
                'legacy_names' => ['Перенос записи', 'Перенос записи для клиента', 'Booking rescheduled', 'booking-rescheduled'],
                'name' => 'Appointment rescheduled',
                'subject' => null,
                'body' => "Your appointment was rescheduled\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}",
                'variables' => $clientDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => "Appointment rescheduled\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Booking rescheduled\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}", 'variables' => $clientLabels],
                ],
            ],
            [
                'template_key' => 'booking-cancelled',
                'locale' => 'ru',
                'rule_key' => 'booking-cancelled-client-ru',
                'legacy_names' => ['Отмена записи', 'Отмена записи для клиента', 'Booking cancelled', 'booking-cancelled'],
                'name' => 'Отмена записи',
                'subject' => null,
                'body' => "Ваша запись отменена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}",
                'variables' => $clientDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => "Запись отменена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Запись отменена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }}) отменена", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Запись отменена\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}", 'variables' => $clientLabels],
                ],
            ],
            [
                'template_key' => 'booking-cancelled',
                'locale' => 'en',
                'rule_key' => 'booking-cancelled-client-en',
                'legacy_names' => ['Отмена записи', 'Отмена записи для клиента', 'Booking cancelled', 'booking-cancelled'],
                'name' => 'Appointment cancellation',
                'subject' => null,
                'body' => "Your appointment was cancelled\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}",
                'variables' => $clientDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => "Appointment cancelled\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}", 'variables' => $clientDetails],
                    ['subject' => null, 'body' => "Booking cancelled\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}", 'variables' => $clientLabels],
                ],
            ],
            [
                'template_key' => 'booking-created-specialist',
                'locale' => 'ru',
                'rule_key' => 'booking-created-specialist',
                'legacy_names' => ['Новая заявка на запись для специалиста', 'Новая запись для специалиста', 'booking-created-specialist for specialist', 'booking-created-specialist'],
                'name' => 'Новая запись от клиента',
                'subject' => null,
                'body' => "Новая запись от клиента\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.",
                'variables' => $specialistDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => 'Новая заявка на запись от клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.', 'variables' => $specialistDetails],
                    ['subject' => null, 'body' => "Новая запись\nНовая заявка на запись от клиента {{ client.full_name }}.\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.", 'variables' => $specialistDetails],
                    ['subject' => null, 'body' => "Новая запись\nНовая заявка на запись от клиента {{ client.full_name }}.\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.", 'variables' => $specialistLabels],
                    ['subject' => null, 'body' => 'New appointment request from {{ client.full_name }} for {{ booking.service_name }} on {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).', 'variables' => ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone']],
                ],
            ],
            [
                'template_key' => 'booking-confirmed-specialist',
                'locale' => 'ru',
                'rule_key' => 'booking-confirmed-specialist',
                'legacy_names' => ['Подтверждение записи для специалиста', 'booking-confirmed-specialist for specialist', 'booking-confirmed-specialist'],
                'name' => 'Запись клиента подтверждена',
                'subject' => null,
                'body' => "Запись клиента подтверждена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.",
                'variables' => $specialistDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => 'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» подтверждена на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.', 'variables' => $specialistDetails],
                    ['subject' => null, 'body' => "Запись подтверждена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.", 'variables' => $specialistDetails],
                    ['subject' => null, 'body' => "Запись подтверждена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.", 'variables' => $specialistLabels],
                    ['subject' => null, 'body' => 'Appointment with {{ client.full_name }} for {{ booking.service_name }} is confirmed for {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).', 'variables' => ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone']],
                ],
            ],
            [
                'template_key' => 'booking-rescheduled-specialist',
                'locale' => 'ru',
                'rule_key' => 'booking-rescheduled-specialist',
                'legacy_names' => ['Перенос записи для специалиста', 'booking-rescheduled for specialist', 'booking-rescheduled-specialist'],
                'name' => 'Запись клиента перенесена',
                'subject' => null,
                'body' => "Запись клиента перенесена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.",
                'variables' => $specialistDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => 'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» перенесена на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.', 'variables' => $specialistDetails],
                    ['subject' => null, 'body' => "Запись перенесена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.", 'variables' => $specialistDetails],
                    ['subject' => null, 'body' => "Запись перенесена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.", 'variables' => $specialistLabels],
                    ['subject' => null, 'body' => 'Appointment with {{ client.full_name }} for {{ booking.service_name }} was rescheduled to {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).', 'variables' => ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone']],
                ],
            ],
            [
                'template_key' => 'booking-cancelled-specialist',
                'locale' => 'ru',
                'rule_key' => 'booking-cancelled-specialist',
                'legacy_names' => ['Отмена записи для специалиста', 'booking-cancelled for specialist', 'booking-cancelled-specialist'],
                'name' => 'Запись клиента отменена',
                'subject' => null,
                'body' => "Запись клиента отменена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.",
                'variables' => $specialistDetails,
                'legacy_states' => [
                    ['subject' => null, 'body' => 'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}) отменена. Telegram клиента: {{ client.telegram_contact }}.', 'variables' => $specialistDetails],
                    ['subject' => null, 'body' => "Запись отменена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_details }}\nTelegram клиента: {{ client.telegram_contact }}.", 'variables' => $specialistDetails],
                    ['subject' => null, 'body' => "Запись отменена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.", 'variables' => $specialistLabels],
                    ['subject' => null, 'body' => 'Appointment with {{ client.full_name }} for {{ booking.service_name }} on {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}) was cancelled.', 'variables' => ['client.full_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone']],
                ],
            ],
            [
                'template_key' => 'booking-created-crm',
                'locale' => 'ru',
                'rule_key' => 'booking-created-specialist-database',
                'legacy_names' => ['Новая запись в CRM'],
                'name' => 'Новая запись от клиента',
                'subject' => 'Новая запись от клиента',
                'body' => 'Клиент {{ client.full_name }} отправил заявку на запись: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}. Проверьте заявку и подтвердите запись.',
                'variables' => $crmVariables,
                'legacy_states' => [
                    ['subject' => 'Новая запись', 'body' => 'Новая запись: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.', 'variables' => $crmVariables],
                ],
            ],
            [
                'template_key' => 'booking-home-visit-review-crm',
                'locale' => 'ru',
                'rule_key' => 'booking-home-visit-review-database',
                'legacy_names' => ['Заявка на выезд'],
                'name' => 'Заявка клиента на выезд',
                'subject' => 'Заявка клиента на выезд',
                'body' => 'Клиент {{ client.full_name }} запросил выезд: {{ booking.local_date }} в {{ booking.local_time }}. Проверьте заявку.',
                'variables' => $crmHomeVisitVariables,
                'legacy_states' => [
                    ['subject' => 'Новая заявка на выезд', 'body' => 'Новая заявка на выезд к клиенту {{ client.full_name }}: {{ booking.local_date }} в {{ booking.local_time }}.', 'variables' => $crmHomeVisitVariables],
                ],
            ],
            [
                'template_key' => 'booking-confirmed-crm',
                'locale' => 'ru',
                'rule_key' => 'booking-confirmed-specialist-database',
                'legacy_names' => ['Запись подтверждена в CRM'],
                'name' => 'Запись клиента подтверждена',
                'subject' => 'Запись клиента подтверждена',
                'body' => 'Запись клиента {{ client.full_name }} подтверждена: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                'variables' => $crmVariables,
                'legacy_states' => [
                    ['subject' => 'Запись подтверждена', 'body' => 'Запись подтверждена: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.', 'variables' => $crmVariables],
                ],
            ],
            [
                'template_key' => 'booking-rescheduled-crm',
                'locale' => 'ru',
                'rule_key' => 'booking-rescheduled-specialist-database',
                'legacy_names' => ['Запись перенесена в CRM'],
                'name' => 'Запись клиента перенесена',
                'subject' => 'Запись клиента перенесена',
                'body' => 'Запись клиента {{ client.full_name }} перенесена: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                'variables' => $crmVariables,
                'legacy_states' => [
                    ['subject' => 'Запись перенесена', 'body' => 'Запись перенесена: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.', 'variables' => $crmVariables],
                ],
            ],
            [
                'template_key' => 'booking-cancelled-crm',
                'locale' => 'ru',
                'rule_key' => 'booking-cancelled-specialist-database',
                'legacy_names' => ['Запись отменена в CRM'],
                'name' => 'Запись клиента отменена',
                'subject' => 'Запись клиента отменена',
                'body' => 'Запись клиента {{ client.full_name }} отменена: {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.',
                'variables' => $crmVariables,
                'legacy_states' => [
                    ['subject' => 'Запись отменена', 'body' => 'Запись отменена: {{ client.full_name }} — {{ booking.service_name }}, {{ booking.local_date }} в {{ booking.local_time }}.', 'variables' => $crmVariables],
                ],
            ],
        ];
    }
};

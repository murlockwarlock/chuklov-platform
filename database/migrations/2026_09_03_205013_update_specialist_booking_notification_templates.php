<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (mixed $organizationId): void {
                foreach ($this->definitions() as $definition) {
                    $template = DB::table('notification_templates')
                        ->where('organization_id', $organizationId)
                        ->where('template_key', $definition['template_key'])
                        ->where('locale', 'ru')
                        ->first(['id']);

                    if ($template === null) {
                        continue;
                    }

                    $current = DB::table('notification_template_versions')
                        ->where('organization_id', $organizationId)
                        ->where('template_id', $template->id)
                        ->where('version', 1)
                        ->first(['id', 'body']);

                    if ($current === null || ! in_array($current->body, $definition['old_bodies'], true)) {
                        continue;
                    }

                    $latest = DB::table('notification_template_versions')
                        ->where('organization_id', $organizationId)
                        ->where('template_id', $template->id)
                        ->orderByDesc('version')
                        ->first(['id', 'version', 'body']);
                    $versionId = $latest?->body === $definition['body'] ? $latest->id : null;

                    if ($versionId === null) {
                        $version = ((int) ($latest?->version ?? 0)) + 1;
                        $versionId = DB::table('notification_template_versions')->insertGetId([
                            'organization_id' => $organizationId,
                            'template_id' => $template->id,
                            'version' => $version,
                            'status' => 'published',
                            'subject' => null,
                            'body' => $definition['body'],
                            'variables' => json_encode($definition['variables'], JSON_THROW_ON_ERROR),
                            'created_by_user_id' => null,
                            'published_at' => now(),
                            'created_at' => now(),
                        ]);
                    }

                    DB::table('scenario_rules')
                        ->where('organization_id', $organizationId)
                        ->where('rule_key', $definition['rule_key'])
                        ->where('template_version_id', $current->id)
                        ->update([
                            'template_version_id' => $versionId,
                            'version' => DB::raw('version + 1'),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void {}

    /** @return list<array{rule_key: string, template_key: string, old_bodies: list<string>, body: string, variables: list<string>}> */
    private function definitions(): array
    {
        $variables = [
            'client.full_name',
            'client.telegram_contact',
            'booking.service_name',
            'booking.local_date',
            'booking.local_time',
            'booking.timezone',
            'booking.visit_format_label',
            'booking.location_label',
        ];

        return [
            [
                'rule_key' => 'booking-created-specialist',
                'template_key' => 'booking-created-specialist',
                'old_bodies' => [
                    'Новая заявка на запись от клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.',
                    'New appointment request from {{ client.full_name }} for {{ booking.service_name }} on {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).',
                ],
                'body' => 'Новая запись\nНовая заявка на запись от клиента {{ client.full_name }}.\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.',
                'variables' => $variables,
            ],
            [
                'rule_key' => 'booking-confirmed-specialist',
                'template_key' => 'booking-confirmed-specialist',
                'old_bodies' => [
                    'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» подтверждена на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.',
                    'Appointment with {{ client.full_name }} for {{ booking.service_name }} is confirmed for {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).',
                ],
                'body' => 'Запись подтверждена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.',
                'variables' => $variables,
            ],
            [
                'rule_key' => 'booking-rescheduled-specialist',
                'template_key' => 'booking-rescheduled-specialist',
                'old_bodies' => [
                    'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» перенесена на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.',
                    'Appointment with {{ client.full_name }} for {{ booking.service_name }} was rescheduled to {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).',
                ],
                'body' => 'Запись перенесена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.',
                'variables' => $variables,
            ],
            [
                'rule_key' => 'booking-cancelled-specialist',
                'template_key' => 'booking-cancelled-specialist',
                'old_bodies' => [
                    'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}) отменена. Telegram клиента: {{ client.telegram_contact }}.',
                    'Appointment with {{ client.full_name }} for {{ booking.service_name }} on {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}) was cancelled.',
                ],
                'body' => 'Запись отменена\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.',
                'variables' => $variables,
            ],
        ];
    }
};

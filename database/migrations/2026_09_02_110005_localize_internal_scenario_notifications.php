<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->definitions() as $definition) {
            DB::table('organizations')
                ->orderBy('id')
                ->pluck('id')
                ->each(function (mixed $organizationId) use ($definition): void {
                    $oldTemplate = DB::table('notification_templates')
                        ->where('organization_id', $organizationId)
                        ->where('template_key', $definition['template_key'])
                        ->where('locale', 'en')
                        ->first(['id']);

                    if ($oldTemplate === null) {
                        return;
                    }

                    $oldVersion = DB::table('notification_template_versions')
                        ->where('organization_id', $organizationId)
                        ->where('template_id', $oldTemplate->id)
                        ->where('version', 1)
                        ->first(['id', 'body']);

                    if ($oldVersion === null || $oldVersion->body !== $definition['old_body']) {
                        return;
                    }

                    $template = DB::table('notification_templates')
                        ->where('organization_id', $organizationId)
                        ->where('template_key', $definition['template_key'])
                        ->where('locale', 'ru')
                        ->first(['id']);

                    if ($template === null) {
                        $timestamp = now();
                        $templateId = DB::table('notification_templates')->insertGetId([
                            'organization_id' => $organizationId,
                            'template_key' => $definition['template_key'],
                            'name' => $definition['name'],
                            'locale' => 'ru',
                            'purpose' => 'transactional',
                            'is_active' => true,
                            'created_at' => $timestamp,
                            'updated_at' => $timestamp,
                        ]);
                    } else {
                        $templateId = $template->id;
                    }

                    $version = DB::table('notification_template_versions')
                        ->where('organization_id', $organizationId)
                        ->where('template_id', $templateId)
                        ->orderByDesc('version')
                        ->first(['id']);

                    if ($version === null) {
                        $versionId = DB::table('notification_template_versions')->insertGetId([
                            'organization_id' => $organizationId,
                            'template_id' => $templateId,
                            'version' => 1,
                            'status' => 'published',
                            'subject' => null,
                            'body' => $definition['body'],
                            'variables' => json_encode($definition['variables'], JSON_THROW_ON_ERROR),
                            'created_by_user_id' => null,
                            'published_at' => now(),
                            'created_at' => now(),
                        ]);
                    } else {
                        $versionId = $version->id;
                    }

                    DB::table('scenario_rules')
                        ->where('organization_id', $organizationId)
                        ->where('rule_key', $definition['rule_key'])
                        ->where('template_version_id', $oldVersion->id)
                        ->update([
                            'template_version_id' => $versionId,
                            'version' => DB::raw('version + 1'),
                            'updated_at' => now(),
                        ]);
                });
        }
    }

    public function down(): void {}

    /** @return list<array{rule_key: string, template_key: string, name: string, old_body: string, body: string, variables: list<string>}> */
    private function definitions(): array
    {
        return [
            [
                'rule_key' => 'b2b-sales-call-ready-specialist',
                'template_key' => 'b2b-sales-call-ready-specialist',
                'name' => 'Готовый B2B-разговор для специалиста',
                'old_body' => 'B2B conversation with {{ client.full_name }} (#{{ sales_call.id }}) is scheduled for {{ sales_call.local_date }} at {{ sales_call.local_time }} ({{ sales_call.timezone }}). Open CRM: {{ sales_call.crm_url }}',
                'body' => 'Разговор о развитии бизнеса с клиентом {{ client.full_name }} (№{{ sales_call.id }}) запланирован на {{ sales_call.local_date }} в {{ sales_call.local_time }} ({{ sales_call.timezone }}). Telegram клиента: {{ client.telegram_contact }}. Открыть CRM: {{ sales_call.crm_url }}',
                'variables' => ['client.full_name', 'client.telegram_contact', 'sales_call.id', 'sales_call.local_date', 'sales_call.local_time', 'sales_call.timezone', 'sales_call.crm_url'],
            ],
            [
                'rule_key' => 'booking-created-specialist',
                'template_key' => 'booking-created-specialist',
                'name' => 'Новая заявка на запись для специалиста',
                'old_body' => 'New appointment request from {{ client.full_name }} for {{ booking.service_name }} on {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).',
                'body' => 'Новая заявка на запись от клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.',
                'variables' => ['client.full_name', 'client.telegram_contact', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone'],
            ],
            [
                'rule_key' => 'booking-confirmed-specialist',
                'template_key' => 'booking-confirmed-specialist',
                'name' => 'Подтверждение записи для специалиста',
                'old_body' => 'Appointment with {{ client.full_name }} for {{ booking.service_name }} is confirmed for {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).',
                'body' => 'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» подтверждена на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.',
                'variables' => ['client.full_name', 'client.telegram_contact', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone'],
            ],
            [
                'rule_key' => 'booking-rescheduled-specialist',
                'template_key' => 'booking-rescheduled-specialist',
                'name' => 'Перенос записи для специалиста',
                'old_body' => 'Appointment with {{ client.full_name }} for {{ booking.service_name }} was rescheduled to {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}).',
                'body' => 'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» перенесена на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}). Telegram клиента: {{ client.telegram_contact }}.',
                'variables' => ['client.full_name', 'client.telegram_contact', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone'],
            ],
            [
                'rule_key' => 'booking-cancelled-specialist',
                'template_key' => 'booking-cancelled-specialist',
                'name' => 'Отмена записи для специалиста',
                'old_body' => 'Appointment with {{ client.full_name }} for {{ booking.service_name }} on {{ booking.local_date }} at {{ booking.local_time }} ({{ booking.timezone }}) was cancelled.',
                'body' => 'Запись клиента {{ client.full_name }} на услугу «{{ booking.service_name }}» на {{ booking.local_date }} в {{ booking.local_time }} ({{ booking.timezone }}) отменена. Telegram клиента: {{ client.telegram_contact }}.',
                'variables' => ['client.full_name', 'client.telegram_contact', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone'],
            ],
        ];
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->orderBy('id')->pluck('id')->each(function (mixed $organizationId): void {
            foreach ($this->definitions() as $definition) {
                $template = DB::table('notification_templates')
                    ->where('organization_id', $organizationId)
                    ->where('template_key', $definition['template_key'])
                    ->where('locale', $definition['locale'])
                    ->first(['id']);
                if ($template === null) {
                    continue;
                }

                $rule = DB::table('scenario_rules')
                    ->where('organization_id', $organizationId)
                    ->where('rule_key', $definition['rule_key'])
                    ->first(['id', 'template_version_id']);
                if ($rule === null) {
                    continue;
                }

                $current = DB::table('notification_template_versions')->where('id', $rule->template_version_id)->first(['body']);
                if ($current !== null && strpos((string) $current->body, '\\n') === false && str_contains((string) $current->body, '{{ booking.location_label }}')) {
                    continue;
                }

                $latest = DB::table('notification_template_versions')
                    ->where('organization_id', $organizationId)
                    ->where('template_id', $template->id)
                    ->orderByDesc('version')
                    ->first(['id', 'version', 'body']);
                $versionId = $latest?->body === $definition['body']
                    ? $latest->id
                    : DB::table('notification_template_versions')->insertGetId([
                        'organization_id' => $organizationId,
                        'template_id' => $template->id,
                        'version' => (($latest === null ? 0 : (int) $latest->version) + 1),
                        'status' => 'published',
                        'subject' => null,
                        'body' => $definition['body'],
                        'variables' => json_encode($definition['variables'], JSON_THROW_ON_ERROR),
                        'created_by_user_id' => null,
                        'published_at' => now(),
                        'created_at' => now(),
                    ]);

                DB::table('scenario_rules')->where('id', $rule->id)->update([
                    'template_version_id' => $versionId,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void {}

    /** @return list<array{template_key: string, locale: string, rule_key: string, body: string, variables: list<string>}> */
    private function definitions(): array
    {
        $clientVariables = ['booking.specialist_name', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone', 'booking.visit_format_label', 'booking.location_label'];
        $specialistVariables = ['client.full_name', 'client.telegram_contact', 'booking.service_name', 'booking.local_date', 'booking.local_time', 'booking.timezone', 'booking.visit_format_label', 'booking.location_label'];
        $definitions = [];
        foreach (['booking-created' => 'Новая заявка', 'booking-confirmed' => 'Запись подтверждена', 'booking-rescheduled' => 'Запись перенесена', 'booking-cancelled' => 'Запись отменена'] as $key => $title) {
            foreach (['ru' => $title, 'en' => ucfirst(str_replace('-', ' ', $key))] as $locale => $localizedTitle) {
                $definitions[] = [
                    'template_key' => $key,
                    'locale' => $locale,
                    'rule_key' => $key.'-client-'.$locale,
                    'body' => $locale === 'ru'
                        ? "$localizedTitle\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}"
                        : "$localizedTitle\n{{ booking.specialist_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}",
                    'variables' => $clientVariables,
                ];
            }
            $definitions[] = [
                'template_key' => $key.'-specialist',
                'locale' => 'ru',
                'rule_key' => $key.'-specialist',
                'body' => "$title\nКлиент: {{ client.full_name }}\n{{ booking.service_name }}\n{{ booking.local_date }} · {{ booking.local_time }} ({{ booking.timezone }})\n{{ booking.visit_format_label }}\n{{ booking.location_label }}\nTelegram клиента: {{ client.telegram_contact }}.",
                'variables' => $specialistVariables,
            ];
        }

        return $definitions;
    }
};

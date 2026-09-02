<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'en' => [
                'body' => 'Your B2B conversation with {{ client.full_name }} (#{{ sales_call.id }}) is scheduled for {{ sales_call.local_date }} at {{ sales_call.local_time }} ({{ sales_call.timezone }}). Join: {{ sales_call.join_url }}',
                'new_body' => 'Your B2B conversation with {{ client.full_name }} (#{{ sales_call.id }}) is scheduled for {{ sales_call.local_date }} at {{ sales_call.local_time }} ({{ sales_call.timezone }}).',
            ],
            'ru' => [
                'body' => 'Разговор о развитии бизнеса с клиентом {{ client.full_name }} (№{{ sales_call.id }}) запланирован на {{ sales_call.local_date }} в {{ sales_call.local_time }} ({{ sales_call.timezone }}). Ссылка: {{ sales_call.join_url }}',
                'new_body' => 'Разговор о развитии бизнеса с клиентом {{ client.full_name }} (№{{ sales_call.id }}) запланирован на {{ sales_call.local_date }} в {{ sales_call.local_time }} ({{ sales_call.timezone }}).',
            ],
        ];

        DB::table('notification_templates')
            ->where('template_key', 'b2b-sales-call-ready')
            ->orderBy('id')
            ->get(['id', 'organization_id', 'locale'])
            ->each(function (object $template) use ($defaults): void {
                $default = $defaults[$template->locale] ?? null;
                if ($default === null) {
                    return;
                }

                $current = DB::table('notification_template_versions')
                    ->where('organization_id', $template->organization_id)
                    ->where('template_id', $template->id)
                    ->where('version', 1)
                    ->first(['id', 'body']);
                if ($current === null || $current->body !== $default['body']) {
                    return;
                }

                $version = DB::table('notification_template_versions')
                    ->where('organization_id', $template->organization_id)
                    ->where('template_id', $template->id)
                    ->where('version', 2)
                    ->first(['id']);
                $versionId = $version?->id;

                if ($versionId === null) {
                    $versionId = DB::table('notification_template_versions')->insertGetId([
                        'organization_id' => $template->organization_id,
                        'template_id' => $template->id,
                        'version' => 2,
                        'status' => 'published',
                        'subject' => null,
                        'body' => $default['new_body'],
                        'variables' => json_encode([
                            'client.full_name',
                            'sales_call.id',
                            'sales_call.local_date',
                            'sales_call.local_time',
                            'sales_call.timezone',
                        ], JSON_THROW_ON_ERROR),
                        'created_by_user_id' => null,
                        'published_at' => now(),
                        'created_at' => now(),
                    ]);
                }

                DB::table('scenario_rules')
                    ->where('organization_id', $template->organization_id)
                    ->whereIn('rule_key', [
                        'b2b-sales-call-ready-client-en',
                        'b2b-sales-call-ready-client-ru',
                    ])
                    ->where('template_version_id', $current->id)
                    ->update([
                        'template_version_id' => $versionId,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void {}
};

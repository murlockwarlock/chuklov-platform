<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ruleKeys = [
            'booking-created-client-en',
            'booking-created-client-ru',
            'booking-created-specialist',
            'booking-confirmed-client-en',
            'booking-confirmed-client-ru',
            'booking-confirmed-specialist',
            'booking-rescheduled-client-en',
            'booking-rescheduled-client-ru',
            'booking-rescheduled-specialist',
            'booking-cancelled-client-en',
            'booking-cancelled-client-ru',
            'booking-cancelled-specialist',
        ];

        DB::table('scenario_rules')
            ->whereIn('rule_key', $ruleKeys)
            ->orderBy('id')
            ->get(['id', 'organization_id', 'template_version_id'])
            ->each(function (object $rule): void {
                $current = DB::table('notification_template_versions')
                    ->where('organization_id', $rule->organization_id)
                    ->where('id', $rule->template_version_id)
                    ->first(['id', 'template_id', 'version', 'status', 'subject', 'body', 'variables']);

                if ($current === null) {
                    return;
                }

                $body = str_replace(
                    [
                        "{{ booking.visit_format_label }}\n{{ booking.location_label }}",
                        "{{ booking.visit_format_label }}\r\n{{ booking.location_label }}",
                        '{{ booking.visit_format_label }}\\n{{ booking.location_label }}',
                    ],
                    '{{ booking.visit_details }}',
                    (string) $current->body,
                );

                if ($body === (string) $current->body) {
                    return;
                }

                $variables = json_decode((string) $current->variables, true);
                $variables = is_array($variables) ? array_values(array_unique(array_map('strval', $variables))) : [];
                $variables = array_values(array_diff($variables, ['booking.visit_format_label', 'booking.location_label']));
                $variables[] = 'booking.visit_details';
                $latest = DB::table('notification_template_versions')
                    ->where('organization_id', $rule->organization_id)
                    ->where('template_id', $current->template_id)
                    ->orderByDesc('version')
                    ->first(['id', 'version', 'status', 'body', 'variables']);
                $latestVariables = $latest === null ? [] : json_decode((string) ($latest->variables ?? '[]'), true);
                $latestHasVisitDetails = is_array($latestVariables)
                    && in_array('booking.visit_details', array_map('strval', $latestVariables), true);
                $versionId = $latest !== null
                    && (string) $latest->status === 'published'
                    && (string) $latest->body === $body
                    && $latestHasVisitDetails
                    ? $latest->id
                    : DB::table('notification_template_versions')->insertGetId([
                        'organization_id' => $rule->organization_id,
                        'template_id' => $current->template_id,
                        'version' => ((int) ($latest?->version ?? 0)) + 1,
                        'status' => 'published',
                        'subject' => $current->subject,
                        'body' => $body,
                        'variables' => json_encode($variables, JSON_THROW_ON_ERROR),
                        'created_by_user_id' => null,
                        'published_at' => now(),
                        'created_at' => now(),
                    ]);

                DB::table('scenario_rules')
                    ->where('organization_id', $rule->organization_id)
                    ->where('id', $rule->id)
                    ->where('template_version_id', $current->id)
                    ->update([
                        'template_version_id' => $versionId,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void {}
};

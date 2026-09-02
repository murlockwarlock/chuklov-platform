<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            [
                'template_key' => 'post-session-follow-up-24h',
                'rule_suffix' => '24h',
                'name' => 'Post-session follow-up +24h',
                'bodies' => [
                    'en' => 'How are you feeling after your visit, {{ client.full_name }}? If any questions come up, write to us.',
                    'ru' => 'Как вы себя чувствуете после визита, {{ client.full_name }}? Если появились вопросы, напишите нам.',
                ],
            ],
            [
                'template_key' => 'post-session-follow-up-48h',
                'rule_suffix' => '48h',
                'name' => 'Post-session follow-up +48h',
                'bodies' => [
                    'en' => 'We hope your visit was useful, {{ client.full_name }}. Share your impressions when convenient.',
                    'ru' => 'Надеемся, визит был полезен, {{ client.full_name }}. Поделитесь впечатлениями, когда будет удобно.',
                ],
            ],
            [
                'template_key' => 'post-session-follow-up-72h',
                'rule_suffix' => '72h',
                'name' => 'Post-session follow-up +72h',
                'bodies' => [
                    'en' => '{{ client.full_name }}, if new thoughts or questions came up after your visit, we are here to support you.',
                    'ru' => '{{ client.full_name }}, если после визита появились новые мысли или вопросы, мы готовы вас поддержать.',
                ],
            ],
        ];

        DB::table('organizations')
            ->orderBy('id')
            ->pluck('id')
            ->each(function (mixed $organizationId) use ($definitions): void {
                foreach (['en', 'ru'] as $locale) {
                    $legacyTemplate = DB::table('notification_templates')
                        ->where('organization_id', $organizationId)
                        ->where('template_key', 'post-session-follow-up')
                        ->where('locale', $locale)
                        ->first(['id']);
                    $legacyVersionId = $legacyTemplate === null
                        ? null
                        : DB::table('notification_template_versions')
                            ->where('organization_id', $organizationId)
                            ->where('template_id', $legacyTemplate->id)
                            ->where('version', 1)
                            ->value('id');

                    foreach ($definitions as $definition) {
                        $template = DB::table('notification_templates')
                            ->where('organization_id', $organizationId)
                            ->where('template_key', $definition['template_key'])
                            ->where('locale', $locale)
                            ->first(['id']);
                        $timestamp = now();

                        if ($template === null) {
                            $templateId = DB::table('notification_templates')->insertGetId([
                                'organization_id' => $organizationId,
                                'template_key' => $definition['template_key'],
                                'name' => $definition['name'].' ('.$locale.')',
                                'locale' => $locale,
                                'purpose' => 'service',
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
                            ->where('version', 1)
                            ->first(['id']);
                        $versionId = $version?->id;

                        if ($versionId === null) {
                            $versionId = DB::table('notification_template_versions')->insertGetId([
                                'organization_id' => $organizationId,
                                'template_id' => $templateId,
                                'version' => 1,
                                'status' => 'published',
                                'subject' => null,
                                'body' => $definition['bodies'][$locale],
                                'variables' => json_encode(['client.full_name'], JSON_THROW_ON_ERROR),
                                'created_by_user_id' => null,
                                'published_at' => $timestamp,
                                'created_at' => $timestamp,
                            ]);
                        }

                        if ($legacyVersionId !== null) {
                            DB::table('scenario_rules')
                                ->where('organization_id', $organizationId)
                                ->where('rule_key', 'post-session-follow-up-'.$definition['rule_suffix'].'-'.$locale)
                                ->where('template_version_id', $legacyVersionId)
                                ->update([
                                    'template_version_id' => $versionId,
                                    'version' => DB::raw('version + 1'),
                                    'updated_at' => $timestamp,
                                ]);
                        }
                    }
                }
            });
    }

    public function down(): void {}
};

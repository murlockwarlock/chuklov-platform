<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->templateNames() as $templateKey => $name) {
            DB::table('notification_templates')
                ->where('template_key', $templateKey)
                ->whereIn('name', $this->technicalTemplateNamesForKey($templateKey))
                ->update(['name' => $name]);
        }

        foreach ($this->ruleNames() as $rulePattern => [$name, $technicalName]) {
            DB::table('scenario_rules')
                ->where('rule_key', 'like', $rulePattern)
                ->where('name', $technicalName)
                ->update(['name' => $name]);
        }
    }

    public function down(): void
    {
        foreach ($this->technicalTemplateNames() as $templateKey => $name) {
            DB::table('notification_templates')
                ->where('template_key', $templateKey)
                ->where('name', $this->templateNames()[$templateKey])
                ->update(['name' => $name]);
        }

        foreach ($this->technicalRuleNames() as $rulePattern => $name) {
            DB::table('scenario_rules')
                ->where('rule_key', 'like', $rulePattern)
                ->where('name', $this->ruleNames()[$rulePattern][0])
                ->update(['name' => $name]);
        }
    }

    /** @return array<string, string> */
    private function templateNames(): array
    {
        return [
            'post-session-follow-up' => 'После визита',
            'post-session-follow-up-24h' => 'Через день после визита',
            'post-session-follow-up-48h' => 'Через два дня после визита',
            'post-session-follow-up-72h' => 'Поддержка после визита',
            'b2b-sales-call-ready' => 'B2B-разговор готов',
            'b2b-sales-call-ready-specialist' => 'B2B-разговор для специалиста',
            'booking-created' => 'Новая запись',
            'booking-created-specialist' => 'Новая запись для специалиста',
            'booking-confirmed' => 'Подтверждение записи',
            'booking-confirmed-specialist' => 'Подтверждение записи для специалиста',
            'booking-rescheduled' => 'Перенос записи',
            'booking-rescheduled-specialist' => 'Перенос записи для специалиста',
            'booking-cancelled' => 'Отмена записи',
            'booking-cancelled-specialist' => 'Отмена записи для специалиста',
            'booking-completed-feedback' => 'Оценка визита',
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function ruleNames(): array
    {
        return [
            'post-session-follow-up-24h-%' => ['Через день после визита', 'Post-session follow-up +24h'],
            'post-session-follow-up-48h-%' => ['Через два дня после визита', 'Post-session follow-up +48h'],
            'post-session-follow-up-72h-%' => ['Поддержка после визита', 'Post-session follow-up +72h'],
            'b2b-sales-call-ready-client-%' => ['B2B-разговор для клиента', 'B2B sales call ready for client'],
            'b2b-sales-call-ready-specialist' => ['B2B-разговор для специалиста', 'B2B sales call ready for specialist'],
            'booking-created-client-%' => ['Новая запись для клиента', 'Booking request received by client'],
            'booking-created-specialist' => ['Новая запись для специалиста', 'booking-created-specialist for specialist'],
            'booking-confirmed-client-%' => ['Подтверждение записи для клиента', 'Booking confirmed for client'],
            'booking-confirmed-specialist' => ['Подтверждение записи для специалиста', 'Booking confirmed for specialist'],
            'booking-rescheduled-client-%' => ['Перенос записи для клиента', 'booking-rescheduled for client'],
            'booking-rescheduled-specialist' => ['Перенос записи для специалиста', 'booking-rescheduled for specialist'],
            'booking-cancelled-client-%' => ['Отмена записи для клиента', 'booking-cancelled for client'],
            'booking-cancelled-specialist' => ['Отмена записи для специалиста', 'booking-cancelled for specialist'],
            'booking-completed-feedback-%' => ['Оценка визита', 'Оценка визита после завершения'],
        ];
    }

    /** @return list<string> */
    private function technicalTemplateNamesForKey(string $templateKey): array
    {
        $technicalName = $this->technicalTemplateNames()[$templateKey];

        return [$technicalName, $templateKey];
    }

    /** @return array<string, string> */
    private function technicalTemplateNames(): array
    {
        return [
            'post-session-follow-up' => 'Post-session follow-up',
            'post-session-follow-up-24h' => 'Post-session follow-up +24h',
            'post-session-follow-up-48h' => 'Post-session follow-up +48h',
            'post-session-follow-up-72h' => 'Post-session follow-up +72h',
            'b2b-sales-call-ready' => 'B2B sales call ready',
            'b2b-sales-call-ready-specialist' => 'b2b-sales-call-ready-specialist',
            'booking-created' => 'Booking request received',
            'booking-created-specialist' => 'booking-created-specialist',
            'booking-confirmed' => 'Booking confirmed',
            'booking-confirmed-specialist' => 'booking-confirmed-specialist',
            'booking-rescheduled' => 'booking-rescheduled',
            'booking-rescheduled-specialist' => 'booking-rescheduled-specialist',
            'booking-cancelled' => 'booking-cancelled',
            'booking-cancelled-specialist' => 'booking-cancelled-specialist',
            'booking-completed-feedback' => 'Оценка визита',
        ];
    }

    /** @return array<string, string> */
    private function technicalRuleNames(): array
    {
        return [
            'post-session-follow-up-24h-%' => 'Post-session follow-up +24h',
            'post-session-follow-up-48h-%' => 'Post-session follow-up +48h',
            'post-session-follow-up-72h-%' => 'Post-session follow-up +72h',
            'b2b-sales-call-ready-client-%' => 'B2B sales call ready for client',
            'b2b-sales-call-ready-specialist' => 'B2B sales call ready for specialist',
            'booking-created-client-%' => 'Booking request received by client',
            'booking-created-specialist' => 'booking-created-specialist for specialist',
            'booking-confirmed-client-%' => 'Booking confirmed for client',
            'booking-confirmed-specialist' => 'booking-confirmed-specialist for specialist',
            'booking-rescheduled-client-%' => 'booking-rescheduled for client',
            'booking-rescheduled-specialist' => 'booking-rescheduled for specialist',
            'booking-cancelled-client-%' => 'booking-cancelled for client',
            'booking-cancelled-specialist' => 'booking-cancelled for specialist',
            'booking-completed-feedback-%' => 'Оценка визита после завершения',
        ];
    }
};

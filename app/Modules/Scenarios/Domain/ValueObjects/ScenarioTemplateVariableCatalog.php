<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

use App\Modules\Scenarios\Domain\Enums\ScenarioRulePurpose;
use InvalidArgumentException;

final class ScenarioTemplateVariableCatalog
{
    /** @var list<string> */
    private const ALLOWED = [
        'client.full_name',
        'client.language',
        'client.telegram_contact',
        'companion.escalation_id',
        'companion.crm_url',
        'companion.reason',
        'payout.id',
        'payout.amount',
        'payout.currency',
        'payout.status',
        'payout.status_label',
        'payout.requested_at',
        'payout.processed_at',
        'payout.reason',
        'payout.crm_url',
        'payout.portal_url',
        'referral_link',
        'booking.id',
        'booking.status',
        'booking.visit_format',
        'booking.visit_format_label',
        'booking.visit_details',
        'booking.service_name',
        'booking.specialist_name',
        'booking.location',
        'booking.location_label',
        'booking.location_name',
        'booking.location_address',
        'booking.location_timezone',
        'booking.location_area',
        'booking.crm_url',
        'booking.reminder_offset_label',
        'booking.starts_at',
        'booking.ends_at',
        'booking.local_date',
        'booking.local_time',
        'booking.timezone',
        'booking.completed_at',
        'feedback.url',
        'onboarding.stage',
        'onboarding.completed',
        'survey.title',
        'survey.version',
        'survey.completed_at',
        'tracker.task_title',
        'tracker.task_type',
        'tracker.frequency',
        'tracker.portal_url',
        'sales_call.id',
        'sales_call.local_date',
        'sales_call.local_time',
        'sales_call.timezone',
        'sales_call.join_url',
        'sales_call.crm_url',
        'sales_call.specialist_name',
        'knowledge.source_title',
        'knowledge.revision_version',
        'knowledge.crm_url',
    ];

    /** @return list<string> */
    public static function allowed(): array
    {
        return self::ALLOWED;
    }

    /** @return list<string> */
    public static function allowedForPurpose(ScenarioRulePurpose|string $purpose): array
    {
        $purpose = $purpose instanceof ScenarioRulePurpose ? $purpose : ScenarioRulePurpose::tryFrom($purpose);

        if ($purpose === null) {
            return [];
        }

        return $purpose === ScenarioRulePurpose::Marketing
            ? ['client.full_name', 'client.language', 'referral_link']
            : self::ALLOWED;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'client.full_name' => 'Имя клиента',
            'client.language' => 'Язык клиента',
            'client.telegram_contact' => 'Telegram клиента',
            'companion.escalation_id' => 'Номер обращения',
            'companion.crm_url' => 'Ссылка на обращение в CRM',
            'companion.reason' => 'Причина обращения',
            'payout.id' => 'Номер выплаты',
            'payout.amount' => 'Сумма выплаты',
            'payout.currency' => 'Валюта выплаты',
            'payout.status' => 'Внутренний статус выплаты',
            'payout.status_label' => 'Статус выплаты',
            'payout.requested_at' => 'Время запроса выплаты',
            'payout.processed_at' => 'Время обработки выплаты',
            'payout.reason' => 'Причина по выплате',
            'payout.crm_url' => 'Ссылка на выплату в CRM',
            'payout.portal_url' => 'Ссылка на выплаты партнёра',
            'referral_link' => 'Персональная реферальная ссылка',
            'booking.id' => 'Номер записи',
            'booking.status' => 'Статус записи',
            'booking.visit_format' => 'Формат визита',
            'booking.visit_format_label' => 'Название формата визита',
            'booking.visit_details' => 'Формат и место встречи',
            'booking.service_name' => 'Название услуги',
            'booking.specialist_name' => 'Имя специалиста',
            'booking.location' => 'Адрес приёма',
            'booking.location_label' => 'Место встречи',
            'booking.location_name' => 'Название локации',
            'booking.location_address' => 'Адрес локации',
            'booking.location_timezone' => 'Часовой пояс локации',
            'booking.location_area' => 'Район выезда',
            'booking.crm_url' => 'Ссылка на запись в CRM',
            'booking.reminder_offset_label' => 'Время до визита',
            'booking.starts_at' => 'Дата и время записи',
            'booking.ends_at' => 'Окончание записи',
            'booking.local_date' => 'Местная дата записи',
            'booking.local_time' => 'Местное время записи',
            'booking.timezone' => 'Часовой пояс записи',
            'booking.completed_at' => 'Время завершения визита',
            'feedback.url' => 'Ссылка на оценку визита',
            'onboarding.stage' => 'Текущий этап заполнения',
            'onboarding.completed' => 'Заполнение завершено',
            'survey.title' => 'Название теста',
            'survey.version' => 'Версия теста',
            'survey.completed_at' => 'Время завершения теста',
            'tracker.task_title' => 'Название задачи трекера',
            'tracker.task_type' => 'Тип задачи трекера',
            'tracker.frequency' => 'Периодичность задачи трекера',
            'tracker.portal_url' => 'Ссылка на трекер',
            'sales_call.id' => 'Номер разговора',
            'sales_call.local_date' => 'Дата разговора',
            'sales_call.local_time' => 'Время разговора',
            'sales_call.timezone' => 'Часовой пояс разговора',
            'sales_call.join_url' => 'Ссылка участника Zoom',
            'sales_call.crm_url' => 'Ссылка на B2B-лид в CRM',
            'sales_call.specialist_name' => 'Имя специалиста',
            'knowledge.source_title' => 'Название материала',
            'knowledge.revision_version' => 'Версия материала',
            'knowledge.crm_url' => 'Ссылка на материал в CRM',
        ];
    }

    /** @return array<string, string> */
    public static function labelsForPurpose(ScenarioRulePurpose|string|null $purpose): array
    {
        if ($purpose === null || $purpose === '') {
            return self::labels();
        }

        $allowed = self::allowedForPurpose($purpose);

        return array_intersect_key(self::labels(), array_flip($allowed));
    }

    /** @return list<string> */
    public static function used(string ...$contents): array
    {
        $used = [];

        foreach ($contents as $content) {
            preg_match_all('/\{\{\s*([a-z][a-z0-9_.]*)\s*\}\}/', $content, $matches);

            foreach ($matches[1] as $variable) {
                if (! in_array($variable, self::ALLOWED, true)) {
                    throw new InvalidArgumentException('The notification template contains an unsupported variable.');
                }

                $used[] = $variable;
            }
        }

        return array_values(array_unique($used));
    }
}

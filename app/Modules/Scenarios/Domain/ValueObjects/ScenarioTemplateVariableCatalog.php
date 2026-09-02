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
        'booking.id',
        'booking.status',
        'booking.visit_format',
        'booking.service_name',
        'booking.specialist_name',
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
        'sales_call.id',
        'sales_call.local_date',
        'sales_call.local_time',
        'sales_call.timezone',
        'sales_call.join_url',
        'sales_call.crm_url',
        'sales_call.specialist_name',
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
            ? ['client.full_name', 'client.language']
            : self::ALLOWED;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'client.full_name' => 'Имя клиента',
            'client.language' => 'Язык клиента',
            'client.telegram_contact' => 'Telegram клиента',
            'booking.id' => 'Номер записи',
            'booking.status' => 'Статус записи',
            'booking.visit_format' => 'Формат визита',
            'booking.service_name' => 'Название услуги',
            'booking.specialist_name' => 'Имя специалиста',
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
            'sales_call.id' => 'Номер разговора',
            'sales_call.local_date' => 'Дата разговора',
            'sales_call.local_time' => 'Время разговора',
            'sales_call.timezone' => 'Часовой пояс разговора',
            'sales_call.join_url' => 'Ссылка участника Zoom',
            'sales_call.crm_url' => 'Ссылка на B2B-лид в CRM',
            'sales_call.specialist_name' => 'Имя специалиста',
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

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
        'booking.id',
        'booking.status',
        'booking.visit_format',
        'booking.service_name',
        'booking.starts_at',
        'booking.ends_at',
        'booking.completed_at',
        'onboarding.stage',
        'onboarding.completed',
        'survey.title',
        'survey.version',
        'survey.completed_at',
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
            'booking.id' => 'Номер записи',
            'booking.status' => 'Статус записи',
            'booking.visit_format' => 'Формат визита',
            'booking.service_name' => 'Название услуги',
            'booking.starts_at' => 'Дата и время записи',
            'booking.ends_at' => 'Окончание записи',
            'booking.completed_at' => 'Время завершения визита',
            'onboarding.stage' => 'Текущий этап заполнения',
            'onboarding.completed' => 'Заполнение завершено',
            'survey.title' => 'Название теста',
            'survey.version' => 'Версия теста',
            'survey.completed_at' => 'Время завершения теста',
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

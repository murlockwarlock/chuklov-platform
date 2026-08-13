<?php

namespace App\Modules\Scenarios\Domain\ValueObjects;

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
    ];

    /** @return list<string> */
    public static function allowed(): array
    {
        return self::ALLOWED;
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

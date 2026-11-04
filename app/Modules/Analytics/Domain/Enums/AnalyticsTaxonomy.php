<?php

namespace App\Modules\Analytics\Domain\Enums;

enum AnalyticsTaxonomy: string
{
    case Problems = 'problems';
    case Causes = 'causes';
    case Goals = 'goals';
    case Feedback = 'feedback';

    public function label(): string
    {
        return match ($this) {
            self::Problems => 'Проблемы и боли',
            self::Causes => 'Причины нарушений',
            self::Goals => 'Цели и задачи',
            self::Feedback => 'Темы обратной связи',
        };
    }
}

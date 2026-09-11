<?php

namespace App\Modules\Analytics\Domain\Enums;

enum ClientSegment: string
{
    case All = 'all';
    case NewClients = 'new';
    case Regular = 'regular';
    case Dormant = 'dormant';
    case NoCompletedVisits = 'no_visits';
    case Restricted = 'restricted';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::All->value => 'Все клиенты',
            self::NewClients->value => 'Новые',
            self::Regular->value => 'Постоянные',
            self::Dormant->value => 'Спящие',
            self::NoCompletedVisits->value => 'Не посещали',
            self::Restricted->value => 'Ограничена запись',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}

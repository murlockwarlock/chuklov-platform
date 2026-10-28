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
    case Blacklist = 'blacklist';
    case WarmLead = 'warm_lead';
    case Sleeping = 'sleeping';
    case ColdLead = 'cold_lead';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::All->value => 'Все клиенты',
            self::NewClients->value => 'Новые',
            self::Regular->value => 'Постоянные',
            self::Dormant->value => 'Неактивные',
            self::NoCompletedVisits->value => 'Не посещали',
            self::Restricted->value => 'Ограничена запись',
            self::Blacklist->value => 'Чёрный список',
            self::WarmLead->value => 'Тёплые лиды',
            self::Sleeping->value => 'Спящие (более года)',
            self::ColdLead->value => 'Не явились',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}

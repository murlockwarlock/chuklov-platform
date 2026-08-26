<?php

namespace App\Modules\Broadcasts\Application;

final class BroadcastSegmentSummary
{
    /** @param list<array{key: string, operator: string, value: mixed}> $filters */
    public function make(array $filters): string
    {
        if ($filters === []) {
            return 'Все подходящие клиенты организации';
        }

        return collect($filters)->map(function (array $filter): string {
            $value = is_array($filter['value']) ? implode(', ', $filter['value']) : ($filter['value'] === true ? 'да' : ($filter['value'] === false ? 'нет' : (string) $filter['value']));

            return (SegmentDefinition::labels()[$filter['key']] ?? 'Условие').' · '.$value;
        })->implode('; ');
    }
}

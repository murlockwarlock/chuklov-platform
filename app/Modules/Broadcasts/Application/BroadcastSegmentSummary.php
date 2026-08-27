<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;

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
            if ($filter['key'] === 'b2b_specialist_answer') {
                $values = is_array($filter['value']) ? $filter['value'] : [$filter['value']];
                $value = implode(', ', array_map(
                    static fn (mixed $item): string => match (B2bSpecialistAnswer::tryFrom((string) $item)) {
                        B2bSpecialistAnswer::Yes => '#Массажист_B2B',
                        B2bSpecialistAnswer::No => 'Не специалист',
                        default => (string) $item,
                    },
                    $values,
                ));
            }

            return (SegmentDefinition::labels()[$filter['key']] ?? 'Условие').' · '.$value;
        })->implode('; ');
    }
}

<?php

namespace App\Modules\Sessions\Application;

final readonly class AggregateMedicalSessionCauses
{
    /**
     * @param array<int, mixed> $testResults
     * @return list<array{cause_id:int,test_count:int,is_primary:bool}>
     */
    public function handle(array $testResults): array
    {
        $summary = [];

        foreach ($testResults as $test) {
            if (! is_array($test)) {
                continue;
            }

            $causeIds = [];
            $primaryIds = [];
            $walk = function (mixed $value, ?string $key = null) use (&$walk, &$causeIds, &$primaryIds): void {
                if ($key === 'cause_ids') {
                    foreach (is_array($value) ? $value : [$value] as $id) {
                        if (is_numeric($id) && (int) $id > 0) {
                            $causeIds[(int) $id] = true;
                        }
                    }

                    return;
                }
                if ($key === 'primary_cause_id') {
                    if (is_numeric($value) && (int) $value > 0) {
                        $primaryIds[(int) $value] = true;
                    }

                    return;
                }
                if (is_array($value)) {
                    foreach ($value as $childKey => $child) {
                        $walk($child, is_string($childKey) ? $childKey : null);
                    }
                }
            };
            $walk($test['structured'] ?? $test['structured_payload'] ?? $test);
            $walk(['cause_ids' => $test['cause_ids'] ?? []]);

            foreach (array_keys($causeIds) as $causeId) {
                $summary[$causeId] ??= ['cause_id' => $causeId, 'test_count' => 0, 'is_primary' => false];
                $summary[$causeId]['test_count']++;
                if (isset($primaryIds[$causeId])) {
                    $summary[$causeId]['is_primary'] = true;
                }
            }
        }

        usort($summary, static function (array $left, array $right): int {
            return [(int) $right['is_primary'], $right['test_count'], $left['cause_id']]
                <=> [(int) $left['is_primary'], $left['test_count'], $right['cause_id']];
        });

        return array_values($summary);
    }
}

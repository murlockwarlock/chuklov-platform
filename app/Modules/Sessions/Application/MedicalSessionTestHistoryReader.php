<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use DateTimeInterface;

final readonly class MedicalSessionTestHistoryReader
{
    public function __construct(private MedicalSessionTestReader $reader) {}

    /** @return list<array<string, mixed>> */
    public function previousForClient(Client $client, ?DateTimeInterface $before = null, ?int $excludeSessionId = null): array
    {
        $query = MedicalSession::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->when($excludeSessionId !== null, fn ($query) => $query->where('id', '<>', $excludeSessionId))
            ->when($before !== null, fn ($query) => $query->where('occurred_at', '<', $before))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $previous = $query->first();
        if (! $previous instanceof MedicalSession) {
            return [];
        }

        $history = array_values(array_filter(array_map(static function ($test): ?array {
            $violated = $test->afterViolationCount > 0 || $test->afterAbnormal;
            if (! $violated && ! $test->priority) {
                return null;
            }

            return [
                'test_id' => $test->testId,
                'priority' => $test->priority,
                'priority_regions' => $test->priorityRegions,
                'stabilized' => $test->stabilized,
                'previous_after' => $test->structuredPayload !== null
                    ? __('Нарушений: :count', ['count' => $test->afterViolationCount])
                    : ($test->afterLabel ?? __('Нарушение')),
            ];
        }, $this->reader->tests($previous))));

        usort($history, static function (array $left, array $right): int {
            return ((int) $right['priority']) <=> ((int) $left['priority']);
        });

        return $history;
    }
}

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

        return array_values(array_filter(array_map(static function ($test): ?array {
            $violated = $test->afterViolationCount > 0 || $test->afterAbnormal;
            if (! $violated) {
                return null;
            }

            return [
                'test_id' => $test->testId,
                'previous_after' => $test->structuredPayload !== null
                    ? __('Нарушений: :count', ['count' => $test->afterViolationCount])
                    : ($test->afterLabel ?? __('Нарушение')),
            ];
        }, $this->reader->tests($previous))));
    }
}

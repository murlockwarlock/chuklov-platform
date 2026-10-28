<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\MedicalPainData;
use App\Modules\MedicalProfiles\Application\MedicalProfilePainReader;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Sessions\Domain\Models\MedicalSessionPain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class MedicalSessionPainInitializer
{
    public function __construct(
        private MedicalProfilePainReader $profilePains,
        private MedicalSessionPainWriter $writer,
        private MedicalSessionPainReader $sessionPains,
    ) {}

    /** @return list<array<string, mixed>> */
    public function defaultsForClient(Client $client, ?MedicalSession $exclude = null): array
    {
        $previousQuery = MedicalSession::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey());

        if ($exclude instanceof MedicalSession) {
            $previousQuery
                ->where('id', '<>', $exclude->getKey())
                ->where(function ($nested) use ($exclude): void {
                    $nested->where('occurred_at', '<', $exclude->occurred_at)
                        ->orWhere(function ($tie) use ($exclude): void {
                            $tie->where('occurred_at', $exclude->occurred_at)->where('id', '<', $exclude->getKey());
                        });
                });
        }

        $previous = $previousQuery
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        if ($previous instanceof MedicalSession && $previous->pains()->exists()) {
            $previousData = collect($this->sessionPains->forSession($previous))->keyBy('lineageKey');

            return array_values($previous->pains()->orderBy('sort_order')->orderBy('id')->get()->map(function (MedicalSessionPain $pain) use ($previousData): array {
                $data = $previousData->get((string) $pain->lineage_key);

                return [
                    'lineage_key' => (string) $pain->lineage_key,
                    'name' => $data instanceof MedicalPainData ? $data->name : '',
                    'frequency_mode' => $pain->frequency_mode,
                    'frequency_count' => $pain->frequency_count,
                    'frequency_period_unit' => $pain->frequency_period_unit,
                    'time_of_day' => $pain->time_of_day ?: ['all'],
                    'before_score' => null,
                    'after_score' => null,
                    'previous_after_score' => $data instanceof MedicalPainData ? $data->afterScore : null,
                    'tag_ids' => $data instanceof MedicalPainData ? $data->tagIds : [],
                ];
            })->all());
        }

        $profile = $client->medicalProfile()->first();
        if ($profile === null) {
            return [];
        }

        return array_values(collect($this->profilePains->forProfile($profile))->map(static fn (MedicalPainData $pain): array => [
            'lineage_key' => $pain->lineageKey ?: Str::uuid()->toString(),
            'name' => $pain->name,
            'frequency_mode' => 'permanent',
            'frequency_count' => null,
            'frequency_period_unit' => null,
            'time_of_day' => ['all'],
            'before_score' => $pain->score,
            'after_score' => null,
            'previous_after_score' => null,
            'tag_ids' => $pain->tagIds,
        ])->all());
    }

    public function initialize(MedicalSession $session, int $keyVersion): void
    {
        DB::transaction(function () use ($session, $keyVersion): void {
            $lockedSession = MedicalSession::query()
                ->where('organization_id', $session->organization_id)
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedSession->pains()->exists()) {
                return;
            }

            $client = $lockedSession->client()->firstOrFail();
            $defaults = $this->defaultsForClient($client, $lockedSession);
            if ($defaults === []) {
                return;
            }

            $this->writer->replace($lockedSession, $defaults, $keyVersion, allowInactiveFrequencyPeriods: true);
        });
    }
}

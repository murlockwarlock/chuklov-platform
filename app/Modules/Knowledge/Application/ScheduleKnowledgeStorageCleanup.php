<?php

namespace App\Modules\Knowledge\Application;

use App\Modules\Knowledge\Domain\Enums\KnowledgeStorageCleanupStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeStorageCleanupOperation;
use App\Modules\Knowledge\Jobs\ProcessKnowledgeStorageCleanup;
use Carbon\CarbonImmutable;

final class ScheduleKnowledgeStorageCleanup
{
    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $staleAt = $now->subSeconds($this->boundedConfig('stale_after_seconds', 300, 60, 86400));
        $limit = $this->boundedConfig('batch_size', 100, 1, 500);
        $operations = KnowledgeStorageCleanupOperation::query()
            ->where(function ($query) use ($now, $staleAt): void {
                $query->where(function ($query) use ($now): void {
                    $query->whereIn('status', [KnowledgeStorageCleanupStatus::Pending->value, KnowledgeStorageCleanupStatus::Retryable->value])
                        ->where('available_at', '<=', $now);
                })->orWhere(function ($query) use ($staleAt): void {
                    $query->where('status', KnowledgeStorageCleanupStatus::Processing->value)
                        ->where(function ($query) use ($staleAt): void {
                            $query->whereNull('processing_started_at')->orWhere('processing_started_at', '<=', $staleAt);
                        });
                });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'organization_id']);

        foreach ($operations as $operation) {
            ProcessKnowledgeStorageCleanup::dispatch((int) $operation->organization_id, (int) $operation->getKey())
                ->onQueue((string) config('rag.cleanup.queue', 'default'));
        }

        return $operations->count();
    }

    private function boundedConfig(string $key, int $default, int $minimum, int $maximum): int
    {
        return min($maximum, max($minimum, (int) config('rag.cleanup.'.$key, $default)));
    }
}

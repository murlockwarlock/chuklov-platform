<?php

namespace App\Modules\Knowledge\Application;

use App\Modules\Knowledge\Domain\Enums\KnowledgeStorageCleanupStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeStorageCleanupOperation as CleanupOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProcessKnowledgeStorageCleanupOperation
{
    private const MAX_CONFIGURED_ATTEMPTS = 10;

    private const RETRYABLE_ERROR_CODES = [
        'storage_delete_failed',
        'storage_delete_exception',
    ];

    public function handle(int $organizationId, int $operationId): void
    {
        $operation = $this->claim($organizationId, $operationId);

        if ($operation === null) {
            return;
        }

        if (! $this->hasSafeStorageIdentity($operation)) {
            $this->markProtected($operation, 'invalid_storage_identity');

            return;
        }

        $canDelete = DB::transaction(function () use ($operation): bool {
            $current = CleanupOperation::query()
                ->where('organization_id', $operation->organization_id)
                ->whereKey($operation->getKey())
                ->lockForUpdate()
                ->first();

            if ($current === null
                || $current->status !== KnowledgeStorageCleanupStatus::Processing
                || $current->processing_token !== $operation->processing_token) {
                return false;
            }

            $referenced = DB::table('knowledge_revisions')
                ->where('storage_disk', $current->storage_disk)
                ->where('storage_path', $current->storage_path)
                ->lockForUpdate()
                ->first(['id']) !== null;

            if ($referenced) {
                $current->forceFill([
                    'status' => KnowledgeStorageCleanupStatus::Protected,
                    'processing_started_at' => null,
                    'processing_token' => null,
                    'processed_at' => CarbonImmutable::now(),
                    'error_code' => 'storage_object_referenced',
                ])->save();

                return false;
            }

            return true;
        });

        if (! $canDelete) {
            return;
        }

        try {
            $deleted = Storage::disk($operation->storage_disk)->delete($operation->storage_path);
        } catch (Throwable) {
            $this->markRetryable($operation, 'storage_delete_exception');

            return;
        }

        if (! $deleted) {
            $this->markRetryable($operation, 'storage_delete_failed');

            return;
        }

        $this->markSucceeded($operation);
    }

    private function claim(int $organizationId, int $operationId): ?CleanupOperation
    {
        return DB::transaction(function () use ($organizationId, $operationId): ?CleanupOperation {
            $operation = CleanupOperation::query()
                ->where('organization_id', $organizationId)
                ->whereKey($operationId)
                ->lockForUpdate()
                ->first();

            if ($operation === null || $operation->status->isTerminal()) {
                return null;
            }

            $now = CarbonImmutable::now();
            $staleAt = $now->subSeconds($this->config('stale_after_seconds', 300, 60, 86400));

            if ($operation->status === KnowledgeStorageCleanupStatus::Processing
                && $operation->processing_started_at !== null
                && $operation->processing_started_at->greaterThan($staleAt)) {
                return null;
            }

            if ($operation->attempts >= $this->maxAttempts()) {
                $operation->forceFill([
                    'status' => KnowledgeStorageCleanupStatus::Failed,
                    'processing_started_at' => null,
                    'processing_token' => null,
                    'processed_at' => $now,
                    'error_code' => $this->exhaustedErrorCode($operation->error_code),
                ])->save();

                return null;
            }

            if ($operation->status !== KnowledgeStorageCleanupStatus::Processing
                && $operation->available_at->greaterThan($now)) {
                return null;
            }

            $operation->forceFill([
                'status' => KnowledgeStorageCleanupStatus::Processing,
                'attempts' => $operation->attempts + 1,
                'processing_started_at' => $now,
                'processing_token' => bin2hex(random_bytes(32)),
                'processed_at' => null,
                'error_code' => null,
            ])->save();

            return $operation->fresh();
        });
    }

    private function hasSafeStorageIdentity(CleanupOperation $operation): bool
    {
        $disk = $operation->getRawOriginal('storage_disk');
        $path = $operation->getRawOriginal('storage_path');
        $disks = config('filesystems.disks');

        return is_string($disk)
            && $disk !== ''
            && mb_strlen($disk) <= 40
            && is_array($disks)
            && array_key_exists($disk, $disks)
            && is_string($path)
            && $path !== ''
            && mb_strlen($path) <= 500
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\0")
            && ! preg_match('~(^|/)\.\.?(/|$)~', $path);
    }

    private function markProtected(CleanupOperation $operation, string $errorCode): void
    {
        DB::transaction(function () use ($operation, $errorCode): void {
            $current = $this->currentProcessingOperation($operation);

            if ($current === null) {
                return;
            }

            $current->forceFill([
                'status' => KnowledgeStorageCleanupStatus::Protected,
                'processing_started_at' => null,
                'processing_token' => null,
                'processed_at' => CarbonImmutable::now(),
                'error_code' => $errorCode,
            ])->save();
        });
    }

    private function markRetryable(CleanupOperation $operation, string $errorCode): void
    {
        DB::transaction(function () use ($operation, $errorCode): void {
            $current = $this->currentProcessingOperation($operation);

            if ($current === null) {
                return;
            }

            $status = $current->attempts >= $this->maxAttempts()
                ? KnowledgeStorageCleanupStatus::Failed
                : KnowledgeStorageCleanupStatus::Retryable;

            $current->forceFill([
                'status' => $status,
                'available_at' => $status === KnowledgeStorageCleanupStatus::Retryable
                    ? CarbonImmutable::now()->addSeconds($this->retryDelay((int) $current->attempts))
                    : $current->available_at,
                'processing_started_at' => null,
                'processing_token' => null,
                'processed_at' => $status === KnowledgeStorageCleanupStatus::Failed ? CarbonImmutable::now() : null,
                'error_code' => $this->retryErrorCode($errorCode),
            ])->save();
        });
    }

    private function markSucceeded(CleanupOperation $operation): void
    {
        DB::transaction(function () use ($operation): void {
            $current = $this->currentProcessingOperation($operation);

            if ($current === null) {
                return;
            }

            $current->forceFill([
                'status' => KnowledgeStorageCleanupStatus::Succeeded,
                'processing_started_at' => null,
                'processing_token' => null,
                'processed_at' => CarbonImmutable::now(),
                'error_code' => null,
            ])->save();
        });
    }

    private function currentProcessingOperation(CleanupOperation $operation): ?CleanupOperation
    {
        return CleanupOperation::query()
            ->where('organization_id', $operation->organization_id)
            ->whereKey($operation->getKey())
            ->where('status', KnowledgeStorageCleanupStatus::Processing->value)
            ->where('processing_token', $operation->processing_token)
            ->lockForUpdate()
            ->first();
    }

    private function retryDelay(int $attempts): int
    {
        $base = $this->config('retry_after_seconds', 60, 1, 86400);
        $maximum = $this->config('max_retry_after_seconds', 3600, $base, 604800);
        $exponent = min(6, max(0, $attempts - 1));

        return min($maximum, $base * (2 ** $exponent));
    }

    private function maxAttempts(): int
    {
        return $this->config('max_attempts', 5, 1, self::MAX_CONFIGURED_ATTEMPTS);
    }

    private function exhaustedErrorCode(?string $errorCode): string
    {
        return in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)
            ? $errorCode
            : 'retry_exhausted';
    }

    private function retryErrorCode(string $errorCode): string
    {
        return in_array($errorCode, self::RETRYABLE_ERROR_CODES, true)
            ? $errorCode
            : 'storage_delete_exception';
    }

    private function config(string $key, int $default, int $minimum, int $maximum): int
    {
        return min($maximum, max($minimum, (int) config('rag.cleanup.'.$key, $default)));
    }
}

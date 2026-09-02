<?php

namespace App\Modules\Knowledge\Domain\Models;

use App\Modules\Knowledge\Domain\Enums\KnowledgeStorageCleanupStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $cleanup_key
 * @property string $storage_disk
 * @property string $storage_path
 * @property KnowledgeStorageCleanupStatus $status
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $processing_started_at
 * @property string|null $processing_token
 * @property CarbonImmutable|null $processed_at
 * @property string|null $error_code
 */
#[Fillable([
    'organization_id',
    'cleanup_key',
    'storage_disk',
    'storage_path',
    'status',
    'attempts',
    'available_at',
    'processing_started_at',
    'processing_token',
    'processed_at',
    'error_code',
])]
class KnowledgeStorageCleanupOperation extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'status' => KnowledgeStorageCleanupStatus::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}

<?php

namespace App\Modules\Knowledge\Domain\Models;

use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $organization_id
 * @property KnowledgeSourceType $type
 * @property string $title
 * @property string|null $category
 * @property KnowledgeSourceStatus $status
 * @property int|null $active_revision_id
 * @property-read Organization $organization
 * @property-read KnowledgeRevision|null $activeRevision
 * @property-read KnowledgeRevision|null $latestRevision
 */
class KnowledgeSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => KnowledgeSourceType::class,
            'status' => KnowledgeSourceStatus::class,
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<KnowledgeRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(KnowledgeRevision::class);
    }

    /** @return BelongsTo<KnowledgeRevision, $this> */
    public function activeRevision(): BelongsTo
    {
        return $this->belongsTo(KnowledgeRevision::class, 'active_revision_id');
    }

    /** @return HasOne<KnowledgeRevision, $this> */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(KnowledgeRevision::class)->latestOfMany();
    }
}

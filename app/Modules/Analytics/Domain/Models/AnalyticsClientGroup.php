<?php

namespace App\Modules\Analytics\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, mixed> $rule_definition
 * @property CarbonImmutable|null $archived_at
 * @property int $clients_count
 */
#[Fillable(['organization_id', 'name', 'description', 'group_type', 'is_active', 'sort_order', 'version', 'rule_definition', 'created_by_user_id', 'updated_by_user_id', 'archived_at'])]
class AnalyticsClientGroup extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<Client, $this> */
    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(
            Client::class,
            'analytics_client_group_members',
            'analytics_client_group_id',
            'client_id',
        )->withPivot(['organization_id', 'added_by_user_id'])->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /** @return HasMany<AnalyticsClientGroupVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AnalyticsClientGroupVersion::class, 'analytics_client_group_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'version' => 'integer',
            'rule_definition' => 'array',
            'archived_at' => 'immutable_datetime',
        ];
    }
}

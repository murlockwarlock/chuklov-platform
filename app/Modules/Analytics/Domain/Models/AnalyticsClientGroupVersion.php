<?php

namespace App\Modules\Analytics\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'analytics_client_group_id',
    'version',
    'name',
    'description',
    'group_type',
    'is_active',
    'sort_order',
    'rule_definition',
    'client_ids',
    'created_by_user_id',
])]
class AnalyticsClientGroupVersion extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AnalyticsClientGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AnalyticsClientGroup::class, 'analytics_client_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'rule_definition' => 'array',
            'client_ids' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}

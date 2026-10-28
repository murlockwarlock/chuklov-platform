<?php

namespace App\Modules\B2B\Domain\Models;

use App\Models\User;
use App\Modules\B2B\Domain\Enums\B2bFormConfigurationStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'version', 'status', 'fields', 'published_at', 'created_by_user_id'])]
class B2bFormConfiguration extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => B2bFormConfigurationStatus::class,
            'fields' => 'array',
            'published_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}

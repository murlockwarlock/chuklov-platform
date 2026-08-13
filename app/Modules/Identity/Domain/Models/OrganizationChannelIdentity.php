<?php

namespace App\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\OrganizationChannelIdentityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ChannelIdentityStatus $verification_status
 */
#[Fillable(['channel', 'external_id', 'verification_status', 'verification_method', 'verified_at'])]
class OrganizationChannelIdentity extends Model
{
    /** @use HasFactory<OrganizationChannelIdentityFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): OrganizationChannelIdentityFactory
    {
        return OrganizationChannelIdentityFactory::new();
    }

    protected function casts(): array
    {
        return [
            'verification_status' => ChannelIdentityStatus::class,
            'verified_at' => 'datetime',
        ];
    }
}

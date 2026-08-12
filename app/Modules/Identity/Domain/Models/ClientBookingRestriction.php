<?php

namespace App\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ClientBookingRestrictionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Organization $organization
 * @property-read Client $client
 * @property-read User $blockedBy
 * @property-read User|null $unblockedBy
 */
#[Fillable(['reason'])]
class ClientBookingRestriction extends Model
{
    /** @use HasFactory<ClientBookingRestrictionFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<User, $this> */
    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function unblockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unblocked_by_user_id');
    }

    protected static function newFactory(): ClientBookingRestrictionFactory
    {
        return ClientBookingRestrictionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'blocked_at' => 'datetime',
            'unblocked_at' => 'datetime',
        ];
    }
}

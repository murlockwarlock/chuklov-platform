<?php

namespace App\Modules\Security\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Organization $organization
 * @property array<string, mixed> $metadata
 * @property Carbon $occurred_at
 */
#[Fillable(['action', 'target_type', 'target_id', 'metadata', 'occurred_at'])]
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function newFactory(): AuditEventFactory
    {
        return AuditEventFactory::new();
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}

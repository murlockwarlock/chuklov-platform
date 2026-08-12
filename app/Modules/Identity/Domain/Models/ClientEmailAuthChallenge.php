<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ClientEmailAuthChallengeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Organization $organization
 * @property string $email
 * @property string $code_hash
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
#[Fillable(['email'])]
class ClientEmailAuthChallenge extends Model
{
    /** @use HasFactory<ClientEmailAuthChallengeFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function newFactory(): ClientEmailAuthChallengeFactory
    {
        return ClientEmailAuthChallengeFactory::new();
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}

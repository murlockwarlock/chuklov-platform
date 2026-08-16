<?php

namespace App\Modules\Security\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use Database\Factories\OrganizationCredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Organization $organization
 * @property array<string, mixed> $credentials
 * @property CredentialStatus $status
 * @property string|null $revision_id
 * @property Carbon|null $last_rotated_at
 */
#[Fillable(['provider', 'credential_name', 'revision_id'])]
#[Hidden(['credentials'])]
class OrganizationCredential extends Model
{
    /** @use HasFactory<OrganizationCredentialFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function rotatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rotated_by_user_id');
    }

    /** @return array<string, mixed> */
    public function masked(): array
    {
        return [
            'id' => $this->getKey(),
            'provider' => $this->provider,
            'credential_name' => $this->credential_name,
            'status' => $this->status->value,
            'last_rotated_at' => $this->last_rotated_at?->toISOString(),
            'has_credentials' => filled($this->credentials),
        ];
    }

    protected static function newFactory(): OrganizationCredentialFactory
    {
        return OrganizationCredentialFactory::new();
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'status' => CredentialStatus::class,
            'last_rotated_at' => 'datetime',
        ];
    }
}

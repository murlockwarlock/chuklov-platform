<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $certificate_id
 * @property int $initiated_by_client_id
 * @property int|null $claimed_client_id
 * @property string $token_hash
 * @property string $status
 * @property Carbon|null $claimed_at
 * @property Carbon|null $revoked_at
 * @property Client|null $initiatedBy
 * @property Client|null $claimedClient
 */
#[Fillable([])]
class GiftCertificateClaim extends Model
{
    protected $table = 'gift_certificate_claims';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<GiftCertificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(GiftCertificate::class, 'certificate_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'initiated_by_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function claimedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'claimed_client_id');
    }

    /**
     * @param  Builder<GiftCertificateClaim>  $query
     * @return Builder<GiftCertificateClaim>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

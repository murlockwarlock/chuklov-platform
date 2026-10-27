<?php

namespace App\Modules\Identity\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $birth_date
 */
class PatientProfile extends Model
{
    protected $hidden = ['creation_key', 'creation_hash'];

    /** @return BelongsTo<Client, $this> */
    public function clinicalClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'clinical_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'owner_client_id');
    }

    /** @return HasMany<PatientAccessGrant, $this> */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(PatientAccessGrant::class, 'patient_profile_id');
    }

    public function isAdult(): bool
    {
        return $this->birth_date !== null && $this->birth_date->lessThanOrEqualTo(CarbonImmutable::today('UTC')->subYears(18));
    }

    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date', 'owner_revoked_at' => 'immutable_datetime'];
    }
}

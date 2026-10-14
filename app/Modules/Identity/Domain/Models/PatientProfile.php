<?php

namespace App\Modules\Identity\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function isAdult(): bool
    {
        return $this->birth_date !== null && $this->birth_date->lessThanOrEqualTo(CarbonImmutable::today('UTC')->subYears(18));
    }

    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date', 'owner_revoked_at' => 'immutable_datetime'];
    }
}

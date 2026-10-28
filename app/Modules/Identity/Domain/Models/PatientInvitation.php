<?php

namespace App\Modules\Identity\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 */
class PatientInvitation extends Model
{
    protected $hidden = ['encrypted_token', 'token_hash'];

    protected function casts(): array
    {
        return ['encrypted_token' => 'encrypted', 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}

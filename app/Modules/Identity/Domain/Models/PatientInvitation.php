<?php

namespace App\Modules\Identity\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class PatientInvitation extends Model
{
    protected $hidden = ['encrypted_token', 'token_hash'];

    protected function casts(): array
    {
        return ['encrypted_token' => 'encrypted', 'expires_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}

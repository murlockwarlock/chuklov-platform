<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'client_id',
    'marker_key',
    'marker_text',
    'encryption_key_version',
    'is_active',
    'source_session_id',
    'activated_at',
    'activated_by',
    'cleared_at',
    'cleared_by',
    'clear_reason',
    'clear_reason_key_version',
])]
class MedicalClientSafetyMarker extends Model
{
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

    /** @return BelongsTo<MedicalSession, $this> */
    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(MedicalSession::class, 'source_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    protected function casts(): array
    {
        return [
            'encryption_key_version' => 'integer',
            'is_active' => 'boolean',
            'source_session_id' => 'integer',
            'activated_by' => 'integer',
            'cleared_by' => 'integer',
            'clear_reason_key_version' => 'integer',
            'activated_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }
}

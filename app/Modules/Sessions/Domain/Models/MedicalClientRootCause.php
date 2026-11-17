<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['organization_id', 'client_id', 'medical_cause_definition_id', 'identity_key', 'status', 'region', 'region_key_version', 'free_text', 'free_text_key_version', 'relations', 'relations_key_version', 'source_session_id', 'first_seen_at', 'last_seen_at', 'resolved_at', 'sort_order'])]
class MedicalClientRootCause extends Model
{
    public const STATUS_NEW = 'новая';

    public const STATUS_IN_PROGRESS = 'в работе';

    public const STATUS_RESOLVED = 'устранена';

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

    /** @return BelongsTo<MedicalCauseDefinition, $this> */
    public function cause(): BelongsTo
    {
        return $this->belongsTo(MedicalCauseDefinition::class, 'medical_cause_definition_id');
    }

    /** @return BelongsTo<MedicalSession, $this> */
    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(MedicalSession::class, 'source_session_id');
    }

    /** @return HasMany<MedicalClientRootCauseHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(MedicalClientRootCauseHistory::class, 'medical_client_root_cause_id');
    }

    protected function casts(): array
    {
        return [
            'region_key_version' => 'integer',
            'free_text_key_version' => 'integer',
            'relations_key_version' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'sort_order' => 'integer',
        ];
    }
}

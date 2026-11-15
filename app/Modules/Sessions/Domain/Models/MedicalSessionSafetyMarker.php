<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'client_id', 'medical_session_id', 'marker_key', 'marker_text', 'encryption_key_version'])]
class MedicalSessionSafetyMarker extends Model
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
    public function session(): BelongsTo
    {
        return $this->belongsTo(MedicalSession::class, 'medical_session_id');
    }

    protected function casts(): array
    {
        return ['encryption_key_version' => 'integer'];
    }
}

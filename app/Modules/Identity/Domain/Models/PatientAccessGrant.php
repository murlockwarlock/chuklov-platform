<?php

namespace App\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\PatientAccessGrantScope;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PatientAccessGrantScope $scope
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Fillable(['scope', 'request_key', 'legal_document_version', 'granted_at', 'revoked_at'])]
class PatientAccessGrant extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<PatientProfile, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'patient_profile_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'grantor_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function grantee(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'grantee_client_id');
    }

    /** @return BelongsTo<LegalDocument, $this> */
    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    protected function casts(): array
    {
        return [
            'scope' => PatientAccessGrantScope::class,
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}

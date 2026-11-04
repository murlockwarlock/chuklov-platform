<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int|null $patient_profile_id
 * @property int $specialist_id
 * @property int|null $booking_id
 * @property string|null $pain
 * @property string|null $pain_vas
 * @property string|null $tests
 * @property string|null $observations
 * @property string|null $subjective_feelings
 * @property string|null $visual_posture_diagnostics
 * @property string|null $root_cause_hypothesis
 * @property string|null $protocol
 * @property string|null $result
 * @property bool $causes_manual_override
 * @property int $encryption_key_version
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 * @property-read Client $client
 * @property-read Specialist $specialist
 * @property-read Booking|null $booking
 * @property-read Collection<int, MedicalSessionAttachment> $attachmentLinks
 * @property-read Collection<int, MedicalSessionTestResult> $testResults
 * @property-read Collection<int, MedicalSessionRootCause> $rootCauses
 * @property-read Collection<int, MedicalCauseDefinition> $causes
 */
#[Fillable(['pain', 'pain_vas', 'tests', 'observations', 'subjective_feelings', 'visual_posture_diagnostics', 'root_cause_hypothesis', 'protocol', 'result', 'causes_manual_override', 'encryption_key_version'])]
class MedicalSession extends Model
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

    /** @return BelongsTo<Specialist, $this> */
    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return HasMany<MedicalSessionAttachment, $this> */
    public function attachmentLinks(): HasMany
    {
        return $this->hasMany(MedicalSessionAttachment::class, 'medical_session_id');
    }

    /** @return HasMany<MedicalSessionPain, $this> */
    public function pains(): HasMany
    {
        return $this->hasMany(MedicalSessionPain::class, 'medical_session_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return HasMany<MedicalSessionTestResult, $this> */
    public function testResults(): HasMany
    {
        return $this->hasMany(MedicalSessionTestResult::class, 'medical_session_id');
    }

    /** @return HasMany<MedicalSessionRootCause, $this> */
    public function rootCauses(): HasMany
    {
        return $this->hasMany(MedicalSessionRootCause::class, 'medical_session_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return BelongsToMany<MedicalCauseDefinition, $this> */
    public function causes(): BelongsToMany
    {
        return $this->belongsToMany(MedicalCauseDefinition::class, 'medical_session_causes', 'medical_session_id', 'medical_cause_definition_id')
            ->wherePivot('organization_id', $this->organization_id)
            ->withPivot(['organization_id', 'client_id']);
    }

    protected function casts(): array
    {
        return [
            'encryption_key_version' => 'integer',
            'causes_manual_override' => 'boolean',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

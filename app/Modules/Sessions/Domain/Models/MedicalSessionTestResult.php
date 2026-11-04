<?php

namespace App\Modules\Sessions\Domain\Models;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['organization_id', 'client_id', 'medical_session_id', 'medical_test_definition_id', 'before_value_id', 'after_value_id'])]
class MedicalSessionTestResult extends Model
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

    /** @return BelongsTo<MedicalTestDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MedicalTestDefinition::class, 'medical_test_definition_id');
    }

    /** @return BelongsTo<MedicalTestValue, $this> */
    public function beforeValue(): BelongsTo
    {
        return $this->belongsTo(MedicalTestValue::class, 'before_value_id');
    }

    /** @return BelongsTo<MedicalTestValue, $this> */
    public function afterValue(): BelongsTo
    {
        return $this->belongsTo(MedicalTestValue::class, 'after_value_id');
    }

    /** @return BelongsToMany<MedicalCauseDefinition, $this> */
    public function causes(): BelongsToMany
    {
        return $this->belongsToMany(MedicalCauseDefinition::class, 'medical_session_test_causes', 'medical_session_test_result_id', 'medical_cause_definition_id');
    }
}

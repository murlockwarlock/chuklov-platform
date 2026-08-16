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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $client_id
 * @property int $specialist_id
 * @property int|null $booking_id
 * @property string|null $pain
 * @property string|null $tests
 * @property string|null $observations
 * @property string|null $root_cause_hypothesis
 * @property string|null $protocol
 * @property string|null $result
 * @property int $encryption_key_version
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Organization $organization
 * @property-read Client $client
 * @property-read Specialist $specialist
 * @property-read Booking|null $booking
 * @property-read Collection<int, MedicalSessionAttachment> $attachmentLinks
 */
#[Fillable(['pain', 'tests', 'observations', 'root_cause_hypothesis', 'protocol', 'result', 'encryption_key_version'])]
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

    protected function casts(): array
    {
        return [
            'encryption_key_version' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

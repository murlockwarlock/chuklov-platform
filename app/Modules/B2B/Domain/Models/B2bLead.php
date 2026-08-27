<?php

namespace App\Modules\B2B\Domain\Models;

use App\Modules\B2B\Domain\Enums\B2bLeadSource;
use App\Modules\B2B\Domain\Enums\B2bLeadStatus;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Database\Factories\B2bLeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $organization_id
 * @property int $client_id
 * @property B2bSpecialistAnswer $b2b_specialist_answer
 * @property B2bLeadSource $source_channel
 * @property string $idempotency_key
 * @property string $request_hash
 * @property B2bLeadStatus $status
 * @property int $event_version
 * @property-read Organization $organization
 * @property-read Client $client
 * @property-read B2bSalesCall $salesCall
 */
#[Fillable([])]
class B2bLead extends Model
{
    /** @use HasFactory<B2bLeadFactory> */
    use HasFactory;

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

    /** @return HasOne<B2bSalesCall, $this> */
    public function salesCall(): HasOne
    {
        return $this->hasOne(B2bSalesCall::class, 'lead_id');
    }

    public function submittedAtUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->getAttribute('submitted_at'))->utc();
    }

    protected function casts(): array
    {
        return [
            'b2b_specialist_answer' => B2bSpecialistAnswer::class,
            'source_channel' => B2bLeadSource::class,
            'status' => B2bLeadStatus::class,
            'submitted_at' => 'datetime',
            'event_version' => 'integer',
        ];
    }

    protected static function newFactory(): B2bLeadFactory
    {
        return B2bLeadFactory::new();
    }
}

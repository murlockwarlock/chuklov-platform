<?php

namespace App\Modules\Attribution\Domain\Models;

use App\Modules\Attribution\Domain\ValueObjects\AttributionData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property string|null $encrypted_source_detail
 * @property int|null $source_detail_key_version
 */
#[Fillable([])]
class PreAuthAttribution extends Model
{
    protected $hidden = ['encrypted_source_detail', 'source_detail_key_version'];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function consumedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'consumed_client_id');
    }

    public function attributionData(): AttributionData
    {
        return new AttributionData(
            sourceType: (string) $this->source_type,
            source: $this->source,
            referralCode: $this->referral_code,
            utmSource: $this->utm_source,
            utmMedium: $this->utm_medium,
            utmCampaign: $this->utm_campaign,
            utmContent: $this->utm_content,
            utmTerm: $this->utm_term,
        );
    }

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

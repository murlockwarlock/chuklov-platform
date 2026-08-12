<?php

namespace App\Modules\ClientPortal\Domain\Models;

use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ClientOnboardingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Organization $organization
 * @property-read Client $client
 * @property ClientOnboardingStage $current_stage
 * @property array<string, mixed> $data
 */
#[Fillable(['flow_version', 'data'])]
class ClientOnboarding extends Model
{
    /** @use HasFactory<ClientOnboardingFactory> */
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

    protected static function newFactory(): ClientOnboardingFactory
    {
        return ClientOnboardingFactory::new();
    }

    protected function casts(): array
    {
        return [
            'current_stage' => ClientOnboardingStage::class,
            'data' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Modules\Identity\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\ClientConsentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property-read Client $client
 * @property-read Organization $organization
 * @property ConsentSubject $subject
 * @property bool $is_required
 * @property bool $granted
 * @property Carbon $recorded_at
 */
#[Fillable(['subject', 'version', 'evidence'])]
class ClientConsent extends Model
{
    /** @use HasFactory<ClientConsentFactory> */
    use HasFactory;

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    protected static function newFactory(): ClientConsentFactory
    {
        return ClientConsentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'subject' => ConsentSubject::class,
            'is_required' => 'boolean',
            'granted' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }
}

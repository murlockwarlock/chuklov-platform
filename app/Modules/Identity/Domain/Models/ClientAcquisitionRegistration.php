<?php

namespace App\Modules\Identity\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $client_id
 * @property string|null $session_hash
 * @property int|null $telegram_authentication_request_id
 * @property Carbon|null $finalized_at
 */
#[Fillable([])]
class ClientAcquisitionRegistration extends Model
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

    /** @return BelongsTo<ClientTelegramAuthenticationRequest, $this> */
    public function telegramAuthenticationRequest(): BelongsTo
    {
        return $this->belongsTo(ClientTelegramAuthenticationRequest::class, 'telegram_authentication_request_id');
    }

    protected function casts(): array
    {
        return [
            'finalized_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

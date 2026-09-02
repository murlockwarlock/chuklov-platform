<?php

namespace App\Modules\Broadcasts\Domain\Models;

use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BroadcastClientProfile extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'b2b_specialist_answer' => B2bSpecialistAnswer::class,
        ];
    }
}

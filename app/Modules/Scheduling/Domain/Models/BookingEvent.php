<?php

namespace App\Modules\Scheduling\Domain\Models;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingEventType;
use Database\Factories\BookingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Organization $organization
 * @property-read Booking $booking
 * @property-read User|null $actorUser
 * @property-read Client|null $actorClient
 * @property BookingEventType $event_type
 * @property array<string, mixed> $old_values
 * @property array<string, mixed> $new_values
 */
#[Fillable(['event_type', 'actor_type', 'old_values', 'new_values', 'reason', 'occurred_at'])]
class BookingEvent extends Model
{
    public const UPDATED_AT = null;

    /** @use HasFactory<BookingEventFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function actorClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'actor_client_id');
    }

    protected static function newFactory(): BookingEventFactory
    {
        return BookingEventFactory::new();
    }

    protected function casts(): array
    {
        return [
            'event_type' => BookingEventType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}

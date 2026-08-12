<?php

namespace App\Modules\Specialists\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use Database\Factories\SpecialistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Organization $organization
 * @property-read User|null $staffUser
 */
#[Fillable(['display_name', 'timezone'])]
class Specialist extends Model
{
    /** @use HasFactory<SpecialistFactory> */
    use HasFactory;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    protected static function newFactory(): SpecialistFactory
    {
        return SpecialistFactory::new();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
